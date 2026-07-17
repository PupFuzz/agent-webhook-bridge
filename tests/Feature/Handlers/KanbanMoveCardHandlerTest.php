<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class KanbanMoveCardHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/mvcard-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeWriteback(array $stages = ['merged' => 52], array $extra = []): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => $stages] + $extra],
        ]));
    }

    private function writeToken(): void
    {
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
    }

    private function handle(array $payload): void
    {
        (new KanbanMoveCardHandler)->handle(
            ReactionTarget::make('kanban_move_card', '5', payload: $payload),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    private function payload(array $over = []): array
    {
        return array_merge(['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'merged'], $over);
    }

    public function test_happy_path_moves_card_to_mapped_stage(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])   // GET
                ->push(['data' => ['id' => 5]]),                                                // PATCH
        ]);

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r['task'] === ['workflow_stage_id' => 52]
            && $r->hasHeader('Authorization', 'Bearer wb-token'));
    }

    public function test_already_in_target_stage_is_idempotent_noop(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52]])]);

        $this->handle($this->payload());

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // no move
    }

    public function test_card_on_wrong_board_is_refused_no_move(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]])]);

        $this->handle($this->payload());   // refused (belongs-to-board guard) — no throw, no move

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_no_mapping_for_repo_is_noop(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake();

        $this->handle($this->payload(['repo' => 'other/repo']));

        Http::assertNothingSent();   // never even called the API
    }

    public function test_no_stage_for_outcome_is_noop(): void
    {
        $this->writeWriteback(['merged' => 52]);   // only 'merged' mapped
        $this->writeToken();
        Http::fake();

        $this->handle($this->payload(['outcome' => 'closed_unmerged']));

        Http::assertNothingSent();
    }

    public function test_writeback_disabled_is_noop(): void
    {
        // No writeback.json written.
        Http::fake();
        $this->handle($this->payload());
        Http::assertNothingSent();
    }

    public function test_missing_token_throws_for_redelivery(): void
    {
        // Transient/operator-fixable: throw → 5xx → redelivery succeeds once the
        // operator places the token (mirrors the HMAC-secret fail-closed).
        $this->writeWriteback();
        // No token written.
        Http::fake();
        $this->expectException(ConfigException::class);   // propagates → 5xx, same as before
        $this->handle($this->payload());
    }

    public function test_bad_payload_card_id_is_permanent_noop_not_a_throw(): void
    {
        // A malformed payload is a deterministic classifier bug — permanent, so it
        // must NOT throw (a durable throw would 5xx-storm an event that fails
        // identically every redelivery). Log + no-op.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake();

        $this->handle($this->payload(['card_id' => 'not-a-number']));   // no exception

        Http::assertNothingSent();
    }

    public function test_kanban_4xx_on_get_is_permanent_noop(): void
    {
        // A deleted card / bad id → kanban 404 is PERMANENT: log + no-op, never
        // 5xx-storm. (No move attempted.)
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['error' => 'not found'], 404)]);

        $this->handle($this->payload());   // no exception

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_kanban_4xx_on_move_is_permanent_noop(): void
    {
        // e.g. the mapped stage isn't on the card's board (config typo) → kanban
        // 422/404 on the PATCH is PERMANENT: log + no-op, not a 5xx-storm.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])   // GET ok
                ->push(['error' => 'invalid stage'], 422),                                      // PATCH 4xx
        ]);

        $this->handle($this->payload());   // no exception

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');   // move attempted; 4xx swallowed
    }

    public function test_move_4xx_logs_the_server_body_and_drops_the_guessed_cause(): void
    {
        // card#4409: a 4xx move refusal must hand over what kanban actually said (the
        // response body) instead of asserting a config cause the handler never checked.
        // The real DL-204 incident was a 403 authz refusal mislabelled as a
        // writeback.json stage-map typo — status alone couldn't tell them apart.
        $this->writeWriteback();
        $this->writeToken();
        Log::spy();
        Http::fake([
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [['id' => 49, 'position' => 3], ['id' => 52, 'position' => 5]]]]]]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])   // GET ok
                ->push(['message' => 'you are not authorized to move this card'], 403),         // PATCH 403 authz
        ]);

        $this->handle($this->payload());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'kanban refused the move')
            && ! str_contains($msg, 'check the writeback.json stage')
            && $ctx['status'] === 403
            && $ctx['board'] === 8
            && str_contains($ctx['body'], 'not authorized to move this card'));
    }

    public function test_stamp_4xx_logs_the_server_body_and_drops_the_custom_field_guess(): void
    {
        // card#4409: the stamp refusal previously asserted "the board likely lacks the
        // dl_number/pr_number custom field" — a cause it never verified. The card is
        // already at the target stage (self-heal path), so only the stamp PATCH fires.
        $this->writeWriteback();
        $this->writeToken();
        Log::spy();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52]])   // GET: already at target
                ->push(['message' => 'forbidden: token cannot write custom fields'], 403),      // PATCH stamp 403
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'stamp refused by kanban')
            && ! str_contains($msg, 'board likely lacks')
            && $ctx['status'] === 403
            && str_contains($ctx['body'], 'cannot write custom fields'));
    }

    public function test_move_4xx_scrubs_a_credential_echoed_in_the_body(): void
    {
        // A kanban error body that echoes the request could carry the writeback token;
        // the refusal log must scrub it before persisting.
        $this->writeWriteback();
        $this->writeToken();
        Log::spy();
        Http::fake([
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [['id' => 49, 'position' => 3], ['id' => 52, 'position' => 5]]]]]]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])
                ->push(['message' => 'denied', 'echo' => ['token' => 'wb-SECRET-TOKEN-abc123']], 403),
        ]);

        $this->handle($this->payload());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'kanban refused the move')
            && ! str_contains($ctx['body'], 'wb-SECRET-TOKEN-abc123')
            && str_contains($ctx['body'], '[REDACTED]'));
    }

    public function test_kanban_5xx_is_transient_and_throws(): void
    {
        // A kanban 5xx / timeout is TRANSIENT: throw → 5xx → redelivery retries.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response('upstream error', 503)]);

        $this->expectException(RequestException::class);
        $this->handle($this->payload());
    }

    public function test_started_promotes_card_from_an_allowed_backlog_stage(): void
    {
        // DL-160: branch-create push → `started`. The card sits in Backlog (46),
        // an allowed promote-from stage → move it to In Progress (49).
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47]]);
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 46]])   // GET (Backlog)
                ->push(['data' => ['id' => 5]]),                                                // PATCH
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r['task'] === ['workflow_stage_id' => 49]);
    }

    public function test_started_does_not_regress_an_already_progressed_card(): void
    {
        // The card is In Review (50), NOT a promote-from stage → no move (a
        // re-created/force-pushed old branch must not drag it backward).
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47]]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 50]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // no regression
    }

    public function test_started_is_refused_when_no_promote_from_stages_configured(): void
    {
        // Fail-closed: with no `started_from_stages` we can't know what's safe to
        // promote from, so a `started` move is refused (log + no-op).
        $this->writeWriteback(['started' => 49]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 46]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_started_already_in_progress_is_idempotent_noop(): void
    {
        // Already at the target In-Progress stage → idempotent no-op (guard never reached).
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47]]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    // --- Contract PR #113: Held promote-from + pinned-card opt-out on `started` ---

    public function test_started_promotes_a_held_card_when_held_is_in_promote_from(): void
    {
        // The Held-promote default is delivered by carrying the Held stage (51) in
        // started_from_stages — the mechanism is unchanged, only the config default.
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47, 51]]);
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51]])   // GET (Held)
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 49]);
    }

    public function test_started_refused_when_card_has_a_block_reason(): void
    {
        // Pinned opt-out: a non-empty block_reason blocks the promotion regardless
        // of the card being in an allowed promote-from stage.
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47, 51]]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => [
            'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'block_reason' => 'waiting on upstream',
        ]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_started_refused_when_card_has_no_automove_tag(): void
    {
        // Pinned opt-out via the `no-automove` tag — a human-pinned card is never
        // auto-promoted even from an allowed stage.
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47, 51]]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => [
            'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['triaged', 'no-automove'],
        ]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_started_promotes_a_held_card_with_non_pinning_tags_and_blank_block_reason(): void
    {
        // Boundary: a whitespace-only block_reason must NOT pin (the trim guard), and a
        // tag list without `no-automove` must NOT pin (the exact-match guard). One case
        // pins both against a regression to "any non-null string" / substring/any-tag.
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47, 51]]);
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'block_reason' => '   ', 'tags' => ['triaged']]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 49]);
    }

    public function test_payload_board_id_is_ignored_config_is_authoritative(): void
    {
        // A payload board_id that disagrees with config must NOT change the
        // belongs-to-board decision — the card's real board (8) matches the
        // CONFIG mapping (8), so the move proceeds despite payload board_id 999.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle($this->payload(['board_id' => 999]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 52]);
    }

    // --- #2935: no-regression guard for the four PR-driven outcomes ---

    private function writeAllOutcomes(): void
    {
        $this->writeWriteback(['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49]);
        $this->writeToken();
    }

    /** Board-8 order for the guard: In-Progress 49 < In-Review 50 < Shipped 52 < Released 53. */
    private function fakeStageOrderAndCard(int $currentStage): void
    {
        Http::fake([
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['id' => 11, 'stages' => [
                ['id' => 49, 'position' => 3072.0],
                ['id' => 50, 'position' => 4096.0],
                ['id' => 52, 'position' => 5120.0],
                ['id' => 53, 'position' => 6144.0],
            ]]]]]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => $currentStage]]),
        ]);
    }

    public function test_opened_does_not_regress_a_released_card(): void
    {
        // The core reported bug: a release PR whose title carries the card's DL-NNN
        // (or a redelivered `opened`) fires opened→In-Review on a card already at
        // Released — must be refused (no backward move), not silently applied.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(53);   // Released

        $this->handle($this->payload(['outcome' => 'opened']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_opened_still_promotes_a_card_forward(): void
    {
        // The guard must NOT block a legitimate forward move.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(49);   // In-Progress → In-Review (50)

        $this->handle($this->payload(['outcome' => 'opened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 50]);
    }

    public function test_merged_does_not_regress_a_released_card(): void
    {
        // A redelivered `merged` (PR merged to a non-main base) on an already-Released
        // card would drag Released(53)→Shipped(52) — refused.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(53);

        $this->handle($this->payload(['outcome' => 'merged']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_closed_unmerged_returns_an_in_review_card_to_in_progress(): void
    {
        // closed_unmerged is the ONE legitimately-backward outcome: an abandoned PR
        // returns its In-Review card to In-Progress. The guard must allow it.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(50);   // In-Review → In-Progress (49)

        $this->handle($this->payload(['outcome' => 'closed_unmerged']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 49]);
    }

    public function test_closed_unmerged_does_not_resurrect_a_released_card(): void
    {
        // ...but a stale PR closing long after the card shipped/released must NOT
        // pull it back to In-Progress (current stage is at/past the terminal floor).
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(53);   // Released

        $this->handle($this->payload(['outcome' => 'closed_unmerged']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_no_regression_guard_fails_open_when_stage_order_is_unavailable(): void
    {
        // The guard is a safety net layered on the existing behavior — it must never
        // BREAK the writeback. When the board order can't be read, the move proceeds.
        $this->writeAllOutcomes();
        Http::fake([
            '*/boards/8/preload.json' => Http::response('upstream error', 500),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 53]]),
        ]);

        $this->handle($this->payload(['outcome' => 'opened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 50]);
    }

    public function test_board_stage_order_preload_is_read_once_across_cards_on_one_instance(): void
    {
        // #3575: a bundled PR/DL moving N cards on the same board dispatches N
        // targets through the SAME singleton handler in one request. The
        // no-regression guard's `/preload.json` read must be memoized to one call
        // per board, not repeated per card — while every card still moves.
        $this->writeAllOutcomes();
        Http::fake([
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['id' => 11, 'stages' => [
                ['id' => 49, 'position' => 3072.0],
                ['id' => 50, 'position' => 4096.0],
                ['id' => 52, 'position' => 5120.0],
                ['id' => 53, 'position' => 6144.0],
            ]]]]]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]]),
            '*/tasks/6.json' => Http::response(['data' => ['id' => 6, 'board_id' => 8, 'workflow_stage_id' => 49]]),
        ]);

        $handler = new KanbanMoveCardHandler;
        $agent = AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);
        $handler->handle(ReactionTarget::make('kanban_move_card', '5', payload: $this->payload(['card_id' => 5, 'outcome' => 'opened'])), $agent);
        $handler->handle(ReactionTarget::make('kanban_move_card', '6', payload: $this->payload(['card_id' => 6, 'outcome' => 'opened'])), $agent);

        $preloadReads = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/preload.json'))
            ->count();
        $this->assertSame(1, $preloadReads, 'the board stage-order preload must be read once, not once per card');

        // Both cards still moved forward (49 In-Progress → 50 In-Review) — and to
        // their OWN card URLs, not the same one twice.
        $movedCards = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'PATCH' && $pair[0]['task'] === ['workflow_stage_id' => 50])
            ->map(fn ($pair) => $pair[0]->url())
            ->sort()
            ->values()
            ->all();
        $this->assertCount(2, $movedCards);
        $this->assertStringContainsString('/tasks/5', $movedCards[0]);
        $this->assertStringContainsString('/tasks/6', $movedCards[1]);
    }

    // --- FR-4: writeback.alert_channel (loud per-event signal on a permanent move-failure) ---

    private const ALERT_URL = 'http://127.0.0.1:9931/';

    private function writeWritebackWithAlert(array $stages = ['merged' => 52], array $extra = []): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => $stages] + $extra],
        ]));
    }

    private function isAlertPush(Request $r): bool
    {
        return $r->method() === 'POST' && str_starts_with($r->url(), self::ALERT_URL);
    }

    public function test_alert_channel_pushes_one_signal_on_a_warning_branch(): void
    {
        // A permanent move-failure on a Log::warning branch (card not on mapped
        // board) emits exactly one loud signal to the configured alert channel.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());   // card on wrong board → refused

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'card_not_on_mapped_board'
            && $r['repo'] === 'owner/repo'
            && $r['outcome'] === 'merged'
            && $r['card_id'] === 5);
        // The push is ADDITIVE to the durable log — the warning fires regardless.
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg) => str_contains($msg, 'not on the mapped board'));
    }

    public function test_alert_channel_signals_card_id_branch_with_non_scalar_repo_without_throwing(): void
    {
        // Branch #1 (card_id not int) passes the best-available repo/outcome — which
        // at that point are un-validated payload values. A non-string (e.g. array)
        // repo must NOT throw an "Array to string conversion" out of the handler.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle(['card_id' => 'nope', 'repo' => ['not' => 'a string'], 'outcome' => 'merged']);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'card_id_not_int'
            && $r['repo'] === ''           // non-string coerced to empty, not a crash
            && $r['card_id'] === null);
    }

    public function test_alert_channel_unset_pushes_nothing(): void
    {
        // No alert_channel ⇒ log-only (unchanged behavior).
        $this->writeWriteback();   // no alert_channel key
        $this->writeToken();
        Http::fake([
            '*://127.0.0.1:*/*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '127.0.0.1'));
    }

    public function test_alert_channel_silent_on_an_info_branch(): void
    {
        // The Log::info "not tracked" branches (#4 no mapping / #5 no stage) stay
        // QUIET — no alert even with a channel configured.
        $this->writeWritebackWithAlert(['merged' => 52]);   // only 'merged' mapped
        $this->writeToken();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle($this->payload(['outcome' => 'closed_unmerged']));   // no stage for outcome (info branch)

        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    public function test_alert_channel_dedups_repeated_identical_signatures(): void
    {
        // The SAME (repo, outcome, reason) firing on N events alerts exactly once
        // (the O_EXCL dedup marker).
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());
        $this->handle($this->payload());
        $this->handle($this->payload());

        $alertPushes = collect(Http::recorded())->filter(fn ($pair) => $this->isAlertPush($pair[0]))->count();
        $this->assertSame(1, $alertPushes, 'an identical (repo, outcome, reason) must alert exactly once');
    }

    public function test_alert_channel_failure_does_not_throw_out_of_the_handler(): void
    {
        // Best-effort: the alert push failing (HTTP 500 / connection refused) must
        // never throw out of the handler — an unmovable card must not 5xx-storm.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response('channel down', 500),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());   // no exception escapes

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r));   // push WAS attempted
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // move still a no-op
    }

    public function test_alert_channel_failed_push_releases_the_dedup_marker_for_a_retry(): void
    {
        // A FAILED first push must not permanently silence the signature — the
        // marker is released so a later redelivery re-attempts (claim-before-push
        // can't turn one dropped packet into forever-silence). First push 500s →
        // marker released; second delivery → a second push is attempted.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::sequence()
                ->push('channel down', 500)        // first attempt fails
                ->push(['ok' => true], 200),       // second attempt succeeds
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());
        $this->handle($this->payload());

        $alertPushes = collect(Http::recorded())->filter(fn ($pair) => $this->isAlertPush($pair[0]))->count();
        $this->assertSame(2, $alertPushes, 'a failed first push must re-arm the signature for the next delivery');
    }

    // --- FR #3866: stamp correlation refs (dl_number / pr_number) add-if-missing ---

    public function test_card_fallback_move_stamps_missing_dl_and_pr(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['origin' => 'preemptive']]])  // GET
                ->push(['data' => ['id' => 5]])   // PATCH move
                ->push(['data' => ['id' => 5]]),  // PATCH stamp
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        // dl_number stored zero-padded (DL-%04d); pr_number as an int.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['task'] === ['payload' => ['dl_number' => 'DL-0042', 'pr_number' => 77]]);
    }

    public function test_stamp_is_add_if_missing_never_overwrites_an_existing_dl(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['dl_number' => 'DL-0099']]])  // GET: dl already set
                ->push(['data' => ['id' => 5]])   // move
                ->push(['data' => ['id' => 5]]),  // stamp (pr only)
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        // only pr_number stamped — the existing dl_number is NOT overwritten.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['task'] === ['payload' => ['pr_number' => 77]]);
    }

    public function test_stamp_is_add_if_missing_stamps_dl_when_only_pr_present(): void
    {
        // Inverse of the dl-present case: pr_number already set, dl_number absent →
        // stamp only dl_number, leaving the existing pr_number untouched.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => 77]]])  // GET: pr set, dl absent
                ->push(['data' => ['id' => 5]])   // move
                ->push(['data' => ['id' => 5]]),  // stamp (dl only)
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['task'] === ['payload' => ['dl_number' => 'DL-0042']]);
    }

    public function test_no_stamp_patch_when_card_already_carries_both_refs(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['dl_number' => 'DL-0042', 'pr_number' => 77]]])
                ->push(['data' => ['id' => 5]]),  // move only
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && isset($r['task']['payload']));
    }

    public function test_already_in_stage_self_heals_the_stamp_without_moving(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => ['origin' => 'x']]])  // GET: already in target stage 52
                ->push(['data' => ['id' => 5]]),  // stamp PATCH
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        Http::assertNotSent(fn (Request $r) => isset($r['task']['workflow_stage_id']));  // no move
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['task'] === ['payload' => ['dl_number' => 'DL-0042', 'pr_number' => 77]]);
    }

    public function test_stamp_permanent_4xx_is_swallowed_move_still_succeeds(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => []]])  // GET
                ->push(['data' => ['id' => 5]])                    // move OK
                ->push(['message' => 'unknown field'], 422),      // stamp 4xx — permanent
        ]);

        // Must NOT throw: a permanent stamp failure is log + no-op (the move succeeded).
        $this->handle($this->payload(['stamp_dl' => 'DL-42']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && isset($r['task']['payload']));  // stamp was attempted
    }

    public function test_stamp_transient_5xx_propagates_for_redelivery(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => []]])  // GET
                ->push(['data' => ['id' => 5]])       // move OK
                ->push(['error' => 'boom'], 500),     // stamp 5xx — transient
        ]);

        // A transient stamp failure propagates → 5xx → redelivery re-stamps (idempotent).
        $this->expectException(RequestException::class);
        $this->handle($this->payload(['stamp_dl' => 'DL-42']));
    }

    public function test_move_without_stamp_hints_sends_no_payload_patch(): void
    {
        // A DL-resolved move carries no stamp_dl/stamp_pr — stays column-only.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => []]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle($this->payload());

        Http::assertNotSent(fn (Request $r) => isset($r['task']['payload']));
    }

    // --- DL-194: auto-unpark a parked card on branch-cut (started + unpark_from_stages) ---

    /**
     * A `started` move from an unpark stage (51) to In-Progress (49). A single
     * non-sequence fake: GET returns the card at 51 (with the given pin signals),
     * the PATCH move returns 200. No re-GET, so this serves repeated deliveries too.
     *
     * @param  array<string, mixed>  $cardData  extra card fields (block_reason / tags)
     */
    private function fakeUnparkCard(array $cardData = [], bool $withAlert = true): void
    {
        $fakes = [
            '*/tasks/5.json' => Http::response(['data' => array_merge(
                ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51], $cardData
            )]),
        ];
        if ($withAlert) {
            $fakes = [self::ALERT_URL.'*' => Http::response(['ok' => true])] + $fakes;
        }
        Http::fake($fakes);
    }

    /** unpark_from_stages=[51], started stage 49; started_from_stages disjoint. */
    private function unparkExtra(array $holdMarkerTags = [], array $over = []): array
    {
        return array_merge([
            'started_from_stages' => [46, 47],
            'unpark_from_stages' => [51],
            'hold_marker_tags' => $holdMarkerTags,
        ], $over);
    }

    private function alertPushCount(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => $this->isAlertPush($pair[0]))->count();
    }

    public function test_unpark_moves_a_no_automove_pinned_card_and_alerts(): void
    {
        // Row 1: a `no-automove` tag is a real pin — the move is applied anyway
        // (DL-194 reversal for the unpark stage) and the override alerts.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['tags' => ['triaged', 'no-automove']]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 49]);
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_auto_unparked'
            && $r['reason'] === 'auto_unparked'
            && $r['repo'] === 'owner/repo'
            && $r['card_id'] === 5
            && $r['from_stage'] === 51);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'no_automove');
    }

    public function test_unpark_moves_a_human_block_reason_card_and_alerts(): void
    {
        // Row 2 (BLOCKER-round-1 fix): a human block_reason (≠ the draft sentinel)
        // ALWAYS alerts, even with hold_marker_tags configured.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra(['gate']));
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['block_reason' => 'waiting on upstream']);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 49]);
        $this->assertSame(1, $this->alertPushCount());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'block_reason');
    }

    public function test_unpark_moves_a_configured_hold_tag_card_and_alerts(): void
    {
        // Row 3: a card carrying a configured hold tag (e.g. `gate`) alerts.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra(['gate']));
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['tags' => ['gate']]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(1, $this->alertPushCount());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'hold_tag');
    }

    public function test_unpark_draft_only_card_moves_without_alerting_hold_tags_configured(): void
    {
        // Row 4: block_reason == the benign draft sentinel, no other signal,
        // hold_marker_tags configured → moves, no alert (automated draft-park).
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra(['gate']));
        $this->writeToken();
        $this->fakeUnparkCard(['block_reason' => 'PR is in draft']);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }

    public function test_unpark_draft_only_card_moves_without_alerting_no_hold_tags(): void
    {
        // Row 5: draft sentinel, hold_marker_tags empty → moves, no alert (provably
        // an automated draft-park).
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        $this->fakeUnparkCard(['block_reason' => 'PR is in draft']);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }

    public function test_unpark_bare_park_alerts_failsafe_when_no_hold_tags_configured(): void
    {
        // Row 6 (fail-safe): a card with NO recognized pin signal, hold_marker_tags
        // empty → moves and alerts (can't discriminate → an extra alert beats a miss).
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['tags' => ['triaged']]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(1, $this->alertPushCount());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'failsafe');
    }

    public function test_unpark_bare_park_stays_quiet_when_hold_tags_configured(): void
    {
        // Row 7: a bare park, hold_marker_tags configured → the operator declared
        // their marker, so a card without it is trusted → moves, no alert.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra(['gate']));
        $this->writeToken();
        $this->fakeUnparkCard(['tags' => ['triaged']]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }

    public function test_started_pinned_card_in_a_started_from_stage_still_refused_dl178(): void
    {
        // Row 8 (DL-178 preserved): a pinned card in a started_from_stages stage that
        // is NOT an unpark stage is still refused — the reversal is scoped to unpark.
        $this->writeWritebackWithAlert(['started' => 49], [
            'started_from_stages' => [46, 47, 51],
            'unpark_from_stages' => [52],   // disjoint; the card at 51 is NOT an unpark stage
        ]);
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'block_reason' => 'parked']]),
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // DL-178 refuse, no move
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'pinned_no_automove');
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r) && $r['type'] === 'writeback_auto_unparked');
    }

    public function test_unpark_alert_not_sent_when_the_move_4xx_noops(): void
    {
        // The alert is emitted only AFTER a confirmed move. A 4xx move-refusal
        // no-ops → no auto-unpark alert.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['no-automove']]])   // GET
                ->push(['error' => 'invalid stage'], 422),                                                                // PATCH 4xx
        ]);

        $this->handle($this->payload(['outcome' => 'started']));   // no throw

        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r) && $r['type'] === 'writeback_auto_unparked');
    }

    public function test_unpark_durable_log_fires_even_with_no_alert_channel(): void
    {
        // The durable Log::warning records the override even when no alert channel is
        // configured (log = durable record; push = additive live wake).
        $this->writeWriteback(['started' => 49], $this->unparkExtra());   // NO alert_channel
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['tags' => ['no-automove']], withAlert: false);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'no_automove');
    }

    public function test_unpark_durable_log_fires_even_when_the_alert_push_is_down(): void
    {
        // Alert channel configured but the push 500s — the move still succeeds, the
        // durable log still fires, and nothing throws out of the handler.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response('channel down', 500),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['no-automove']]]),
        ]);

        $this->handle($this->payload(['outcome' => 'started']));   // no throw

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r));   // push attempted
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked'));
    }

    public function test_reparked_card_re_alerts_on_a_second_unpark_no_dedup(): void
    {
        // A human re-parks the card (moves it back into the unpark stage), a fresh
        // branch-cut fires `started` again → a second alert (no per-card dedup).
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        $this->fakeUnparkCard(['tags' => ['no-automove']]);   // non-sequence: every GET returns stage 51

        $this->handle($this->payload(['outcome' => 'started']));
        $this->handle($this->payload(['outcome' => 'started']));

        $this->assertSame(2, $this->alertPushCount());   // one per successful unpark, no dedup
    }

    public function test_redelivery_while_already_in_progress_does_not_re_alert(): void
    {
        // A partial-failure redelivery re-runs the handler, but the card is already at
        // the target In-Progress stage → the idempotent short-circuit returns before
        // the `started`/alert block → no second alert.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'tags' => ['no-automove']]]),   // already at target 49
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && isset($r['task']['workflow_stage_id']));   // no move
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));   // no alert
    }

    public function test_notify_unpark_pushes_once_per_distinct_card_no_dedup(): void
    {
        // notifyUnpark has NO dedup — two distinct cards unparked → two pushes, each
        // carrying the writeback_auto_unparked type.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['no-automove']]]),
            '*/tasks/6.json' => Http::response(['data' => ['id' => 6, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['no-automove']]]),
        ]);

        $this->handle($this->payload(['card_id' => 5, 'outcome' => 'started']));
        $this->handle($this->payload(['card_id' => 6, 'outcome' => 'started']));

        $pushes = collect(Http::recorded())
            ->filter(fn ($pair) => $this->isAlertPush($pair[0]) && $pair[0]['type'] === 'writeback_auto_unparked')
            ->map(fn ($pair) => $pair[0]['card_id'])
            ->sort()->values()->all();
        $this->assertSame([5, 6], $pushes);
    }

    // --- DL-195: Won't-Do-revival (reopened → revive from the abandon stage) ---

    /**
     * closed_unmerged parks in Won't-Do (77), which sits AFTER In-Review (50) in stage
     * order, so a reopen revival is a backward move the DL-163 guard refuses without
     * DL-195. revive_on_reopen on; hold_marker_tags per arg.
     */
    private function writeReviveConfig(array $holdMarkerTags = [], bool $withAlert = false): void
    {
        $stages = ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 77];
        $extra = ['revive_on_reopen' => true, 'hold_marker_tags' => $holdMarkerTags];
        $withAlert
            ? $this->writeWritebackWithAlert($stages, $extra)
            : $this->writeWriteback($stages, $extra);
        $this->writeToken();
    }

    /** Board-8 order with Won't-Do (77) after In-Review; card at $currentStage. */
    private function fakeReviveStageOrderAndCard(int $currentStage, array $cardData = [], bool $withAlert = false): void
    {
        $fakes = [
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['id' => 11, 'stages' => [
                ['id' => 49, 'position' => 3072.0],
                ['id' => 50, 'position' => 4096.0],
                ['id' => 52, 'position' => 5120.0],
                ['id' => 53, 'position' => 6144.0],
                ['id' => 77, 'position' => 7168.0],   // Won't-Do, AFTER In-Review
            ]]]]]),
            '*/tasks/5.json' => Http::response(['data' => array_merge(
                ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => $currentStage], $cardData
            )]),
        ];
        if ($withAlert) {
            $fakes = [self::ALERT_URL.'*' => Http::response(['ok' => true])] + $fakes;
        }
        Http::fake($fakes);
    }

    public function test_reopened_revives_a_card_from_the_abandon_stage(): void
    {
        // The core hole: a reopened PR whose card is parked in Won't-Do (77) is
        // revived back to In-Review (50) — the backward move the DL-163 guard refuses.
        $this->writeReviveConfig();
        $this->fakeReviveStageOrderAndCard(77);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 50]);
    }

    public function test_reopened_on_a_non_abandoned_card_is_forward_only_like_opened(): void
    {
        // A reopen of a card NOT in the abandon stage behaves exactly like `opened`:
        // a forward move In-Progress(49) → In-Review(50) is allowed, no revival.
        $this->writeReviveConfig();
        $this->fakeReviveStageOrderAndCard(49);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 50]);
    }

    public function test_reopened_does_not_revive_a_terminal_card(): void
    {
        // Terminal protection: a card at Released (53) is NOT in the abandon stage, so
        // a (stale) reopen targeting In-Review(50) is a backward move → refused.
        $this->writeReviveConfig();
        $this->fakeReviveStageOrderAndCard(53);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_reopened_redelivery_after_revival_no_double_move(): void
    {
        // A redelivered reopen after the revival: the card already sits at In-Review(50),
        // so the idempotent already-in-stage short-circuit no-ops before the guard.
        $this->writeReviveConfig();
        $this->fakeReviveStageOrderAndCard(50);   // already revived

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_reopened_pinned_card_is_revived_and_alerts(): void
    {
        // A human-pinned Won't-Do card is revived anyway (operator chose override) and
        // the override alerts — the revived_on_reopen signal, symmetric with unpark.
        $this->writeReviveConfig(withAlert: true);
        Log::spy();
        $this->fakeReviveStageOrderAndCard(77, ['tags' => ['no-automove']], withAlert: true);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 50]);
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_revived_on_reopen'
            && $r['reason'] === 'revived_on_reopen'
            && $r['repo'] === 'owner/repo'
            && $r['card_id'] === 5
            && $r['from_stage'] === 77);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'revived a card') && $ctx['hold_signal'] === 'no_automove');
    }

    public function test_reopened_bare_park_alerts_failsafe_when_no_hold_tags(): void
    {
        // A bare Won't-Do park (no recognized pin signal), no hold_marker_tags → the
        // fail-safe alerts on every revival (an extra alert beats a missed override).
        $this->writeReviveConfig(withAlert: true);
        Log::spy();
        $this->fakeReviveStageOrderAndCard(77, ['tags' => ['triaged']], withAlert: true);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(1, $this->alertPushCount());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'revived a card') && $ctx['hold_signal'] === 'failsafe');
    }

    public function test_reopened_bare_park_stays_quiet_when_hold_tags_configured(): void
    {
        // A bare park with hold_marker_tags declared: the operator declared their
        // marker, so a card without it is trusted → revived, no alert.
        $this->writeReviveConfig(['gate'], withAlert: true);
        $this->fakeReviveStageOrderAndCard(77, ['tags' => ['triaged']], withAlert: true);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }

    public function test_reopened_forward_move_does_not_alert(): void
    {
        // A non-revival reopen (forward, card not in the abandon stage) moves but must
        // NOT alert — only a genuine revival from the abandon stage is an override.
        $this->writeReviveConfig(withAlert: true);
        $this->fakeReviveStageOrderAndCard(49, [], withAlert: true);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }
}
