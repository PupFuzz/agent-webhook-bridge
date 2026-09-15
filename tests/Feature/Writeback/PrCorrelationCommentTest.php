<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\GitHubPrCardMoveClassifier;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Models\AgentDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitHubIssueCommentsStub;
use Tests\Support\KanbanCardStub;
use Tests\Support\PreloadStub;
use Tests\TestCase;

/**
 * DL-390 / card#9569: a merge or close whose writeback cannot correlate a card says so ON THE
 * PULL REQUEST, once.
 *
 * END TO END THROUGH THE DISPATCHER, deliberately: the real classifier builds the targets, the real
 * HandlerRegistry resolves them, and only the two HTTP peers are stubbed. The comment is decided in
 * two places (classify time for a token that resolves nothing, handler time for a refused move), so a
 * test that drove either half alone could pass while the seam between them carried nothing.
 *
 * ⛔ NOTHING HERE MAY REACH A REAL HOST: `Tests\TestCase` calls {@see Http::preventStrayRequests()},
 * which turns an unstubbed request into a thrown exception rather than a network call.
 */
class PrCorrelationCommentTest extends TestCase
{
    use RefreshDatabase;

    private const REPO = 'acme/widget';

    /** A merge title carrying the DL-305 closure evidence a resolved DL-390 would need. */
    private const CLOSES_DL_390 = 'feat: a thing (closes DL-390)';

    private string|false $origGhToken;

    private string $dir;

    private GitHubIssueCommentsStub $github;

    /** @var array<int, array<string, mixed>> the cards a board-scoped lookup finds on board 8 */
    private array $onBoard = [];

    /** @var array<string, list<int>> DL → card ids the by-ref lookup answers */
    private array $dlCards = [];

    private ?KanbanCardStub $cards = null;

    /** Whether the mapped board itself reads back — the guard's control between a foreign id and an install fault. */
    private bool $boardReadsBack = true;

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/prcorr-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => [self::REPO => ['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ]]],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        File::put($this->dir.'/github/token', 'gh-test-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        chmod($this->dir.'/github/token', 0o600);
        File::put($this->dir.'/writeback-agent.yml', "subscriptions:\n  - provider: github\n    scopes: [\"".self::REPO."\"]\n"
            ."classifier:\n  class: '".GitHubPrCardMoveClassifier::class."'\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.providers.github.credential_helper' => '',
        ]);
        $this->github = new GitHubIssueCommentsStub;
        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN');
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        putenv($this->origGhToken === false ? 'GH_TOKEN' : 'GH_TOKEN='.$this->origGhToken);
        parent::tearDown();
    }

    // --- one test per correlation-failure cause: exactly one comment, naming it -------------------

    public function test_merged_with_a_dl_no_card_carries_posts_one_comment_naming_the_cause(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=dl_unresolved', $body);
        $this->assertStringContainsString('`DL-390`', $body);
        $this->assertStringContainsString('head branch', $body);
        $this->assertStringContainsString('board 8', $body);
        $this->assertStringContainsString('kbcard patch --task <card-id> --dl DL-390 --pr 702', $body);
    }

