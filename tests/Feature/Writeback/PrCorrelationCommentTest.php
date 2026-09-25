<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\GitHubPrCardMoveClassifier;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\GitHubWriteDebt;
use App\Bridge\Writeback\PrCorrelationComment;
use App\Bridge\Writeback\PrCorrelationCommenter;
use App\Bridge\Writeback\WritebackConfig;
use App\Models\AgentDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    /**
     * Cards that EXIST, but on a board this install declares nowhere: a board-scoped lookup never
     * answers them, while an UNSCOPED search of the id does — the read card#8375 forbids, stubbed
     * to succeed so that a leak of the true board is observable rather than impossible.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $elsewhere = [];

    /** @var array<string, list<int>> DL → card ids the by-ref lookup answers */
    private array $dlCards = [];

    private ?KanbanCardStub $cards = null;

    /** Whether the mapped board itself reads back — the guard's control between a foreign id and an install fault. */
    private bool $boardReadsBack = true;

    /** @var array<string, mixed> further peer stubs a leg needs, e.g. a second declared board's stage order */
    private array $extraStubs = [];

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

    /**
     * The MULTI-BOARD sibling of the leg above (card#9850 / DL-404): a mapping that declares
     * more than one board, and a cited card on none of them.
     *
     * ⛔ THE COMMENT NAMES WHAT WAS CHECKED AND NEVER WHERE THE CARD IS, and this is the
     * widest surface any writeback record reaches — a public pull-request page — so the
     * negative below is the load-bearing assertion. The declared boards are this install's own
     * config and are what the author (or the operator) needs; the board the card actually
     * lives on was not measured, and learning it would take the unscoped read of an
     * author-supplied id card#8375 exists to prevent.
     */
    public function test_merged_naming_a_card_on_none_of_several_declared_boards_names_the_set_it_checked(): void
    {
        $this->declareSecondBoard(13);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-123-thing', title: 'feat: a thing', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=card_id_outside_declared_boards', $body);
        $this->assertStringContainsString('card#123', $body);
        $this->assertStringContainsString('board 8, board 13', $body);
        $this->assertStringNotContainsString('is not a card on board 8:', $body,
            'the single-board sentence claims the card is off ONE board; this refusal checked two and must say so');
        // The two other lines that name a board say what the Cause line says, not the mapped board alone.
        $this->assertStringContainsString('- **Boards looked on:** board 8, board 13, ', $body);
        $this->assertStringNotContainsString('the board this repository is mapped to', $body);
        $this->assertStringContainsString('with `kbcard` pointed at whichever of board 8, board 13 holds the card', $body);
        $this->assertStringNotContainsString('workflow stage 52', $body,
            'a stage id is meaningful only on its own board, and this card was established on none of them');
    }

    /**
     * The NEGATIVE the leg above cannot carry: there, card 123 exists nowhere in the fixture, so
     * a comment that named the board the card is really on had no board to name. Here card 123
     * EXISTS, on board 9002, and both unscoped reads — the card GET and an id-only search — are
     * stubbed to hand it over. Only the board-scoped lookups of the declared set miss. Anything
     * that enriched the comment with where the card really is would therefore put `9002` on a
     * public pull-request page, and this reds on it.
     */
    public function test_a_declared_set_refusal_comment_never_names_the_board_the_card_is_really_on(): void
    {
        $this->declareSecondBoard(13);
        $this->elsewhere = [123 => ['id' => 123, 'board_id' => 9002]];
        $this->cards = new KanbanCardStub([123 => $this->card(123, board: 9002)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-123-thing', title: 'feat: a thing', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=card_id_outside_declared_boards', $body);
        $this->assertStringContainsString('board 8, board 13', $body);
        $this->assertStringNotContainsString('9002', $body,
            'the comment must NEVER name the board the card is really on: it was not measured, and learning it takes '
            .'the unscoped read of an author-supplied id card#8375 exists to prevent — on the widest surface there is');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/123.json'));
    }

    /**
     * ⛔ A CAUSE DECIDED AFTER THE CARD RESOLVED ON ANOTHER DECLARED BOARD NAMES THAT BOARD
     * (card#9850 / DL-404). The move handler narrows its mapping onto the board the card was
     * established on — board 13 here — and every later refusal is about THAT board: its stage
     * map, its card. A comment rebuilt from the repo's un-narrowed mapping instead names board 8
     * and board 8's stage id on a public pull-request page, for a card board 8 does not hold.
     * One leg per cause reachable after the narrowing; the causes decided before it
     * (`card_token_near_miss`, the declared-set refusal) have no narrowed board to name.
     */
    public function test_an_uncorroborated_title_token_on_a_card_resolved_on_another_declared_board_names_that_board(): void
    {
        $this->declareSecondBoard(13);
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 13]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, board: 13, stage: 96, pr: 900)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: a thing (closes card#5)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=card_token_uncorroborated', $body);
        $this->assertStringContainsString('- **Board looked on:** board 13, the declared board this card was found on. The `merged` outcome moves a card to workflow stage 97.', $body);
        $this->assertStringContainsString('with `kbcard` pointed at board 13 (', $body);
        $this->assertStringNotContainsString('board 8', $body, 'the card is on board 13; board 8 is only where the lookup started');
        $this->assertStringNotContainsString('workflow stage 52', $body, 'board 8\'s stage id means nothing on board 13');
        $this->assertStringNotContainsString('the board this repository is mapped to', $body);
    }

    public function test_a_card_read_back_off_the_declared_board_it_resolved_on_is_compared_against_that_board(): void
    {
        $this->declareSecondBoard(13);
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 13]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, board: 9)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-5-thing', title: 'feat: a thing', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=card_not_on_mapped_board', $body);
        $this->assertStringContainsString('card#5 is on a different board than board 13, so it was not moved.', $body);
        $this->assertStringContainsString('workflow stage 97', $body);
        $this->assertStringNotContainsString('board 8', $body);
        $this->assertStringNotContainsString('workflow stage 52', $body);
        $this->assertStringNotContainsString('board 9', $body);   // the board the row came back on is never disclosed
    }

    public function test_an_unstamped_ref_on_a_close_of_a_card_resolved_on_another_declared_board_names_that_boards_stage(): void
    {
        $this->declareSecondBoard(13, ['merged' => 97, 'closed_unmerged' => 95]);
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 13]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, board: 13, stage: 96, pr: 739)]);
        $this->extraStubs = PreloadStub::stub(13, [95 => 1, 96 => 2, 97 => 3]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(719, head: 'feat/card-5-thing', title: 'feat: a thing', merged: false));

        $body = $this->onlyComment(719);
        $this->assertStringContainsString('cause=correlation_ref_not_stamped', $body);
        $this->assertStringContainsString('this close may have moved card#5 to workflow stage 95 ', $body,
            'the stage the close really moved the card to is board 13\'s, and the remedy that puts it back depends on it');
        $this->assertStringContainsString('- **Board looked on:** board 13, ', $body);
        $this->assertStringNotContainsString('workflow stage 49', $body);
        $this->assertStringNotContainsString('board 8', $body);
        $this->assertSame([['workflow_stage_id' => 95]], array_slice($this->cards->patchesTo(5), 0, 1));   // control: the decline landed on board 13's stage
    }

    /**
     * The mapped board is a declared board like any other, and a card resolved on IT keeps the
     * single-board text: it IS the board this repository is mapped to.
     */
    public function test_a_card_resolved_on_the_mapped_board_of_a_multi_board_mapping_keeps_the_mapped_board_text(): void
    {
        $this->declareSecondBoard(13);
        $this->onBoard = [5 => ['id' => 5, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([5 => $this->card(5, pr: 900)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: a thing (closes card#5)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('cause=card_token_uncorroborated', $body);
        $this->assertStringContainsString('- **Board looked on:** board 8, the board this repository is mapped to. The `merged` outcome moves a card to workflow stage 52.', $body);
        $this->assertStringNotContainsString('board 13', $body);
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

    // --- a parsed DL beside an unreadable card token: the unreadable token's own closure evidence counts --

    public function test_a_near_miss_refusal_on_an_integration_merge_from_a_branch_naming_the_unreadable_token_posts_one_comment(): void
    {
        // Had `card_77` parsed, the branch route would have closed card#77 and the DL-218 guard moved it,
        // so the refused move is one this pull request claimed; the title's DL is only a mention.
        $this->dlCards = ['42' => [5]];
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card_77-thing', title: 'feat: a thing (DL-42)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('<!-- cause=card_token_near_miss card=5 -->', $body);
        $this->assertStringContainsString('`DL-42` from the title', $body);
        $this->assertStringContainsString('a card-shaped token from the head branch that does not parse (it appears to name card 77)', $body);
        $this->assertSame([], $this->cards->patchesTo(5));   // the refusal itself is unchanged: nothing written
    }

    public function test_a_near_miss_refusal_on_a_merge_whose_title_closes_the_unreadable_token_posts_one_comment(): void
    {
        $this->dlCards = ['42' => [5]];
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: closes card_77 (DL-42)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('<!-- cause=card_token_near_miss card=5 -->', $body);
        $this->assertStringContainsString('`DL-42` from the title', $body);
        $this->assertStringContainsString('a card-shaped token from the title that does not parse (it appears to name card 77)', $body);
        $this->assertSame([], $this->cards->patchesTo(5));
    }

    public function test_an_unresolved_dl_on_an_integration_merge_from_a_branch_naming_an_unreadable_token_posts_one_token_unreadable_comment(): void
    {
        // The claim is the unreadable card token's, not the DL's, so the comment names that cause and
        // does not send its reader to stamp a DL the pull request only mentions.
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card_77-thing', title: 'feat: a thing (DL-999)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('<!-- cause=token_unreadable card=none -->', $body);
        $this->assertStringContainsString('`DL-999` from the title', $body);
        $this->assertStringContainsString('a card-shaped token from the head branch that does not parse (it appears to name card 77)', $body);
        $this->assertStringNotContainsString('--dl DL-999', $body);
    }

    public function test_an_unresolved_dl_on_a_merge_whose_title_closes_an_unreadable_token_posts_one_token_unreadable_comment(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: closes card_77 (DL-999)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('<!-- cause=token_unreadable card=none -->', $body);
        $this->assertStringContainsString('`DL-999` from the title', $body);
        $this->assertStringContainsString('a card-shaped token from the title that does not parse (it appears to name card 77)', $body);
        $this->assertStringNotContainsString('--dl DL-999', $body);
    }

    public function test_the_same_merge_with_a_card_token_that_parses_moves_that_card_and_posts_nothing_beside_a_resolving_dl(): void
    {
        $this->assertParsingBranchMovesCard77AndPostsNothing(['42' => [5]], 'feat: a thing (DL-42)');
    }

    public function test_the_same_merge_with_a_card_token_that_parses_moves_that_card_and_posts_nothing_beside_an_unresolved_dl(): void
    {
        $this->assertParsingBranchMovesCard77AndPostsNothing([], 'feat: a thing (DL-999)');
    }

    public function test_an_unresolved_dl_beside_an_unreadable_token_on_a_merge_that_claims_nothing_posts_nothing(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'docs: DL-999 notes (card_77)', merged: true));

        $this->assertSame([], $this->github->requests);
    }

    public function test_a_release_merge_from_a_branch_naming_an_unreadable_token_beside_a_resolving_dl_posts_nothing(): void
    {
        $this->dlCards = ['42' => [5]];
        $this->assertReleaseMergePostsNothing('feat: a thing (DL-42)');
    }

    public function test_a_release_merge_from_a_branch_naming_an_unreadable_token_beside_an_unresolved_dl_posts_nothing(): void
    {
        $this->assertReleaseMergePostsNothing('feat: a thing (DL-999)');
    }

    /** @param  array<string, list<int>>  $dlCards */
    private function assertParsingBranchMovesCard77AndPostsNothing(array $dlCards, string $title): void
    {
        $this->dlCards = $dlCards;
        $this->onBoard = [77 => ['id' => 77, 'board_id' => 8]];
        $this->cards = new KanbanCardStub([77 => $this->card(77)]);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card-77-thing', title: $title, merged: true));

        $this->assertSame(52, $this->cards->patchesTo(77)[0]['workflow_stage_id'] ?? null);   // control: it moved
        $this->assertSame([], $this->github->requests);
    }

    private function assertReleaseMergePostsNothing(string $title): void
    {
        $this->fakePeers();
        $release = $this->closedPr(702, head: 'feat/card_77-thing', title: $title, merged: true);
        $release['pull_request']['base']['ref'] = 'main';

        $this->dispatch('d1', $release);

        $this->assertSame([], $this->github->requests);
    }

    // --- the DL and the cause a comment names are the classifier's, never re-read from the token list --

    public function test_a_dl_unresolved_comment_names_the_dl_the_classifier_looked_up_not_the_head_branch_dl(): void
    {
        // The lookup reads the title before the head branch, and the token list reads the branch
        // first. DL-12 is carried by card 5; naming it would send the reader to stamp it twice.
        $this->dlCards = ['12' => [5]];
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-12-thing', title: 'feat: a thing (closes DL-999)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('<!-- cause=dl_unresolved card=none -->', $body);
        $this->assertStringContainsString('carries `dl_number` DL-999,', $this->causeLine($body));
        $this->assertStringContainsString('kbcard patch --task <card-id> --dl DL-999 --pr 702', $this->remedy($body));
        $this->assertStringNotContainsString('DL-12', $this->causeLine($body));
        $this->assertStringNotContainsString('DL-12', $this->remedy($body));
        $this->assertStringContainsString('`DL-12` from the head branch', $body);   // listed as read, never claimed
    }

    public function test_a_near_miss_refusal_beside_a_closed_unreadable_dl_spelling_and_a_mentioned_card_token_posts_nothing(): void
    {
        // `closes DL_7` claims a DL-shaped spelling. Beside a DL that parsed, the only unreadable token
        // in question is the card-shaped `card_77`, and the title only mentions it.
        $this->dlCards = ['42' => [5]];
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: closes DL_7 (DL-42) see card_77', merged: true));

        $this->assertSame([], $this->github->requests);
        $this->assertSame([], $this->cards->patchesTo(5));   // the refusal itself is unchanged: nothing written
    }

    public function test_an_unresolved_dl_beside_a_closed_unreadable_dl_spelling_and_a_mentioned_card_token_posts_nothing(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: closes DL_7 (DL-999) see card_5', merged: true));

        $this->assertSame([], $this->github->requests);
    }

    public function test_a_merge_whose_title_closes_an_unreadable_dl_spelling_where_nothing_parses_posts_one_token_unreadable_comment(): void
    {
        // Where no DL parsed, a DL-shaped spelling IS the unreadable token, so its closure counts there.
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'fix: a thing (closes DL_7)', merged: true));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('<!-- cause=token_unreadable card=none -->', $body);
        $this->assertStringContainsString('a DL-shaped token from the title that does not parse', $body);
    }

    public function test_a_close_whose_title_only_mentions_a_dl_no_card_carries_names_it_without_a_dl_remedy(): void
    {
        // A close is reported without closure evidence, but a DL the title does not close is never
        // offered for stamping. No unreadable token is present, so the cause stays `dl_unresolved`.
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'fix/thing-abc', title: 'feat: a thing (DL-999)', merged: false));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('<!-- cause=dl_unresolved card=none -->', $body);
        $this->assertStringContainsString('carries `dl_number` DL-999,', $this->causeLine($body));
        $this->assertStringNotContainsString('--dl', $this->remedy($body));
        $this->assertStringContainsString('kbcard patch --task <card-id> --pr 702', $this->remedy($body));
    }

    public function test_a_close_from_a_branch_naming_an_unreadable_card_token_beside_a_mentioned_dl_posts_token_unreadable_without_a_dl_remedy(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card_77-thing', title: 'feat: a thing (DL-999)', merged: false));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('<!-- cause=token_unreadable card=none -->', $body);
        $this->assertStringNotContainsString('--dl', $this->remedy($body));
    }

    public function test_a_close_whose_title_closes_a_dl_no_card_carries_beside_an_unreadable_card_token_offers_the_dl(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/card_77-thing', title: 'feat: closes DL-999', merged: false));

        $body = $this->onlyComment(702);
        $this->assertStringContainsString('<!-- cause=dl_unresolved card=none -->', $body);
        $this->assertStringContainsString('kbcard patch --task <card-id> --dl DL-999 --pr 702', $this->remedy($body));
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

    // --- a comment that did not land is OWED, and the operator's repair posts it (card#10365 / DL-422) ---
    //
    // ⛔ THE TRANSITION IS THE SUBJECT, NEVER THE END STATE. A pull request MERGES ONCE, so "the next
    // event tries again" never fires on a merge; each leg below asserts the sequence — refused, then
    // OWED with the comment ABSENT from the pull request, then the cause cleared, then posted, then
    // the record empty — because a test asserting only the end state certifies whatever put it there.

    public function test_a_merge_comment_github_refused_is_owed_and_posted_by_the_operators_repair(): void
    {
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();

        // (1) DECIDED AND REFUSED. GitHub was asked; nothing is on the pull request; the write is owed.
        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));
        $this->assertCount(1, $this->github->posts(702));
        $this->assertSame([], $this->github->stored(702));
        $this->assertSame([[702, 'merged', 'post_refused', 403, 1]], $this->owedComments());
        $rendered = $this->github->posts(702)[0];

        // (2) THE OPERATOR GRANTS THE TOKEN WRITE. There is no later merge event for this pull request.
        $this->github->postStatus = 201;

        $this->artisan('bridge:github-owed', ['--fix' => true])
            ->expectsOutputToContain('done      '.self::REPO.'#702 [the `merged` correlation comment] — posted')
            ->assertSuccessful();

        // (3) POSTED — the body the EVENT rendered, not a re-render — AND THE DEBT IS DISCHARGED.
        $this->assertSame([$rendered], $this->github->stored(702));
        $this->assertStringContainsString('cause=dl_unresolved', $rendered);
        $this->assertSame([], GitHubWriteDebt::owed());
    }

    public function test_the_default_run_is_report_only_and_leaves_the_pull_request_as_the_refusal_left_it(): void
    {
        // The CONTROL for the leg above: same install, same owed comment, same command, no --fix.
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();
        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));
        $this->github->postStatus = 201;
        $requests = count($this->github->requests);

        $this->artisan('bridge:github-owed')
            ->expectsOutputToContain('owed  '.self::REPO.'#702 [the `merged` correlation comment] — post_refused (403)')
            ->assertSuccessful();

        $this->assertCount($requests, $this->github->requests, 'a report-only run must reach GitHub for nothing');
        $this->assertSame([], $this->github->stored(702));
        $this->assertSame([[702, 'merged', 'post_refused', 403, 1]], $this->owedComments());
    }

    public function test_a_repair_that_is_refused_again_stays_owed_and_the_run_reds(): void
    {
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();
        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        $this->artisan('bridge:github-owed', ['--fix' => true])
            ->expectsOutputToContain('still owed '.self::REPO.'#702 [the `merged` correlation comment] — post_refused (403)')
            ->assertFailed();

        $this->assertSame([], $this->github->stored(702));
        $this->assertSame([[702, 'merged', 'post_refused', 403, 2]], $this->owedComments());
    }

    public function test_a_repair_refuses_while_another_repair_holds_the_lock_and_posts_nothing(): void
    {
        // The repair's dedupe read and its POST are not atomic, so two runs at once could each find
        // the comment absent and each post it. The second is REFUSED, by name, before it reads.
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();
        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));
        $this->github->postStatus = 201;
        $requests = count($this->github->requests);

        // Another run holds the repair lock — through the same primitive a run takes it with.
        BridgePaths::withLock(GitHubWriteDebt::repairLockTarget(), function (): void {
            $this->artisan('bridge:github-owed', ['--fix' => true])
                ->expectsOutputToContain('REFUSED — another `bridge:github-owed --fix` is running on this install')
                ->assertFailed();
        });

        $this->assertCount($requests, $this->github->requests, 'a refused repair must reach GitHub for nothing');
        $this->assertSame([], $this->github->stored(702));
        $this->assertSame([[702, 'merged', 'post_refused', 403, 1]], $this->owedComments());

        // Released, the same command runs: the refusal was the lock, not the state.
        $this->artisan('bridge:github-owed', ['--fix' => true])->assertSuccessful();
        $this->assertCount(1, $this->github->stored(702));
    }

    /**
     * Re-derived for THIS surface (card#10365), from GitHub's REST reference for the two requests
     * the comment makes — not inherited from the label's table. `422` is the row that differs.
     *
     * @return array<string, array{0: string, 1: int|string|null, 2: string, 3: bool}>
     */
    public static function commentFailureArms(): array
    {
        return [
            // recoverable: an operator act, or time, can make the identical request succeed
            'no token file resolves' => ['post', null, 'token_unresolved', true],
            'post 401 the token is bad or expired' => ['post', 401, 'post_refused', true],
            'post 403 no write, or a secondary rate limit' => ['post', 403, 'post_refused', true],
            'post 404 the token cannot SEE the repo' => ['post', 404, 'post_refused', true],
            'post 408' => ['post', 408, 'post_refused', true],
            'post 422 validation OR the endpoint spammed' => ['post', 422, 'post_refused', true],
            'post 429 rate limited' => ['post', 429, 'post_refused', true],
            'post 500 a GitHub fault' => ['post', 500, 'post_refused', true],
            'post never completed' => ['post', 'transport', 'post_failed', true],
            'read 401' => ['list', 401, 'dedupe_read_refused', true],
            'read 403' => ['list', 403, 'dedupe_read_refused', true],
            'read 404' => ['list', 404, 'dedupe_read_refused', true],
            'read 408' => ['list', 408, 'dedupe_read_refused', true],
            'read 429' => ['list', 429, 'dedupe_read_refused', true],
            'read 502' => ['list', 502, 'dedupe_read_refused', true],
            'read never completed' => ['list', 'transport', 'dedupe_read_failed', true],
            'read not to the end' => ['list', 'unreadable', 'dedupe_read_incomplete', true],
            // terminal: the identical request will be refused for the same reason forever
            'post 410 the thread is gone' => ['post', 410, 'post_refused', false],
            'post 400 malformed' => ['post', 400, 'post_refused', false],
            'read 410 the thread is gone' => ['list', 410, 'dedupe_read_refused', false],
            'read 422' => ['list', 422, 'dedupe_read_refused', false],
        ];
    }

    #[DataProvider('commentFailureArms')]
    public function test_only_a_recoverable_comment_failure_is_owed(string $request, int|string|null $answer, string $reason, bool $owed): void
    {
        match (true) {
            $answer === null => File::delete($this->dir.'/github/token'),
            $answer === 'transport' && $request === 'list' => $this->github->listFailsInTransport = true,
            $answer === 'transport' => $this->github->postFailsInTransport = true,
            $answer === 'unreadable' => $this->github->seedUnreadable(702),
            $request === 'list' => $this->github->listStatus = (int) $answer,
            default => $this->github->postStatus = (int) $answer,
        };
        $this->fakePeers();
        Log::spy();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        // The arm is REACHED, not just the record empty: a terminal row that never got its answer
        // would also owe nothing.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'pr_correlation_comment: NOT posted')
            && ($context['reason'] ?? null) === $reason
            && ($context['status'] ?? null) === (is_int($answer) ? $answer : null))->once();
        $this->assertSame([], $this->github->stored(702));
        $this->assertSame($owed ? [[702, 'merged', $reason, is_int($answer) ? $answer : null, 1]] : [], $this->owedComments());
    }

    public function test_an_owed_comment_the_repair_finds_can_never_land_is_reported_terminal_and_forgotten(): void
    {
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();
        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));
        $this->assertSame([[702, 'merged', 'post_refused', 403, 1]], $this->owedComments());

        // The pull request's thread is now GONE: the identical request is refused forever.
        $this->github->postStatus = 410;

        $this->artisan('bridge:github-owed', ['--fix' => true])
            ->expectsOutputToContain('terminal  '.self::REPO.'#702 [the `merged` correlation comment] — post_refused (410); this write can never land and is no longer owed')
            ->assertSuccessful();

        $this->assertCount(2, $this->github->posts(702), 'the repair did attempt it');
        $this->assertSame([], $this->github->stored(702));
        $this->assertSame([], GitHubWriteDebt::owed());
    }

    public function test_a_two_hundred_that_does_not_carry_the_comment_is_owed_and_the_repair_never_posts_it_twice(): void
    {
        // A 2xx is the server's CLAIM. Here GitHub DID store the comment and answered with a body
        // that does not carry it, so the event cannot confirm it: owed, never `posted`. The repair
        // reads the pull request first, finds the marker, and discharges the debt WITHOUT a second POST.
        $this->github = new GitHubIssueCommentsStub(postAnswer: ['id' => 1]);
        $this->fakePeers();
        Log::spy();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'pr_correlation_comment.post_unconfirmed'
            && ($context['reason'] ?? null) === 'post_unconfirmed')->once();
        $this->assertSame([[702, 'merged', 'post_unconfirmed', null, 1]], $this->owedComments());

        $this->artisan('bridge:github-owed', ['--fix' => true])
            ->expectsOutputToContain('done      '.self::REPO.'#702 [the `merged` correlation comment] — already_posted')
            ->assertSuccessful();

        $this->assertCount(1, $this->github->posts(702), 'the repair must find the comment that landed, never post a second');
        $this->assertSame([], GitHubWriteDebt::owed());
    }

    public function test_an_unexpected_failure_after_the_post_landed_is_owed_and_the_repair_finds_it_without_posting_again(): void
    {
        // A log sink that fails on the success line: outside every named step, AFTER GitHub stored
        // the comment. The bridge cannot tell that from a failure before the POST, so it is owed.
        $this->fakePeers();
        Log::spy();
        Log::shouldReceive('info')->withArgs(fn (string $message) => $message === 'pr_correlation_comment: posted')
            ->andThrow(new RuntimeException('log sink down'));

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'pr_correlation_comment: NOT posted')
            && ($context['reason'] ?? null) === 'unexpected'
            && ($context['catalog_id'] ?? null) === 'pr_correlation_comment.unexpected_failure')->once();
        $this->assertCount(1, $this->github->stored(702), 'the comment DID land');
        $this->assertSame([[702, 'merged', 'unexpected', null, 1]], $this->owedComments());

        $this->artisan('bridge:github-owed', ['--fix' => true])
            ->expectsOutputToContain('done      '.self::REPO.'#702 [the `merged` correlation comment] — already_posted')
            ->assertSuccessful();

        $this->assertCount(1, $this->github->posts(702), 'the repair must find the comment that landed, never post a second');
        $this->assertSame([], GitHubWriteDebt::owed());
    }

    public function test_a_comment_that_failed_to_render_is_not_owed(): void
    {
        // Nothing was decided that could be finished: the body never existed. A ref key that is
        // not a string throws inside the render (an array meets string conversion), which is a
        // real failure of the render step rather than a stand-in for one.
        $this->fakePeers();
        Log::spy();
        $mapping = WritebackConfig::loadDefault()?->mappingFor(self::REPO);
        $this->assertNotNull($mapping);
        $payload = ['repo' => self::REPO, 'outcome' => 'merged']
            + PrCorrelationComment::evidence('merged', 702, 'feat/thing', 'feat: a thing');

        (new PrCorrelationCommenter)->report($payload, PrCorrelationComment::TOKEN_UNREADABLE, $mapping, ['dropped' => [['not a key']]]);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'pr_correlation_comment: NOT posted')
            && ($context['reason'] ?? null) === 'unexpected')->once();
        $this->assertSame([], $this->github->requests, 'nothing rendered, so nothing reached GitHub');
        $this->assertSame([], $this->owedComments());
        $this->assertFileDoesNotExist(GitHubWriteDebt::path());
    }

    public function test_a_later_event_that_posts_the_comment_discharges_what_an_earlier_one_owed(): void
    {
        // The repair route that needs no command, for the one outcome that CAN recur: a reopen and
        // a second close of the same pull request.
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();
        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: 'feat: a thing', merged: false));
        $this->assertSame([[702, 'closed_unmerged', 'post_refused', 403, 1]], $this->owedComments());

        $this->github->postStatus = 201;
        $this->dispatch('d2', $this->closedPr(702, head: 'feat/dl-390-thing', title: 'feat: a thing', merged: false));

        $this->assertCount(1, $this->github->stored(702));
        $this->assertSame([], GitHubWriteDebt::owed());
    }

    public function test_an_owed_comment_is_one_per_pull_request_and_outcome(): void
    {
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();

        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: 'feat: a thing', merged: false));
        $this->dispatch('d2', $this->closedPr(702, head: 'feat/dl-390-thing', title: 'feat: a thing', merged: false));
        $this->dispatch('d3', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));

        // The marker is per outcome, so the close and the merge owe two comments; the second close
        // bumps its outcome's attempt count rather than minting a second row.
        $this->assertSame([
            [702, 'closed_unmerged', 'post_refused', 403, 2],
            [702, 'merged', 'post_refused', 403, 1],
        ], $this->owedComments());
    }

    public function test_a_repo_no_longer_mapped_is_forgotten_rather_than_posted_to(): void
    {
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();
        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));
        $this->github->postStatus = 201;
        $requests = count($this->github->requests);

        // The operator removed the mapping between the refusal and the repair.
        File::put($this->dir.'/writeback.json', (string) json_encode(['identity_id' => 4242, 'mappings' => [
            'acme/other' => ['board_id' => 8, 'stages' => ['merged' => 52]],
        ]]));

        $this->artisan('bridge:github-owed', ['--fix' => true])
            ->expectsOutputToContain('dropped   '.self::REPO.'#702 [the `merged` correlation comment] — this repo is no longer mapped in writeback.json; nothing was written')
            ->assertSuccessful();

        $this->assertCount($requests, $this->github->requests, 'the install no longer writes this repo — the repair must not either');
        $this->assertSame([], GitHubWriteDebt::owed());
    }

    public function test_an_unreadable_writeback_config_keeps_the_comment_owed_and_sends_nothing(): void
    {
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();
        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));
        $this->github->postStatus = 201;
        $requests = count($this->github->requests);

        // Present and not a JSON object: whether the install still maps this repo is UNKNOWN, which
        // is neither "switched off" (drop) nor "still on" (post).
        File::put($this->dir.'/writeback.json', 'not json');

        $this->artisan('bridge:github-owed', ['--fix' => true])
            ->expectsOutputToContain('still owed '.self::REPO.'#702 [the `merged` correlation comment] — writeback.json cannot be read')
            ->assertFailed();

        $this->assertCount($requests, $this->github->requests);
        $this->assertSame([[702, 'merged', 'post_refused', 403, 1]], $this->owedComments());
    }

    public function test_one_run_finishes_an_owed_comment_and_an_owed_label_from_the_one_record(): void
    {
        // ONE primitive, ONE record (canon #5 at the second caller): an operator who granted the
        // token write once finishes every write that refusal cost with one command.
        $this->github = new GitHubIssueCommentsStub(postStatus: 403);
        $this->fakePeers();
        $this->dispatch('d1', $this->closedPr(702, head: 'feat/dl-390-thing', title: self::CLOSES_DL_390, merged: true));
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => '9'], 'add_refused', 403, true);
        $this->assertEqualsCanonicalizing([GitHubWriteDebt::KIND_COMMENT, GitHubWriteDebt::KIND_LABEL], array_column(GitHubWriteDebt::owed(), 'kind'));

        $this->github->postStatus = 201;

        $this->artisan('bridge:github-owed', ['--fix' => true])->assertSuccessful();

        $this->assertCount(1, $this->github->stored(702));
        $this->assertSame(['protocol:invalid'], $this->github->labels(41));
        $this->assertSame([], GitHubWriteDebt::owed());
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

    private function causeLine(string $body): string
    {
        $this->assertSame(1, preg_match('/^- \*\*Cause:\*\* .*$/m', $body, $m), 'no Cause line in the comment');

        return $m[0];
    }

    private function remedy(string $body): string
    {
        $this->assertSame(1, preg_match('/^```\n(.*?)\n```$/ms', $body, $m), 'no remedy block in the comment');

        return $m[1];
    }

    /**
     * The owed correlation comments as `[pr, outcome, reason, status, attempts]` — the fields a
     * reader acts on, with the timestamps and the body left out.
     *
     * @return list<array{0: int, 1: mixed, 2: string, 3: ?int, 4: int}>
     */
    private function owedComments(): array
    {
        return array_values(array_map(
            fn (array $e): array => [$e['number'], $e['outcome'] ?? null, $e['reason'], $e['status'], $e['attempts']],
            array_filter(GitHubWriteDebt::owed(), fn (array $e): bool => $e['kind'] === GitHubWriteDebt::KIND_COMMENT),
        ));
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

    /**
     * Re-write this install's mapping with an additional declared board (card#9850 / DL-404).
     * The mapped board and its stage map are untouched — `boards` is additive — so every other
     * leg in this class keeps the fixture it was written against.
     */
    /** @param  array<string, int>  $stages  that board's OWN stage map */
    private function declareSecondBoard(int $boardId, array $stages = ['merged' => 97]): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => [self::REPO => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'boards' => [(string) $boardId => $stages],
            ]],
        ]));
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
                $scoped = preg_match('/(?<![a-z_])board_id=(\d+)/', urldecode($request->url()), $b) === 1;
                if ($scoped && $row !== null && ($row['board_id'] ?? null) !== (int) $b[1]) {
                    // A board-scoped lookup answers only the rows on the board it names, so a card
                    // on another declared board is found by THAT board's lookup and no other.
                    $row = null;
                }
                if ($row === null && ! $scoped) {
                    $row = $this->elsewhere[(int) $m[1]] ?? null;
                }

                return Http::response(['data' => $row === null ? [] : [$row], 'meta' => ['total' => $row === null ? 0 : 1]]);
            },
            '*/tasks/*/comments.json' => Http::response(['data' => ['id' => 1]]),
        ] + $cards->stub() + PreloadStub::stub(8, [49 => 1, 50 => 2, 52 => 3, 53 => 4]) + $this->extraStubs);
    }
}
