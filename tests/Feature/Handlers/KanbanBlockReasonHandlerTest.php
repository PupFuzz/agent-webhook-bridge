<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Handlers\KanbanBlockReasonHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class KanbanBlockReasonHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/blkreason-'.uniqid();
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

    private function writeWriteback(bool $draftOverlay = true): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52], 'draft_overlay' => $draftOverlay]],
        ]));
    }

    private function writeToken(): void
    {
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
    }

    private const ALERT_URL = 'http://127.0.0.1:9933/';

    /**
     * The same mapping WITH an alert channel — this handler had no notifier wiring of any
     * kind before card#5312, so its refusal arms were structurally unable to signal.
     */
    private function writeWritebackWithAlert(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52], 'draft_overlay' => true]],
        ]));
    }

    private function isAlertPush(Request $r): bool
    {
        return $r->method() === 'POST' && str_starts_with($r->url(), self::ALERT_URL);
    }

    /** @param array<string, mixed> $extra extra payload keys (the card#5953 corroboration flag + pr_number) */
    private function handle(string $action, int $cardId = 5, string $repo = 'owner/repo', array $extra = []): void
    {
        (new KanbanBlockReasonHandler)->handle(
            ReactionTarget::make('kanban_block_reason', (string) $cardId, payload: array_merge(['repo' => $repo, 'action' => $action], $extra)),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    // --- SET = add-if-missing ---

    public function test_set_writes_the_marker_into_an_empty_block_reason(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null]])   // GET
                ->push(['data' => ['id' => 5]]),                                            // PATCH
        ]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && ! isset($r['task'])   // DL-219: block_reason written flat, not under a task wrapper
            && $r['block_reason'] === KanbanBlockReasonHandler::MARKER
            && $r->hasHeader('Authorization', 'Bearer wb-token'));
    }

    public function test_set_writes_the_marker_into_a_whitespace_only_block_reason(): void
    {
        // Boundary: a whitespace-only reason is not a human pin (PinGuard trim
        // semantics), so add-if-missing still stamps the marker.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => '   ']])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && ! isset($r['task'])
            && $r['block_reason'] === KanbanBlockReasonHandler::MARKER);
    }

    public function test_set_leaves_a_human_block_reason_untouched(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => 'waiting on upstream']])]);

        $this->handle('set');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // never stomps a human reason
    }

    public function test_set_is_a_noop_when_the_marker_is_already_present(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => KanbanBlockReasonHandler::MARKER]])]);

        $this->handle('set');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // idempotent
    }

    // --- CLEAR = clear-if-ours ---

    public function test_clear_nulls_block_reason_when_it_is_our_marker(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => KanbanBlockReasonHandler::MARKER]])   // GET
                ->push(['data' => ['id' => 5]]),                                                                       // PATCH
        ]);

        $this->handle('clear');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && ! isset($r['task'])
            && array_key_exists('block_reason', $r->data())
            && $r['block_reason'] === null);
    }

    public function test_clear_leaves_a_human_block_reason_intact(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => 'human decided to hold']])]);

        $this->handle('clear');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // clear-if-ours only
    }

    public function test_clear_is_a_noop_when_block_reason_is_already_empty(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null]])]);

        $this->handle('clear');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    // --- opt-in / guards ---

    public function test_repo_not_opted_in_is_a_noop_without_reading_the_card(): void
    {
        $this->writeWriteback(false);   // draft_overlay off
        $this->writeToken();
        Http::fake();

        $this->handle('set');

        Http::assertNothingSent();   // opt-out decided before any API call
    }

    public function test_unmapped_repo_is_a_noop(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake();

        $this->handle('set', 5, 'other/repo');

        Http::assertNothingSent();
    }

    public function test_card_on_wrong_board_is_refused(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'block_reason' => null]])]);

        $this->handle('set');   // belongs-to-mapped-board guard — no throw, no write

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_writeback_disabled_is_a_noop(): void
    {
        // No writeback.json written.
        Http::fake();

        $this->handle('set');

        Http::assertNothingSent();
    }

    public function test_missing_token_throws_for_redelivery(): void
    {
        // Transient/operator-fixable: throw → 5xx → redelivery succeeds once the token lands.
        $this->writeWriteback();
        // No token written.
        Http::fake();

        $this->expectException(ConfigException::class);
        $this->handle('set');
    }

    // --- transient / permanent split ---

    public function test_getcard_4xx_is_permanent_noop(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['error' => 'not found'], 404)]);

        $this->handle('set');   // no exception

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_setblockreason_4xx_is_permanent_noop(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null]])   // GET ok
                ->push(['error' => 'unknown field'], 422),                                  // PATCH 4xx
        ]);

        $this->handle('set');   // no exception

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');   // write attempted; 4xx swallowed
    }

    public function test_setblockreason_5xx_is_transient_and_throws(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null]])   // GET ok
                ->push('upstream error', 503),                                              // PATCH 5xx
        ]);

        $this->expectException(RequestException::class);
        $this->handle('set');
    }

    // --- card#5312 / DL-274: the overlay's two permanent-refusal arms became live signals ---

    public function test_getcard_4xx_alerts_under_the_draft_overlay_outcome(): void
    {
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['error' => 'not found'], 404),
        ]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'getcard_404_no_such_card'
            && $r['repo'] === 'owner/repo'
            && $r['outcome'] === 'draft_overlay'
            && $r['card_id'] === 5);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg) => str_contains($msg, 'getCard refused by kanban'));
    }

    public function test_setblockreason_4xx_alerts_with_the_write_reason(): void
    {
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null]])   // GET ok
                ->push(['message' => 'forbidden'], 403),                                     // PATCH refused
        ]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'blockreason_403_not_writable_by_this_token'
            && $r['outcome'] === 'draft_overlay');
    }

    public function test_the_overlay_outcome_keeps_its_getcard_signal_distinct_from_the_move_handlers(): void
    {
        // Both handlers use the verb `getcard`, so only the synthetic outcome separates
        // their dedup tuples. A shared outcome would let whichever fired first silence
        // the other's identical refusal on the same repo.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['error' => 'not found'], 404),
        ]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['outcome'] === 'draft_overlay');
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r) && $r['outcome'] === 'merged');
    }

    public function test_setblockreason_5xx_still_throws_and_never_alerts(): void
    {
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null]])
                ->push('upstream error', 503),
        ]);

        try {
            $this->handle('set');
            $this->fail('a 5xx on setBlockReason must propagate for redelivery');
        } catch (RequestException) {
            // expected
        }
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    // --- card#5953 / card#5287: the uncorroborated title-only card# corroboration gate,
    //     extended from the move path to the draft overlay ---

    public function test_uncorroborated_set_is_refused_when_the_card_tracks_another_pr(): void
    {
        // THE case the gate exists for, one surface over from the move path: a draft PR's
        // title descriptively cites card#5 while its branch names nothing. Card 5 is on
        // the mapped board and its block_reason is EMPTY, so absent the gate the SET has
        // real work and lands — the marker pins somebody else's card (DL-178). Card 5
        // already answers to PR 900, which is the evidence the title was citing another
        // card's work. Refuse, loudly, and write NOTHING.
        // (Revert the gate ⇒ a PATCH is sent ⇒ RED.)
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null, 'payload' => ['pr_number' => 900]]])
                ->push(['data' => ['id' => 5]]),
        ]);
        Log::spy();

        $this->handle('set', 5, 'owner/repo', ['card_token_uncorroborated' => true, 'pr_number' => 148]);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'REFUSED')
            && str_contains((string) $msg, 'only in the PR title'))->once();
    }

    public function test_the_suppressed_set_would_otherwise_have_landed(): void
    {
        // The control for the refusal above: the IDENTICAL fixture with the flag absent
        // writes the marker. Without this, "no PATCH was sent" could be true because the
        // set was an add-if-missing no-op rather than because the gate stopped it — a
        // suppression test needs real work to suppress.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null, 'payload' => ['pr_number' => 900]]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle('set', 5, 'owner/repo', ['pr_number' => 148]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['block_reason'] === KanbanBlockReasonHandler::MARKER);
    }

    public function test_uncorroborated_refusal_alerts_under_the_draft_overlay_outcome(): void
    {
        // The move path's twin refusal signals (DL-274 ✅ row); this security refusal is
        // not one of this handler's four known log-only gaps, so it signals too — on the
        // synthetic draft_overlay outcome that keeps its dedup tuple distinct.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => [
                'id' => 5, 'board_id' => 8, 'block_reason' => null, 'payload' => ['pr_number' => 900],
            ]]),
        ]);

        $this->handle('set', 5, 'owner/repo', ['card_token_uncorroborated' => true, 'pr_number' => 148]);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'card_token_uncorroborated'
            && $r['outcome'] === 'draft_overlay'
            && $r['card_id'] === 5);
    }

    public function test_uncorroborated_set_lands_when_the_card_tracks_no_pr(): void
    {
        // The legitimate title-only draft PR — the reason refuse-all was declined on
        // card#5287. Nothing on the card contradicts the title's claim.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null, 'payload' => []]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle('set', 5, 'owner/repo', ['card_token_uncorroborated' => true, 'pr_number' => 148]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['block_reason'] === KanbanBlockReasonHandler::MARKER);
    }

    public function test_uncorroborated_set_lands_when_the_card_already_tracks_this_pr(): void
    {
        // A later draft action on the SAME PR (or a redelivery). The numeric-string form
        // is what a durable-inbox JSON round-trip produces.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null, 'payload' => ['pr_number' => '148']]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle('set', 5, 'owner/repo', ['card_token_uncorroborated' => true, 'pr_number' => 148]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['block_reason'] === KanbanBlockReasonHandler::MARKER);
    }

    public function test_a_corroborated_set_lands_on_a_card_that_tracks_another_pr(): void
    {
        // The gate is scoped to the flag. A branch-corroborated card# (or a DL overlay)
        // on a card that already tracks another PR is a NORMAL second PR against one
        // card and must keep overlaying — the gate must not widen into it.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null, 'payload' => ['pr_number' => 900]]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle('set', 5, 'owner/repo', ['pr_number' => 148]);   // no flag

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['block_reason'] === KanbanBlockReasonHandler::MARKER);
    }

    public function test_uncorroborated_set_is_refused_when_the_event_carries_no_pr_number(): void
    {
        // Fail-closed, exactly as on the move path: an event with no PR number
        // corroborates nothing, so a card that tracks a PR is not marked on the title's
        // word alone.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null, 'payload' => ['pr_number' => 900]]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle('set', 5, 'owner/repo', ['card_token_uncorroborated' => true]);   // no pr_number

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_the_ready_for_review_clear_is_never_gated(): void
    {
        // The gate is scoped to `set` and that is a ruling, not an omission: CLEAR is
        // clear-if-ours, so it can only ever remove the marker WE wrote. Gating it would
        // strand a marker set before this shipped — the guard causing the harm it exists
        // to prevent, permanently pinning the very card it was meant to protect.
        // (Widen the gate to both actions ⇒ no PATCH ⇒ RED.)
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => [
                    'id' => 5, 'board_id' => 8,
                    'block_reason' => KanbanBlockReasonHandler::MARKER,
                    'payload' => ['pr_number' => 900],
                ]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle('clear', 5, 'owner/repo', ['card_token_uncorroborated' => true, 'pr_number' => 148]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && array_key_exists('block_reason', $r->data())
            && $r['block_reason'] === null);
    }

    // --- malformed payloads (deterministic classifier bug → permanent no-op) ---

    public function test_bad_action_is_permanent_noop_not_a_throw(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake();

        (new KanbanBlockReasonHandler)->handle(
            ReactionTarget::make('kanban_block_reason', '5', payload: ['repo' => 'owner/repo', 'action' => 'bogus']),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertNothingSent();
    }

    public function test_non_numeric_target_id_is_permanent_noop_not_a_throw(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake();

        (new KanbanBlockReasonHandler)->handle(
            ReactionTarget::make('kanban_block_reason', 'not-a-number', payload: ['repo' => 'owner/repo', 'action' => 'set']),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertNothingSent();
    }
}