    public function test_merged_with_an_unreadable_card_token_posts_one_comment_naming_the_cause(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card_77-thing', title: 'feat: a thing', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=token_unreadable', $body);
        $this->assertStringContainsString('a card-shaped token from the head branch that does not parse (it appears to name card 77)', $body);
        $this->assertStringNotContainsString('`card#77`', $body);   // an unparsed token is never re-spelled as one that parses
    }

    public function test_merged_naming_a_card_that_is_not_on_the_mapped_board_posts_one_comment_naming_the_cause(): void
    {
        // The realistic "wrong board" shape since card#8375: the board-scoped lookup finds no card
        // 123 on board 8 while board 8 itself reads back — card 123 does not exist there, or lives on
        // a board this bridge never reads.
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-123-thing', title: 'feat: a thing', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=card_id_outside_mapped_board', $body);
        $this->assertStringContainsString('card#123', $body);
        $this->assertStringContainsString('board 8', $body);
    }

    public function test_merged_onto_a_card_read_back_off_another_board_posts_one_comment_naming_the_cause(): void
    {
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, board: 9)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-5-thing', title: 'feat: a thing', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=card_not_on_mapped_board', $body);
        $this->assertStringNotContainsString('board 9', $body);   // the foreign board is never disclosed
    }

    public function test_merged_with_a_near_miss_card_token_beside_a_resolving_dl_posts_one_comment_naming_the_cause(): void
    {
        $this->dlCards = ['42' => [5]];
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/DL-42-card_77-thing', title: 'feat: a thing (closes DL-42)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=card_token_near_miss', $body);
        $this->assertStringContainsString('`DL-42`', $body);
        $this->assertStringContainsString('(it appears to name card 77)', $body);
    }

    public function test_merged_with_an_uncorroborated_title_token_on_a_card_tracking_another_pr_posts_one_comment(): void
    {
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, pr: 900)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: a thing (closes card#5)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=card_token_uncorroborated', $body);
        $this->assertStringContainsString('`card#5`', $body);
        $this->assertStringContainsString('title', $body);
        $this->assertStringNotContainsString('900', $body);   // the card's own PR is not disclosed
    }

    public function test_merged_onto_a_card_that_tracks_another_pr_posts_one_comment_naming_the_unstamped_ref(): void
    {
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, pr: 739)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-5-thing', title: 'feat: a thing', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=correlation_ref_not_stamped', $body);
        $this->assertStringContainsString('`pr_number`', $body);
        $this->assertStringNotContainsString('739', $body);
        $this->assertSame([['workflow_stage_id' => 52]], array_slice($this->cards->patchesTo(5), 0, 1));   // the move itself still landed
    }

    public function test_closed_unmerged_superseded_pr_declining_a_card_that_tracks_the_replacement_posts_one_comment(): void
    {
        // The card's measured incidents (card#9422/#719, card#9486/#739): a superseded PR is closed,
        // its branch still names the card, and the close's decline move lands on a card whose live
        // pull request is the replacement. Decline semantics are NOT changed — the move still lands;
        // what is new is that the PR now says so, and how to put the card back.
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, pr: 739)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(719, head: 'feat/card-5-thing', title: 'feat: a thing', merged: false));

        $body = $this->onlyComment(719);
        $this->assertStringContainsString('outcome=closed_unmerged', $body);
        $this->assertStringContainsString('cause=correlation_ref_not_stamped', $body);
        $this->assertStringContainsString('supersedes', $body);
        $this->assertStringContainsString('kbcard move --task 5', $body);
        $this->assertSame([['workflow_stage_id' => 49]], array_slice($this->cards->patchesTo(5), 0, 1));   // decline unchanged
    }

    // --- success, redelivery, POST failure, scope ---------------------------------------------------

    public function test_a_merge_that_correlates_and_moves_posts_nothing_and_reads_nothing_from_github(): void
    {
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([5 => $this->card(5)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-5-thing', title: 'feat: a thing', merged: true));

        $this->assertSame(52, $this->cards->patchesTo(5)[0]['workflow_stage_id'] ?? null);   // control: it moved
        $this->assertSame([], $this->github->requests);
    }

    public function test_a_redelivered_or_reclosed_failure_does_not_post_a_second_comment(): void
    {
        $this->fakePeers();
        $pr = $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true);

        $this->dispatch('d1', $pr);
        $this->dispatch('d2', $pr);   // a new delivery id: replay, or a reopen + close of the same PR

        $this->assertCount(1, $this->github->posts(702));
        $this->assertCount(2, array_filter($this->github->requests, fn (array $r) => $r['method'] === 'GET'));   // both deliveries looked
    }

    public function test_a_refused_comment_post_is_logged_and_leaves_the_writeback_outcome_unchanged(): void
    {
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, pr: 739)]);
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();
        Log::spy();

        $this->dispatch('d1', $this->closedPr(719, head: 'feat/card-5-thing', title: 'feat: a thing', merged: false));

        $this->assertSame([['workflow_stage_id' => 49]], array_slice($this->cards->patchesTo(5), 0, 1));
        $this->assertCount(1, array_filter($this->cards->log, fn (array $e) => $e['method'] === 'PATCH' && isset($e['data']['workflow_stage_id'])));   // never retried
        $dispatch = AgentDispatch::query()->sole();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
        $this->assertCount(1, $this->github->posts(719));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'pr_correlation_comment: NOT posted')
            && ($context['reason'] ?? null) === 'post_refused'
            && ($context['status'] ?? null) === 403)->once();
    }

    public function test_a_transport_failure_on_the_comment_post_is_logged_and_leaves_the_writeback_outcome_unchanged(): void
    {
        $this->github = new GitHubIssueCommentsStub(postFailsInTransport: true);
        $this->fakePeers();
        Log::spy();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        $dispatch = AgentDispatch::query()->sole();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'pr_correlation_comment: NOT posted')
            && ($context['reason'] ?? null) === 'post_failed')->once();
    }

    public function test_a_later_failure_for_the_same_outcome_with_a_different_cause_posts_no_second_comment(): void
    {
        // The dedupe key is (pull request, outcome), not the cause: a reopen + close whose branch
        // now names a card off the board is still the one `merged` report this PR gets.
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));
        $this->dispatch('d2', $this->closedPr(702, head: 'feat/card-123-thing', title: 'feat: a thing', merged: true));

        $this->assertStringContainsString('cause=dl_unresolved', $this->onlyComment(702));
    }

    public function test_a_different_outcome_on_the_same_pr_gets_its_own_comment(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: 'feat: a thing', merged: false));
        $this->dispatch('d2', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        $posts = $this->github->posts(702);
        $this->assertCount(2, $posts);
        $this->assertStringStartsWith('<!-- agent-webhook-bridge:pr-correlation outcome=closed_unmerged -->', $posts[0]);
        $this->assertStringStartsWith('<!-- agent-webhook-bridge:pr-correlation outcome=merged -->', $posts[1]);
    }

    public function test_a_comment_that_only_quotes_the_marker_does_not_suppress_the_report(): void
    {
        $this->fakePeers();
        $this->github->seed(702, 'why did the bridge post <!-- agent-webhook-bridge:pr-correlation outcome=merged --> here?');

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        $this->assertStringContainsString('cause=dl_unresolved', $this->onlyComment(702));
    }

    public function test_a_refused_dedupe_read_posts_nothing_and_is_logged(): void
    {
        $this->github = new GitHubIssueCommentsStub(listStatus: 403);
        $this->fakePeers();
        Log::spy();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        $this->assertSame([], $this->github->posts(702));
        $this->assertNull(AgentDispatch::query()->sole()->error_message);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'pr_correlation_comment: NOT posted')
            && ($context['reason'] ?? null) === 'dedupe_read_refused'
            && ($context['status'] ?? null) === 403)->once();
    }

    public function test_a_merge_withheld_for_want_of_closure_evidence_posts_nothing(): void
    {
        // Correlation succeeded; DL-305 withheld the move because nothing claims the work is done.
        // A title citing another card is routine, so this is not a correlation failure.
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: a thing, follows card#5', merged: true));

        $this->assertSame([], $this->github->requests);
    }

    public function test_an_install_fault_refusal_of_the_card_id_posts_nothing(): void
    {
        // The mapped board does not read back to the writeback token, so the guard cannot tell a
        // foreign id from its own lost access: that is the operator's problem, not the PR author's.
        $this->boardReadsBack = false;
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-123-thing', title: 'feat: a thing', merged: true));

        $this->assertSame([], $this->github->requests);
    }

    public function test_an_opened_pr_that_fails_correlation_posts_nothing(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->openedPr(702, head: 'feat/card-123-thing', title: 'feat: a thing'));

        $this->assertSame([], $this->github->requests);
    }

    public function test_a_merged_pr_carrying_no_token_at_all_posts_nothing(): void
    {
        // A release PR, a sync PR, a docs fix: no token is not a correlation that failed.
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'release/v1.2.3', title: 'release: v1.2.3', merged: true));

        $this->assertSame([], $this->github->requests);
    }

    public function test_author_controlled_title_and_branch_bytes_never_reach_the_comment(): void
    {
        $this->fakePeers();
        $title = "feat: @octocat ![x](https://evil.example/x.png) <!-- cause=none --> `tick` \u{202E}gnp.exe [link](https://evil.example)";

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-@octocat-![x]', title: $title.' (closes DL-390)', merged: true));

        $body = $this->onlyComment(702);
        foreach (['@', '![', 'evil.example', '<!-- cause=none', "\u{202E}", 'octocat', '[link]'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "author-controlled bytes reached the comment: {$needle}");
        }
    }

    // --- closure evidence: a merge that claims to finish nothing is not a correlation failure -------

    public function test_a_merge_whose_title_only_mentions_a_dl_no_card_carries_posts_nothing(): void
    {
        // The review repro: had DL-305 resolved, the DL-305 gate would still have withheld the
        // move, so a "Board not updated" comment would send its reader to mark a card finished.
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'docs: explain the DL-305 rule', merged: true));

        $this->assertSame([], $this->github->requests);
    }

    public function test_a_merge_whose_title_closes_a_dl_no_card_carries_posts_one_comment(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'docs: explain the rule (closes DL-305)', merged: true));

        $this->assertStringContainsString('cause=dl_unresolved', $this->onlyComment(702));
    }

    public function test_a_merge_naming_an_unresolved_dl_only_in_its_head_branch_posts_nothing(): void
    {
        // The structural route of the gate is a CARD token in the head ref: a resolved DL-390
        // merged from this branch with this title would move nothing either.
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: 'feat: a thing', merged: true));

        $this->assertSame([], $this->github->requests);
    }

    public function test_a_merge_whose_title_only_mentions_an_unreadable_card_token_posts_nothing(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'docs: notes on card_77', merged: true));

        $this->assertSame([], $this->github->requests);
    }

    public function test_a_merge_whose_title_closes_an_unreadable_card_token_posts_one_comment(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: a thing (closes card_77)', merged: true));

        $this->assertStringContainsString('cause=token_unreadable', $this->onlyComment(702));
    }

    public function test_a_release_merge_from_a_branch_naming_an_unreadable_card_token_posts_nothing(): void
    {
        // The structural route is an integration merge's; a release merge needs a closing form.
        $this->fakePeers();

        $release = $this->closedPr(702, head: 'feat/card_77-thing', title: 'feat: a thing', merged: true);
        $release['pull_request']['base']['ref'] = 'main';

        $this->dispatch('d1', $release);

        $this->assertSame([], $this->github->requests);
    }

    public function test_a_near_miss_refusal_on_a_merge_that_claims_no_closure_posts_nothing(): void
    {
        // The DL-287 refusal target is built before the closure gate, so it carried the
        // evidence whether or not the merge claimed to finish anything.
        $this->dlCards = ['42' => [5]];
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'docs: DL-42 notes (card_77)', merged: true));

        $this->assertSame([], $this->github->requests);
    }

    // --- what an unstamped ref comment may claim ----------------------------------------------------

    public function test_an_unstamped_pr_number_beside_a_stamped_pr_url_names_only_the_dropped_key(): void
    {
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, pr: 739)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-5-thing', title: 'feat: a thing', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('the `pr_number` this pull request carries was not recorded on card#5', $body);
        $this->assertStringNotContainsString('`pr_url`', $body);
        $this->assertStringNotContainsString('this pull request was not recorded', $body);
        $this->assertStringContainsString('pr_url', (string) json_encode($this->cards->patchesTo(5)));   // control: the url WAS stamped
    }

    public function test_a_close_that_drops_only_a_dl_number_does_not_say_the_card_tracks_another_pull_request(): void
    {
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $card = $this->card(5);
        $card['payload'] = ['dl_number' => 'DL-0007'];
        $this->cards = new KanbanCardStub([5 => $card]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(719, head: 'feat/card-5-DL-42-thing', title: 'feat: a thing', merged: false));

        $body = $this->onlyComment(719);
        $this->assertStringContainsString('the `dl_number` this pull request carries was not recorded on card#5', $body);
        $this->assertStringNotContainsString('different pull request', $body);
        $this->assertStringContainsString('kbcard patch --task 5 --dl DL-42', $body);
    }

    public function test_a_pinned_card_whose_ref_is_not_stamped_posts_one_comment_and_is_not_moved(): void
    {
        // The pin holds the STAGE and still stamps (KanbanMoveCardHandler), so a dropped ref on
        // a pinned card is reported like any other (DL-390 Decision 7).
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $card = $this->card(5, pr: 739);
        $card['block_reason'] = 'held by operator';
        $this->cards = new KanbanCardStub([5 => $card]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-5-thing', title: 'feat: a thing', merged: true));

        $this->assertStringContainsString('cause=correlation_ref_not_stamped', $this->onlyComment(702));
        $this->assertSame([], array_filter($this->cards->patchesTo(5), fn (array $p) => isset($p['workflow_stage_id'])));
    }

    // --- the dedupe read and the per-request memo --------------------------------------------------

    public function test_a_dedupe_read_that_cannot_complete_posts_nothing_and_is_logged(): void
    {
        $this->github->seedUnreadable(702);
        $this->fakePeers();
        Log::spy();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        $this->assertSame([], $this->github->posts(702));
        $this->assertNull(AgentDispatch::query()->sole()->error_message);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'pr_correlation_comment: NOT posted')
            && ($context['reason'] ?? null) === 'dedupe_read_incomplete')->once();
    }

    public function test_a_refused_post_is_attempted_once_per_request_across_a_bundled_dls_cards(): void
    {
        $this->bundledDlOnCardsTrackingAnotherPr();
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'feat: a thing (closes DL-42)', merged: true));

        $this->assertCount(2, array_filter($this->cards->log, fn (array $e) => $e['method'] === 'PATCH' && isset($e['data']['workflow_stage_id'])));   // control: both cards reported
        $this->assertCount(1, $this->github->posts(702));
    }

    public function test_a_refused_dedupe_read_is_attempted_once_per_request_across_a_bundled_dls_cards(): void
    {
        $this->bundledDlOnCardsTrackingAnotherPr();
        $this->github = new GitHubIssueCommentsStub(listStatus: 403);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'feat: a thing (closes DL-42)', merged: true));

        $this->assertCount(1, array_filter($this->github->requests, fn (array $r) => $r['method'] === 'GET'));
    }

    // --- the posting identity ----------------------------------------------------------------------

    public function test_without_the_receivers_token_file_a_gh_token_in_the_environment_is_not_used(): void
    {
        // `bridge:replay` runs these handlers from a shell, where GH_TOKEN and the credential store
        // are live; the comment must post under the receiver's identity or not at all.
        File::delete($this->dir.'/github/token');
        putenv('GH_TOKEN=gh-env-token-for-this-test');
        $this->fakePeers();
        Log::spy();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        $this->assertSame([], $this->github->requests);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'pr_correlation_comment: NOT posted')
            && ($context['reason'] ?? null) === 'token_unresolved')->once();
    }

    // --- fixtures -----------------------------------------------------------------------------------

    private function bundledDlOnCardsTrackingAnotherPr(): void
    {
        $this->dlCards = ['42' => [5, 6]];
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8], 6 => ['id' => 6, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, pr: 739), 6 => $this->card(6, pr: 739)]);
    }

    private function onlyComment(int $number): string
    {
        $posts = $this->github->posts($number);
        $this->assertCount(1, $posts, 'expected exactly one correlation comment on PR #'.$number);

        return $posts[0];
    }

    /** @return array<string, mixed> */
    private function card(int $id, int $board = 8, int $stage = 50, ?int $pr = null): array
    {
        return [
            'id' => $id, 'board_id' => $board, 'workflow_stage_id' => $stage, 'block_reason' => null, 'tags' => [],
            'payload' => $pr === null ? [] : ['pr_number' => $pr],
            'comments' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function closedPr(int $number, string $head, string $title, bool $merged): array
    {
        return $this->pr('closed', $number, $head, $title, ['merged' => $merged]);
    }

    /** @return array<string, mixed> */
    private function openedPr(int $number, string $head, string $title): array
    {
        return $this->pr('opened', $number, $head, $title, ['merged' => false]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function pr(string $action, int $number, string $head, string $title, array $extra): array
    {
        return [
            'action' => $action,
            'pull_request' => [
                'number' => $number,
                'title' => $title,
                'html_url' => 'https://github.com/'.self::REPO.'/pull/'.$number,
                'head' => ['ref' => $head],
                'base' => ['ref' => 'dev'],
                'draft' => false,
            ] + $extra,
            'repository' => ['full_name' => self::REPO],
            'sender' => ['id' => 555],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function dispatch(string $deliveryId, array $payload): void
    {
        $subs = new SubscriptionRegistry($this->dir);
        (new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
            new HandlerRegistry,
            new IntentLog,
        ))->dispatch('github', self::REPO, new EventDto(
            deliveryId: $deliveryId,
            scopeId: self::REPO,
            eventType: 'pull_request.'.$payload['action'],
            actorId: '555',
        ), $payload);
    }

    private function fakePeers(): void
    {
        $cards = $this->cards ?? new KanbanCardStub([]);
        $this->cards = $cards;

        Http::fake([
            'https://api.github.com/*' => fn (Request $request) => $this->github->answer($request),
            '*/boards/8/tasks/by-ref.json*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $ref = is_string($query['ref'] ?? null) ? ltrim(preg_replace('/\D/', '', $query['ref']) ?? '', '0') : '';

                return Http::response(['data' => array_map(fn (int $id) => ['id' => $id], $this->dlCards[$ref] ?? [])]);
            },
            '*/tasks/search.json*' => function (Request $request) {
                $matched = preg_match('/(?<![a-z_])id=(\d+)/', urldecode($request->url()), $m) === 1;
                if (! $matched) {
                    return $this->boardReadsBack
                        ? Http::response(['data' => [['id' => 1, 'board_id' => 8]], 'meta' => ['total' => 1]])
                        : Http::response(['data' => [], 'meta' => ['total' => 0]]);
                }
                $row = $this->onBoard[(int) $m[1]] ?? null;

                return Http::response(['data' => $row === null ? [] : [$row], 'meta' => ['total' => $row === null ? 0 : 1]]);
            },
            '*/tasks/*/comments.json' => Http::response(['data' => ['id' => 1]]),
        ] + $cards->stub() + PreloadStub::stub(8, [49 => 1, 50 => 2, 52 => 3, 53 => 4]));
    }
}
