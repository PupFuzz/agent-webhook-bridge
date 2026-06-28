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
}
