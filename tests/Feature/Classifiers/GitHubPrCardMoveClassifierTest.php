<?php

namespace Tests\Feature\Classifiers;

use App\Bridge\Classifiers\GitHubPrCardMoveClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ClassifierConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GitHubPrCardMoveClassifierTest extends TestCase
{
    private string $dir;

    private AgentConfig $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/prcls-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ]]],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            // These tests fake the scan correlation path (board search); pin scan
            // (the default is now `ref`, DL-031). Ref correlation is covered in
            // KanbanClientTest; this suite verifies classifier target emission.
            'bridge.writeback.correlation' => 'scan',
        ]);
        $this->agent = AgentConfig::fromArray('test-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<mixed> $pr */
    private function classify(string $eventType, array $pr, string $repo = 'owner/repo'): ClassifyResult
    {
        return (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            $eventType,
            ['pull_request' => $pr, 'repository' => ['full_name' => $repo]],
            new Actor('999'),
            'github',
            $repo,
            $this->agent,
        ));
    }

    private function fakeBoardCards(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
        ]])]);
    }

    /** @param array<string,mixed> $extra extra keys merged into the owner/repo mapping */
    private function writeMapping(array $extra): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => array_merge(['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ]], $extra)],
        ]));
    }

    /** @return list<ReactionTarget> */
    private function targetsNamed(ClassifyResult $r, string $handler): array
    {
        return array_values(array_filter($r->targets, fn ($t) => $t->handler === $handler));
    }

    public function test_promote_target_emitted_on_bare_release_pr_merged_to_main(): void
    {
        $this->writeMapping(['promote_on_release' => true]);

        // A release PR: closed+merged into main, NO DL/card token in title/head.
        $r = $this->classify('pull_request.closed', [
            'number' => 300, 'merged' => true, 'base' => ['ref' => 'main'],
            'title' => 'chore(release): v0.60.0', 'head' => ['ref' => 'release/v0.60.0'],
        ]);

        $promote = $this->targetsNamed($r, 'kanban_promote_released');
        $this->assertCount(1, $promote);
        $this->assertSame(['repo' => 'owner/repo'], $promote[0]->payload);
        $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'));
    }

    public function test_promote_target_emitted_alongside_the_dl_move_target(): void
    {
        $this->writeMapping(['promote_on_release' => true]);
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.closed', [
            'number' => 301, 'merged' => true, 'base' => ['ref' => 'main'],
            'title' => 'DL-42 folded release', 'head' => ['ref' => 'feature/x'],
        ]);

        $this->assertCount(1, $this->targetsNamed($r, 'kanban_promote_released'));
        $this->assertCount(1, $this->targetsNamed($r, 'kanban_move_card'));
    }

    public function test_promote_target_emitted_even_on_a_dependabot_merge_to_main(): void
    {
        // The Finding-8 edge: a dependabot PR merged to main early-returns before the
        // move/overlay targets — the promote scan must still be appended.
        $this->writeMapping(['promote_on_release' => true, 'create_dependabot_cards' => true]);

        $r = $this->classify('pull_request.closed', [
            'number' => 302, 'merged' => true, 'base' => ['ref' => 'main'],
            'title' => 'Bump lib', 'head' => ['ref' => 'dependabot/npm_and_yarn/lib-1.2.3'],
        ]);

        $this->assertCount(1, $this->targetsNamed($r, 'kanban_promote_released'));
        $this->assertCount(1, $this->targetsNamed($r, 'kanban_dependabot_card'));
    }

    public function test_no_promote_target_on_merge_to_dev(): void
    {
        $this->writeMapping(['promote_on_release' => true]);
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.closed', [
            'number' => 303, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'DL-42 feature', 'head' => ['ref' => 'feature/y'],
        ]);

        $this->assertSame([], $this->targetsNamed($r, 'kanban_promote_released'));
    }

    public function test_no_promote_target_when_flag_off(): void
    {
        $this->writeMapping([]);   // promote_on_release absent ⇒ off

        $r = $this->classify('pull_request.closed', [
            'number' => 304, 'merged' => true, 'base' => ['ref' => 'main'],
            'title' => 'chore(release): v0.60.0', 'head' => ['ref' => 'release/v0.60.0'],
        ]);

        $this->assertSame([], $this->targetsNamed($r, 'kanban_promote_released'));
    }

    public function test_consumed_event_types_are_pull_request_and_push(): void
    {
        // card#4183 (DL-196): the writeback classifier consumes pull_request (the
        // move lifecycle) + push (the DL-160 branch-create `started` trigger),
        // config-independent.
        $events = (new GitHubPrCardMoveClassifier)->consumedEventTypes(ClassifierConfig::empty());

        sort($events);
        $this->assertSame(['pull_request', 'push'], $events);
    }

    public function test_opened_pr_correlates_and_emits_move_to_opened_stage(): void
    {
        $this->fakeBoardCards();

        $result = $this->classify('pull_request.opened', ['title' => 'feat: DL-42 ship it', 'head' => ['ref' => 'feat/x']]);

        $this->assertCount(1, $result->targets);
        $t = $result->targets[0];
        $this->assertSame('kanban_move_card', $t->handler);
        $this->assertSame(['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'opened'], $t->payload);
        $this->assertSame([], $result->intents);   // machine-only, no inbox intent
    }

    public function test_merged_to_main_vs_merged_keys_on_base_ref(): void
    {
        $this->fakeBoardCards();

        $main = $this->classify('pull_request.closed', ['title' => 'DL-42', 'merged' => true, 'base' => ['ref' => 'main']]);
        $this->assertSame('merged_to_main', $main->targets[0]->payload['outcome']);

        $dev = $this->classify('pull_request.closed', ['title' => 'DL-42', 'merged' => true, 'base' => ['ref' => 'dev']]);
        $this->assertSame('merged', $dev->targets[0]->payload['outcome']);
    }

    public function test_closed_unmerged_outcome(): void
    {
        $this->fakeBoardCards();
        $r = $this->classify('pull_request.closed', ['title' => 'DL-42', 'merged' => false]);
        $this->assertSame('closed_unmerged', $r->targets[0]->payload['outcome']);
    }

    public function test_dl_token_from_head_branch_when_title_has_none(): void
    {
        $this->fakeBoardCards();
        $r = $this->classify('pull_request.opened', ['title' => 'no ref here', 'head' => ['ref' => 'fix/DL-42-thing']]);
        $this->assertSame(5, $r->targets[0]->payload['card_id']);
    }

    public function test_unmapped_repo_is_noop(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.opened', ['title' => 'DL-42'], repo: 'other/repo');
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();   // didn't even search
    }

    public function test_no_dl_token_is_noop(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.opened', ['title' => 'no card reference']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_no_matching_card_is_noop(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        $r = $this->classify('pull_request.opened', ['title' => 'DL-42']);
        $this->assertSame([], $r->targets);
    }

    public function test_bundled_dl_emits_one_move_target_per_matching_card(): void
    {
        // DL-148: a DL can track multiple cards (bundled PR) — move them ALL,
        // one target each with the card id as a distinct target_id (no coalesce).
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
            ['id' => 6, 'payload' => ['dl_number' => '042']],   // same canonical 42
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],  // different DL, not matched
        ]])]);

        $r = $this->classify('pull_request.closed', ['title' => 'DL-42', 'merged' => true, 'base' => ['ref' => 'main']]);

        $this->assertCount(2, $r->targets);
        $ids = array_map(fn ($t) => $t->payload['card_id'], $r->targets);
        $this->assertEqualsCanonicalizing([5, 6], $ids);
        $this->assertEqualsCanonicalizing(['5', '6'], array_map(fn ($t) => $t->targetId, $r->targets));   // distinct target ids
        foreach ($r->targets as $t) {
            $this->assertSame('kanban_move_card', $t->handler);
            $this->assertSame('merged_to_main', $t->payload['outcome']);
        }
    }

    public function test_non_pull_request_non_push_event_is_noop(): void
    {
        Http::fake();
        $r = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext('issues.opened', [], new Actor('1'), 'github', 'owner/repo', $this->agent));
        $this->assertSame([], $r->targets);
    }

    /** @param array<mixed> $payload */
    private function classifyPush(array $payload, string $repo = 'owner/repo'): ClassifyResult
    {
        return (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            'push',
            $payload + ['repository' => ['full_name' => $repo]],
            new Actor('999'),
            'github',
            $repo,
            $this->agent,
        ));
    }

    public function test_branch_create_push_with_dl_emits_started_target(): void
    {
        $this->fakeBoardCards();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/DL-42-thing']);

        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_move_card', $t->handler);
        $this->assertSame(['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'started'], $t->payload);
        $this->assertSame([], $r->intents);
    }

    public function test_bundled_dl_branch_create_emits_one_started_target_per_card(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
            ['id' => 6, 'payload' => ['dl_number' => '042']],
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
        ]])]);

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/DL-42-bundle']);

        $this->assertEqualsCanonicalizing([5, 6], array_map(fn ($t) => $t->payload['card_id'], $r->targets));
        foreach ($r->targets as $t) {
            $this->assertSame('started', $t->payload['outcome']);
        }
    }

    public function test_push_to_existing_branch_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => false, 'ref' => 'refs/heads/feat/DL-42-thing']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();   // not a branch creation → no correlation read
    }

    public function test_branch_create_push_without_dl_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/no-card-ref']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_dependabot_branch_create_push_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/dependabot/composer/DL-1-x']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_tag_create_push_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/tags/DL-42-v1']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_branch_create_push_unmapped_repo_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/DL-42-x'], repo: 'other/repo');
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_branch_create_push_no_matching_card_is_noop(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/DL-42-x']);
        $this->assertSame([], $r->targets);
    }

    public function test_unhandled_pr_action_is_noop(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.synchronize', ['title' => 'DL-42']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    private function enableDependabot(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ], 'create_dependabot_cards' => true]],
        ]));
    }

    public function test_dependabot_pr_emits_create_or_move_target_when_opted_in(): void
    {
        $this->enableDependabot();
        Http::fake();   // no correlation read on this path

        $r = $this->classify('pull_request.opened', [
            'title' => 'chore(deps): Bump x from 1 to 2',
            'number' => 77,
            'head' => ['ref' => 'dependabot/composer/x-2.0'],
            'html_url' => 'https://github.com/owner/repo/pull/77',
        ]);

        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_dependabot_card', $t->handler);
        $this->assertSame('owner/repo', $t->payload['repo']);
        $this->assertSame('opened', $t->payload['outcome']);
        $this->assertSame(77, $t->payload['pr_number']);
        Http::assertNothingSent();   // create/move decided by the durable handler, not here
    }

    public function test_dependabot_pr_falls_through_when_not_opted_in(): void
    {
        // setUp's config has no create_dependabot_cards → no dependabot branch;
        // a dependabot PR has no DL, so the normal path is a no-op.
        Http::fake();
        $r = $this->classify('pull_request.opened', [
            'title' => 'chore(deps): Bump x', 'number' => 77, 'head' => ['ref' => 'dependabot/composer/x-2.0'],
        ]);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    /** Write a writeback.json with the given mappings and pin `ref` correlation. */
    private function useRefCorrelation(array $mappings): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => $mappings,
        ]));
        config(['bridge.writeback.correlation' => 'ref']);
    }

    public function test_ref_correlation_omits_source_qualifier_on_a_non_shared_board(): void
    {
        $this->useRefCorrelation(['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50]]]);
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 7]]])]);

        $result = $this->classify('pull_request.opened', ['title' => 'Fix DL-9 thing', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/boards/8/tasks/by-ref.json')
            && ! str_contains(urldecode($r->url()), 'source='));
    }

    public function test_ref_correlation_keeps_source_qualifier_on_a_shared_board(): void
    {
        $this->useRefCorrelation([
            'owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50]],
            'owner/other' => ['board_id' => 8, 'stages' => ['opened' => 50]],
        ]);
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 7]]])]);

        $result = $this->classify('pull_request.opened', ['title' => 'Fix DL-9 thing', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'source=owner/repo'));
    }

    public function test_card_token_in_title_correlates_by_native_id_without_a_kanban_read(): void
    {
        Http::fake();
        $result = $this->classify('pull_request.opened', ['title' => 'Fix flaky retry card#3410', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame('kanban_move_card', $result->targets[0]->handler);
        $this->assertSame(3410, $result->targets[0]->payload['card_id']);
        $this->assertSame('opened', $result->targets[0]->payload['outcome']);
        Http::assertNothingSent();   // native-id selection needs no classify-time kanban read
    }

    public function test_card_token_matches_case_insensitively_and_in_head_branch(): void
    {
        Http::fake();
        $result = $this->classify('pull_request.opened', ['title' => 'Fix a thing', 'head' => ['ref' => 'fix/CARD#77-thing']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame(77, $result->targets[0]->payload['card_id']);
    }

    public function test_dl_wins_when_a_co_present_card_token_names_the_same_card(): void
    {
        // FR-7 precedence (framework v0.2.229): DL-NNN is the ratified, more-specific
        // contract. When a co-present card# names the SAME card the DL resolves to it
        // is redundant — the DL wins and nothing is dropped (logged for the ledger).
        $this->fakeBoardCards();
        Log::spy();

        // DL-9 correlates to card 7; card#7 agrees ⇒ no conflict.
        $result = $this->classify('pull_request.opened', ['title' => 'Fix DL-9 thing card#7', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame(7, $result->targets[0]->payload['card_id']);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains((string) $msg, 'same card'))->once();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_dl_only_move_targets_the_resolved_card(): void
    {
        // FR-7 (1): a lone resolving DL with no card# → move that card, no warn.
        $this->fakeBoardCards();
        Log::spy();

        // DL-42 correlates to card 5.
        $result = $this->classify('pull_request.opened', ['title' => 'Ship DL-42', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame(5, $result->targets[0]->payload['card_id']);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_conflicting_card_token_overrides_the_dl_and_warns(): void
    {
        // DL-218 / card#4811 incident: a DL in the title resolves to a card DIFFERENT
        // from a co-present explicit card# — a descriptive/foreign DL mention must not
        // hijack the move. The explicit card# is authoritative: move it, not the DL
        // card, and warn LOUDLY. (Revert the fix ⇒ DL-9's card 7 is targeted ⇒ RED.)
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        // "Static guard against DL-9 re-introduction (card#4811)" — DL-9 resolves to
        // card 7, but the intended card is #4811.
        $result = $this->classify('pull_request.closed', [
            'number' => 148, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'Static guard against DL-9 re-introduction card#4811', 'head' => ['ref' => 'f'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(1, $move);
        $this->assertSame(4811, $move[0]->payload['card_id']);   // the explicit card#, NOT DL-9's card 7
        $this->assertSame('merged', $move[0]->payload['outcome']);
        $this->assertSame(148, $move[0]->payload['stamp_pr']);   // card# path stamps the PR number
        // The foreign DL-9 (it belongs to card 7) must NOT be stamped onto card#4811,
        // or the move-hijack re-emerges as a correlation poison.
        $this->assertArrayNotHasKey('stamp_dl', $move[0]->payload);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'card#4811')
            && str_contains((string) $msg, 'authoritative'))->once();
    }

    public function test_conflicting_card_token_over_a_bundled_dl_drops_the_other_dl_cards(): void
    {
        // DL-218 edge (the intended ruling, pinned): a ONE-TO-MANY DL (bundled PR) that
        // ALSO carries a card# NOT in the resolved set → the explicit card# is
        // authoritative, so ONLY it moves and the OTHER DL-resolved cards are dropped
        // (the rejected "warn+skip" alternative would have moved none). The warning
        // NAMES the dropped card ids for the ledger, so the drop is diagnosable, not
        // silent. (Revert the fix ⇒ DL-9's cards 7 AND 8 move ⇒ RED.)
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 8, 'payload' => ['dl_number' => 'DL-9']],
        ]])]);   // DL-9 → [7, 8]
        Log::spy();

        $result = $this->classify('pull_request.closed', [
            'number' => 148, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'DL-9 bundled fix card#4811', 'head' => ['ref' => 'f'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(1, $move);                                  // ONLY the card#, not 7 & 8
        $this->assertSame(4811, $move[0]->payload['card_id']);
        $this->assertSame(148, $move[0]->payload['stamp_pr']);
        $this->assertArrayNotHasKey('stamp_dl', $move[0]->payload);    // the bundled foreign DL is not stamped
        // The warning names BOTH dropped DL card ids (7,8) alongside the chosen card#.
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, '7,8')
            && str_contains((string) $msg, 'card#4811'))->once();
    }

    public function test_card_token_on_a_branch_create_push_emits_started(): void
    {
        Http::fake();
        $result = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            'push',
            ['created' => true, 'ref' => 'refs/heads/feature/card#88-widget', 'repository' => ['full_name' => 'owner/repo']],
            new Actor('999'),
            'github',
            'owner/repo',
            $this->agent,
        ));

        $this->assertCount(1, $result->targets);
        $this->assertSame(88, $result->targets[0]->payload['card_id']);
        $this->assertSame('started', $result->targets[0]->payload['outcome']);
    }

    public function test_unresolved_dl_falls_through_to_a_present_card_token(): void
    {
        // FR-7 #112 step (2): DL-42 tracks no card (board has only DL-9), but the
        // PR also carries card#3410 — the resolver must NOT dead-end on the DL; it
        // falls through to the native-id path and moves card 3410.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        Log::spy();

        $result = $this->classify('pull_request.opened', ['title' => 'Fix DL-42 thing card#3410', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame(3410, $result->targets[0]->payload['card_id']);
        $this->assertSame('opened', $result->targets[0]->payload['outcome']);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains((string) $msg, 'falling through to card#3410'))->once();
    }

    public function test_unresolved_dl_with_no_card_token_warns_loudly_and_noops(): void
    {
        // FR-7 #112 step (4): DL-42 tracks no card and there is no card# fallback —
        // a high-value miss (a decision-logged-but-unstamped card). No move, but a
        // loud warning rather than a silent no-op.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        Log::spy();

        $result = $this->classify('pull_request.opened', ['title' => 'DL-42 only']);

        $this->assertSame([], $result->targets);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'DL-42') && str_contains((string) $msg, 'high-value miss'))->once();
    }

    public function test_unresolved_dl_falls_through_to_card_token_on_a_branch_create_push(): void
    {
        // FR-7 #112 fallthrough on the push (started) path.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);

        $result = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            'push',
            ['created' => true, 'ref' => 'refs/heads/feat/dl-42-card#3410-widget', 'repository' => ['full_name' => 'owner/repo']],
            new Actor('999'),
            'github',
            'owner/repo',
            $this->agent,
        ));

        $this->assertCount(1, $result->targets);
        $this->assertSame(3410, $result->targets[0]->payload['card_id']);
        $this->assertSame('started', $result->targets[0]->payload['outcome']);
    }

    public function test_conflicting_card_token_overrides_the_dl_on_a_branch_create_push(): void
    {
        // DL-218 sibling (classifyPush, SAME harm — a stage move): a branch like
        // `card-4811-guard-DL-9` where DL-9 resolves to a DIFFERENT card (7) must not
        // hijack the `started` move. The explicit card# is authoritative: move card
        // 4811, warn loudly, and do NOT stamp the foreign DL-9 (no PR on a push, so no
        // stamp_pr either). (Revert the classifier ⇒ DL-9's card 7 is targeted ⇒ RED.)
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $result = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/card-4811-guard-DL-9-reintro']);

        $this->assertCount(1, $result->targets);
        $p = $result->targets[0]->payload;
        $this->assertSame(4811, $p['card_id']);   // the explicit card#, NOT DL-9's card 7
        $this->assertSame('started', $p['outcome']);
        $this->assertArrayNotHasKey('stamp_dl', $p);   // the foreign DL-9 is not stamped
        $this->assertArrayNotHasKey('stamp_pr', $p);   // no PR on a push
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'card#4811')
            && str_contains((string) $msg, 'authoritative'))->once();
    }

    // --- DL-201 / roundtable #48: dash alias + DL-shaped boundary + near-miss warn.
    // The regex decisions are the guard (hostile-input matrix, mutation-checked):
    // reintroducing the trailing \b REDs the underscore tests; dropping the dash
    // alias REDs the card- tests; losing the leading \b REDs the discard/wildcard
    // tests. ---

    public function test_dash_card_token_on_a_branch_create_push_emits_started(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/card-3054-fix']);

        $this->assertCount(1, $r->targets);
        $this->assertSame(3054, $r->targets[0]->payload['card_id']);
        $this->assertSame('started', $r->targets[0]->payload['outcome']);
        Http::assertNothingSent();
    }

    public function test_underscore_after_id_still_correlates_no_trailing_boundary(): void
    {
        // THE roundtable-#48 hole: with a trailing \b, `_` (a word char) after the
        // digits made card#3054_fix a SILENT no-op while DL-200_fix was immune.
        // DL-shaped = both token forms tolerate a word char after the id.
        Http::fake();
        foreach (['refs/heads/feat/card-3054_fix', 'refs/heads/feat/card#3054_fix'] as $ref) {
            $r = $this->classifyPush(['created' => true, 'ref' => $ref]);
            $this->assertCount(1, $r->targets, $ref);
            $this->assertSame(3054, $r->targets[0]->payload['card_id'], $ref);
        }
    }

    public function test_embedded_card_words_do_not_correlate_leading_boundary_holds(): void
    {
        // `discard-1`, `wildcard-2`: "card" preceded by a word char is not a token.
        Http::fake();
        Log::spy();
        foreach (['refs/heads/feat/discard-1-thing', 'refs/heads/fix/wildcard-2-x'] as $ref) {
            $r = $this->classifyPush(['created' => true, 'ref' => $ref]);
            $this->assertSame([], $r->targets, $ref);
        }
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('warning');   // not a near-miss either — no \b'd "card" present
    }

    public function test_dash_card_token_at_start_of_name_and_after_non_word_char(): void
    {
        Http::fake();
        foreach (['refs/heads/card-77-bare' => 77, 'refs/heads/feat-card-88-x' => 88] as $ref => $id) {
            $r = $this->classifyPush(['created' => true, 'ref' => $ref]);
            $this->assertCount(1, $r->targets, $ref);
            $this->assertSame($id, $r->targets[0]->payload['card_id'], $ref);
        }
    }

    public function test_near_miss_card_token_in_branch_warns_and_noops(): void
    {
        // A branch that NAMES a card in a shape the token doesn't accept must fail
        // LOUD, not silent — the exact defect class (b) closes: the branch publishes,
        // the card never moves, nobody is told.
        Http::fake();
        Log::spy();   // Facade::spy() no-ops when already mocked — one spy, count totals
        $refs = ['refs/heads/feat/card_3054-fix', 'refs/heads/feat/card3054', 'refs/heads/feat/card:3054'];
        foreach ($refs as $ref) {
            $r = $this->classifyPush(['created' => true, 'ref' => $ref]);
            $this->assertSame([], $r->targets, $ref);
        }
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'near-miss'))->times(count($refs));
        Http::assertNothingSent();
    }

    public function test_token_less_branch_stays_silent_no_near_miss_spam(): void
    {
        // The warn is a NEAR-MISS detector, not an any-unlinked warn: routine
        // token-less branches (sync/, release/, plain features) and embedded
        // "card"-words followed by non-digits must not log.
        Http::fake();
        Log::spy();
        foreach ([
            'refs/heads/sync/main-to-dev-post-v0.56.0',
            'refs/heads/release/v0.57.0',
            'refs/heads/feat/scorecard_2',          // "card" not \b'd → not a near-miss
            'refs/heads/feat/card-layout-rework',   // token form but no digits → not a near-miss
        ] as $ref) {
            $r = $this->classifyPush(['created' => true, 'ref' => $ref]);
            $this->assertSame([], $r->targets, $ref);
        }
        Log::shouldNotHaveReceived('warning');
        Http::assertNothingSent();
    }

    public function test_dash_card_token_in_pr_title_correlates_and_near_miss_in_pr_warns(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.opened', ['title' => 'Fix flaky retry card-3410', 'head' => ['ref' => 'f']]);
        $this->assertCount(1, $r->targets);
        $this->assertSame(3410, $r->targets[0]->payload['card_id']);

        Log::spy();
        $miss = $this->classify('pull_request.opened', ['title' => 'Fix flaky retry card_3410', 'head' => ['ref' => 'f']]);
        $this->assertSame([], $miss->targets);
        $spaceHash = $this->classify('pull_request.opened', ['title' => 'Fixes card #3410', 'head' => ['ref' => 'f']]);
        $this->assertSame([], $spaceHash->targets);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'near-miss'))->times(2);

        $prose = $this->classify('pull_request.opened', ['title' => 'supports card 2 of the deck', 'head' => ['ref' => 'f']]);
        $this->assertSame([], $prose->targets);
        Log::shouldHaveReceived('warning')->times(2);   // still 2 — bare "card 2" prose is not a near-miss
        Http::assertNothingSent();
    }

    // --- FR #3866: the card# fallback target carries correlation-key stamp hints ---

    public function test_card_fallback_stamps_pr_number_and_url_and_no_dl_when_only_a_card_token_is_present(): void
    {
        // card# only (no DL) → stamp the PR number AND url (card#4852) so release-promote
        // and kanban's by-ref source derivation can correlate; there is no DL to stamp.
        Http::fake();
        $result = $this->classify('pull_request.opened', [
            'title' => 'Fix a thing card#3410', 'head' => ['ref' => 'f'], 'number' => 77,
            'html_url' => 'https://github.com/owner/repo/pull/77',
        ]);

        $p = $result->targets[0]->payload;
        $this->assertSame(3410, $p['card_id']);
        $this->assertSame(77, $p['stamp_pr']);
        $this->assertSame('https://github.com/owner/repo/pull/77', $p['stamp_pr_url']);
        $this->assertArrayNotHasKey('stamp_dl', $p);
    }

    public function test_card_fallback_stamps_the_sole_unresolved_dl(): void
    {
        // The core #3866 case: a card created before its DL — the DL is now in the
        // title but resolves to no card (unstamped), so we fall through to card# AND
        // stamp that single DL so the next event correlates by DL.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        $result = $this->classify('pull_request.opened', ['title' => 'DL-42 thing card#3410', 'head' => ['ref' => 'f'], 'number' => 88]);

        $p = $result->targets[0]->payload;
        $this->assertSame(3410, $p['card_id']);
        $this->assertSame('DL-42', $p['stamp_dl']);
        $this->assertSame(88, $p['stamp_pr']);
    }

    public function test_card_fallback_does_not_stamp_a_dl_when_two_or_more_are_present(): void
    {
        // Foreign-DL guard: a bundled / release-shaped PR carrying 2+ DLs must NOT
        // stamp one onto the card# card (it could be a foreign DL). pr_number still stamps.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);   // neither DL resolves
        $result = $this->classify('pull_request.opened', ['title' => 'Release DL-42 and DL-43 card#3410', 'head' => ['ref' => 'f'], 'number' => 90]);

        $p = $result->targets[0]->payload;
        $this->assertSame(3410, $p['card_id']);
        $this->assertArrayNotHasKey('stamp_dl', $p);
        $this->assertSame(90, $p['stamp_pr']);
    }

    public function test_dl_resolved_target_carries_pr_refs_but_never_stamp_dl(): void
    {
        // card#4852: a DL-only feature PR (no card#) resolves to card 5. The move target
        // now carries the PR provenance (stamp_pr + stamp_pr_url) so release-promote and
        // kanban's by-ref source derivation can correlate — but NEVER stamp_dl: the card
        // already carries the dl_number that resolved it, so re-stamping delivers nothing
        // and could poison. (Revert branch B to a bare moveTargets ⇒ no stamp_pr/
        // stamp_pr_url ⇒ RED.)
        $this->fakeBoardCards();
        $result = $this->classify('pull_request.opened', [
            'title' => 'DL-42 ship it', 'number' => 77, 'html_url' => 'https://github.com/owner/repo/pull/77',
        ]);

        $p = $result->targets[0]->payload;
        $this->assertSame(5, $p['card_id']);
        $this->assertSame(77, $p['stamp_pr']);
        $this->assertSame('https://github.com/owner/repo/pull/77', $p['stamp_pr_url']);
        $this->assertArrayNotHasKey('stamp_dl', $p);
    }

    public function test_multi_dl_title_moves_but_stamps_nothing_on_the_dl_path(): void
    {
        // card#4852 hardening (review consider): a title carrying 2+ DL tokens is
        // bundled/descriptive (release-shaped) — its OWN pr_number/pr_url are foreign
        // to the resolved card, so the DL path must not stamp them; add-if-missing at
        // the handler must not be the only poison guard. The move itself still fires
        // (pre-existing DL-resolution behavior). (Revert the sole-DL gate at the
        // DL-win branch ⇒ stamp_pr/stamp_pr_url appear ⇒ RED.)
        $this->fakeBoardCards();
        $result = $this->classify('pull_request.opened', [
            'title' => 'DL-42 hardening against the DL-218 class', 'number' => 99,
            'html_url' => 'https://github.com/owner/repo/pull/99',
        ]);

        $p = $result->targets[0]->payload;
        $this->assertSame(5, $p['card_id']);   // still moved — the gate is stamp-only
        $this->assertArrayNotHasKey('stamp_pr', $p);
        $this->assertArrayNotHasKey('stamp_pr_url', $p);
        $this->assertArrayNotHasKey('stamp_dl', $p);
    }

    public function test_bundled_dl_stamps_pr_refs_on_every_resolved_card(): void
    {
        // card#4852 + DL-148: a bundled DL resolving to N cards moves them ALL, and every
        // move target carries the PR provenance (stamp_pr + stamp_pr_url), none carries
        // stamp_dl. (Revert branch B ⇒ bare moveTargets ⇒ no stamp refs on any ⇒ RED.)
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 8, 'payload' => ['dl_number' => 'DL-9']],
        ]])]);   // DL-9 → [7, 8]

        $result = $this->classify('pull_request.closed', [
            'number' => 148, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'DL-9 bundled fix', 'head' => ['ref' => 'f'],
            'html_url' => 'https://github.com/owner/repo/pull/148',
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(2, $move);
        foreach ($move as $t) {
            $this->assertContains($t->payload['card_id'], [7, 8]);
            $this->assertSame(148, $t->payload['stamp_pr']);
            $this->assertSame('https://github.com/owner/repo/pull/148', $t->payload['stamp_pr_url']);
            $this->assertArrayNotHasKey('stamp_dl', $t->payload);
        }
    }

    public function test_push_card_fallback_stamps_the_sole_branch_dl_and_no_pr(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);   // DL unresolved
        $result = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            'push',
            ['created' => true, 'ref' => 'refs/heads/feat/DL-55-card#88-widget', 'repository' => ['full_name' => 'owner/repo']],
            new Actor('999'),
            'github',
            'owner/repo',
            $this->agent,
        ));

        $p = $result->targets[0]->payload;
        $this->assertSame(88, $p['card_id']);
        $this->assertSame('DL-55', $p['stamp_dl']);
        $this->assertArrayNotHasKey('stamp_pr', $p);   // no PR on a push
    }

    // --- DL-193: PR draft → block_reason OVERLAY (opt-in `draft_overlay`) ---

    /** Enable the draft overlay on the owner/repo mapping (scan correlation stays pinned from setUp). */
    private function enableDraftOverlay(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ], 'draft_overlay' => true]],
        ]));
    }

    public function test_converted_to_draft_emits_block_reason_set_when_opted_in(): void
    {
        $this->enableDraftOverlay();
        $this->fakeBoardCards();   // DL-42 → card 5

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-42 wip', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_block_reason', $t->handler);
        $this->assertSame('5', $t->targetId);   // card id is the target id
        $this->assertSame(['repo' => 'owner/repo', 'action' => 'set'], $t->payload);
        $this->assertSame([], $r->intents);   // machine-only
    }

    public function test_draft_overlay_prefers_the_card_token_on_a_conflict(): void
    {
        // DL-218 sibling (correlatedCardIds, LOWER harm — a block-reason marker, not a
        // stage move): a draft PR whose DL resolves to a DIFFERENT card than a present
        // card# must mark the INTENDED card# blocked, not the foreign-DL card. Same
        // conflict predicate; the overlay path stays SILENT by design (the move path
        // logs — here converted_to_draft carries no move outcome, so nothing logs).
        // (Revert the classifier ⇒ DL-9's card 7 gets the marker ⇒ RED.)
        $this->enableDraftOverlay();
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-9 wip card#4811', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_block_reason', $t->handler);
        $this->assertSame('4811', $t->targetId);   // the explicit card#, NOT DL-9's card 7
        $this->assertSame(['repo' => 'owner/repo', 'action' => 'set'], $t->payload);
        Log::shouldNotHaveReceived('warning');   // overlay path is silent (no double-log)
    }

    public function test_ready_for_review_emits_block_reason_clear_when_opted_in(): void
    {
        $this->enableDraftOverlay();
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.ready_for_review', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_block_reason', $r->targets[0]->handler);
        $this->assertSame('clear', $r->targets[0]->payload['action']);
    }

    public function test_opened_as_draft_emits_both_the_opened_move_and_the_block_reason_set(): void
    {
        // A PR born a draft: the existing `opened` move STILL fires (card → In Review),
        // and the overlay ADDS a block_reason set — two targets for the same card,
        // distinct handlers (distinct dispatch buckets: handler|debounceKey).
        $this->enableDraftOverlay();
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.opened', ['title' => 'DL-42', 'draft' => true, 'head' => ['ref' => 'f']]);

        $this->assertCount(2, $r->targets);
        $byHandler = [];
        foreach ($r->targets as $t) {
            $byHandler[$t->handler] = $t;
        }
        $this->assertArrayHasKey('kanban_move_card', $byHandler);
        $this->assertArrayHasKey('kanban_block_reason', $byHandler);
        $this->assertSame(['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'opened'], $byHandler['kanban_move_card']->payload);
        $this->assertSame(['repo' => 'owner/repo', 'action' => 'set'], $byHandler['kanban_block_reason']->payload);
    }

    public function test_opened_non_draft_emits_no_overlay_even_when_opted_in(): void
    {
        // Not a draft → only the existing `opened` move (byte-identical to today).
        $this->enableDraftOverlay();
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.opened', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_move_card', $r->targets[0]->handler);
    }

    public function test_bundled_dl_emits_one_block_reason_target_per_matching_card(): void
    {
        // A DL tracking multiple cards overlays them ALL (one-to-many, like the move
        // path) — each with its card id as a distinct target_id (no coalesce).
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50], 'draft_overlay' => true]],
        ]));
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
            ['id' => 6, 'payload' => ['dl_number' => '042']],   // same canonical 42
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],  // different DL, not matched
        ]])]);

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertCount(2, $r->targets);
        $this->assertEqualsCanonicalizing(['5', '6'], array_map(fn ($t) => $t->targetId, $r->targets));
        foreach ($r->targets as $t) {
            $this->assertSame('kanban_block_reason', $t->handler);
            $this->assertSame('set', $t->payload['action']);
        }
    }

    public function test_converted_to_draft_via_card_token_overlays_the_native_id(): void
    {
        // Overlay reuses the card# native-id fallback — no kanban read needed.
        $this->enableDraftOverlay();
        Http::fake();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'wip card#3410', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_block_reason', $r->targets[0]->handler);
        $this->assertSame('3410', $r->targets[0]->targetId);
        Http::assertNothingSent();   // native-id selection needs no classify-time read
    }

    public function test_converted_to_draft_is_noop_when_draft_overlay_off(): void
    {
        // Default config (setUp) has no draft_overlay → the draft actions are IGNORED,
        // byte-identical to today's behavior (they weren't acted on at all).
        Http::fake();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertSame([], $r->targets);
        Http::assertNothingSent();   // never even correlated
    }

    public function test_ready_for_review_is_noop_when_draft_overlay_off(): void
    {
        Http::fake();

        $r = $this->classify('pull_request.ready_for_review', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_draft_overlay_no_card_token_is_noop(): void
    {
        $this->enableDraftOverlay();
        Http::fake();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'no card reference']);

        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_draft_overlay_unmapped_repo_is_noop(): void
    {
        $this->enableDraftOverlay();
        Http::fake();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-42'], repo: 'other/repo');

        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    // --- DL-195: Won't-Do-revival (reopened → distinct `reopened` move outcome) ---

    private function enableRevive(bool $withDependabot = false): void
    {
        $mapping = ['board_id' => 8, 'stages' => [
            'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 77,
        ], 'revive_on_reopen' => true];
        if ($withDependabot) {
            $mapping['create_dependabot_cards'] = true;
        }
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => $mapping],
        ]));
    }

    public function test_reopened_emits_distinct_reopened_outcome_when_revive_on(): void
    {
        $this->enableRevive();
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.reopened', ['title' => 'feat: DL-42 ship it', 'head' => ['ref' => 'feat/x']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_move_card', $r->targets[0]->handler);
        $this->assertSame('reopened', $r->targets[0]->payload['outcome']);
    }

    public function test_reopened_stays_opened_outcome_when_revive_off(): void
    {
        // setUp's config has no revive_on_reopen → a reopened PR is byte-identical to
        // today: it collapses to the `opened` outcome.
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.reopened', ['title' => 'feat: DL-42 ship it', 'head' => ['ref' => 'feat/x']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('opened', $r->targets[0]->payload['outcome']);
    }

    public function test_reopened_dependabot_pr_stays_opened_never_reopened(): void
    {
        // SHOULD-FIX 1 regression guard: a reopened dependabot PR (create_dependabot_cards
        // + revive_on_reopen both on) must keep the `opened` outcome on the DEPENDABOT
        // target — dependabot cards archive on close, never park in closed_unmerged, so
        // revival never applies. A `reopened` outcome here would null-stage the dependabot
        // handler and neither move nor create the card.
        $this->enableRevive(withDependabot: true);
        Http::fake();

        $r = $this->classify('pull_request.reopened', [
            'title' => 'chore(deps): Bump x from 1 to 2',
            'number' => 77,
            'head' => ['ref' => 'dependabot/composer/x-2.0'],
            'html_url' => 'https://github.com/owner/repo/pull/77',
        ]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_dependabot_card', $r->targets[0]->handler);
        $this->assertSame('opened', $r->targets[0]->payload['outcome']);
    }
}
