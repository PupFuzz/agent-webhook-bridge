<?php

namespace Tests\Feature\Classifiers;

use App\Bridge\Classifiers\GitHubPrCardMoveClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Support\AgentConfig;
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

    public function test_dl_token_wins_over_card_token_and_the_ignored_token_is_logged(): void
    {
        // FR-7 precedence (framework v0.2.229): DL-NNN is the ratified, more-specific
        // contract; when both appear the card# is ignored LOUDLY (degraded-states-loud).
        $this->fakeBoardCards();
        Log::spy();

        $result = $this->classify('pull_request.opened', ['title' => 'Fix DL-9 thing card#3410', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame(7, $result->targets[0]->payload['card_id']);   // DL-9 correlates to card 7
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains((string) $msg, 'card#3410'))->once();
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

    // --- FR #3866: the card# fallback target carries correlation-key stamp hints ---

    public function test_card_fallback_stamps_pr_number_and_no_dl_when_only_a_card_token_is_present(): void
    {
        // card# only (no DL) → stamp the PR number so release-promote can correlate;
        // there is no DL to stamp.
        Http::fake();
        $result = $this->classify('pull_request.opened', ['title' => 'Fix a thing card#3410', 'head' => ['ref' => 'f'], 'number' => 77]);

        $p = $result->targets[0]->payload;
        $this->assertSame(3410, $p['card_id']);
        $this->assertSame(77, $p['stamp_pr']);
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

    public function test_dl_resolved_target_carries_no_stamp_refs(): void
    {
        // Finding #1: a DL-resolved card already carries dl_number, so stamping it
        // delivers nothing — and threading pr_number there would poison a feature
        // card from a release PR whose title names its DL. The DL path stamps NOTHING.
        $this->fakeBoardCards();
        $result = $this->classify('pull_request.opened', ['title' => 'DL-42 ship it', 'number' => 77]);

        $p = $result->targets[0]->payload;
        $this->assertSame(5, $p['card_id']);
        $this->assertArrayNotHasKey('stamp_dl', $p);
        $this->assertArrayNotHasKey('stamp_pr', $p);
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
