<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Handlers\KanbanBlockReasonHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\ScopeLookupStub;
use Tests\TestCase;

class KanbanBlockReasonHandlerTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * Whether this test has already had the card#8415 board-scope fallback registered.
     *
     * ⛔ ONCE PER TEST, and the flag is what makes that true: `Http::fake()` also RESETS the
     * recorded-request log (G-020), so registering it on a SECOND `handle()` call would erase
     * the first delivery's requests. No leg here drives two deliveries today; the guard is the
     * same idiom as `KanbanMoveCardHandlerTest`'s, where legs do — and the failure it prevents
     * is the silent one, since an `assertNotSent` over an erased log passes vacuously.
     */
    private bool $scopeLookupStubbed = false;

    /** @param array<string, mixed> $extra extra payload keys (the card#5953 corroboration flag + pr_number) */
    private function handle(string $action, int $cardId = 5, string $repo = 'owner/repo', array $extra = []): void
    {
        // The board-scoped tenant check (card#8415) is made on every delivery that gets as far
        // as building a client, so every leg below reaches this endpoint. Registered LAST so a
        // leg that stubs the search itself still wins (G-020: first match wins); see
        // {@see ScopeLookupStub} for why it is a fixture rather than an assertion.
        if (! $this->scopeLookupStubbed) {
            Http::fake(ScopeLookupStub::onMappedBoard(8));
            $this->scopeLookupStubbed = true;
        }

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

    // --- card#7138 / DL-292: same widening as the move handler's twin — this arm now
    //     shares one predicate with it and with `kanban_coord_card_move`. See that
    //     handler's test for the direction: `!==` against a `readonly int` refused a
    //     numeric-string / float `board_id` naming the mapped board itself. ---

    public function test_a_numeric_string_board_id_naming_the_mapped_board_writes(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => '8', 'block_reason' => null]])   // GET
                ->push(['data' => ['id' => 5]]),                                             // PATCH
        ]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['block_reason'] === KanbanBlockReasonHandler::MARKER);
    }

    public function test_a_float_board_id_naming_the_mapped_board_writes(): void
    {
        // RAW JSON body: `json_encode(8.0)` emits `8`, so an array fixture would
        // round-trip to an int and re-test the case above. `8.0` on the wire decodes to
        // float(8) — refused by the old `!==` spelling.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push('{"data":{"id":5,"board_id":8.0,"block_reason":null}}')   // GET
                ->push(['data' => ['id' => 5]]),                                 // PATCH
        ]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['block_reason'] === KanbanBlockReasonHandler::MARKER);
    }

    public function test_a_board_id_that_casts_onto_the_mapped_board_but_is_not_numeric_is_refused(): void
    {
        // The `is_numeric` disjunct's vector on this arm: `(int) '8abc' === 8`, so
        // without it the cast compare would agree with the mapped board and write to a
        // card that is not on it.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => '8abc', 'block_reason' => null]])]);

        $this->handle('set');

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

        // ⛔ `card_id` is WITHHELD from the channel on this arm (card#7846 / DL-314), and
        // that is the move handler's twin rule, not a quirk of this handler: the overlay
        // resolves its card from the SAME author-controlled `card#`/DL token grammar
        // against a GLOBAL kanban id space, so an id whose read just failed is not
        // established as this install's. The log line keeps it (asserted below).
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'getcard_404_no_such_card'
            && $r['repo'] === 'owner/repo'
            && $r['outcome'] === 'draft_overlay'
            && $r['card_id'] === null
            && ($r['card_id_withheld'] ?? false) === true);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'getCard refused by kanban')
            && ($ctx['card_id'] ?? null) === 5);
    }

    public function test_getcard_403_names_only_the_token_scope_because_the_board_scoped_check_excluded_a_foreign_id(): void
    {
        // ⚑ THIS LEG REVERSED WITH card#8415, and the reversal is the deliverable. It was
        // written as the SIBLING DIFFERENCE: `kanban_move_card` had gained the board-scoped
        // lookup (card#8375) and narrowed its 403 slug, while this overlay made no such check,
        // so both causes stayed live here and `getcard_403_foreign_card_id_or_token_scope` was
        // the honest name. The check is now wired into this arm too — the same token read this
        // id back off the mapped board a request earlier — so the foreign half is ruled out by
        // a measurement and the slug must move with the code rather than be left behind naming
        // a cause the code has excluded (the shape DL-314 was filed on, inverted).
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'getcard_403_token_scope'
            // The withholding is keyed on THE READ FAILED, not on the foreign hypothesis the
            // check above excludes, so DL-314's rule still holds on this arm.
            && $r['card_id'] === null
            && ($r['card_id_withheld'] ?? false) === true);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        // …and the operator's local line must not keep offering the cause the slug dropped.
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'board-scoped check')
            && str_contains($msg, 'EXCLUDED')
            && ! str_contains($msg, 'may name another install')
            && ($ctx['status'] ?? null) === 403
            && ($ctx['card_id'] ?? null) === 5);
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

    // --- card#5968 / DL-285: the overlay's four NON-4xx permanent refusals join the
    //     signalling set. The board guard is the load-bearing one — its twin in
    //     KanbanMoveCardHandler has always alerted, so the gap was an asymmetry inside
    //     one DL-009 guard rather than a missing feature. ---

    public function test_card_not_on_the_mapped_board_alerts_like_its_move_handler_twin(): void
    {
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'block_reason' => null]]),
        ]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'card_not_on_mapped_board'
            && $r['outcome'] === 'draft_overlay'
            && $r['card_id'] === 5
            && $r['issue_number'] === null);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // still refuses to write
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'not on the mapped board'));
    }

    public function test_non_numeric_target_id_alerts(): void
    {
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        (new KanbanBlockReasonHandler)->handle(
            ReactionTarget::make('kanban_block_reason', 'not-a-number', payload: ['repo' => 'owner/repo', 'action' => 'set']),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        // The card id is what is malformed, so it is null; the repo still keys the tuple.
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'target_id_not_card_id'
            && $r['repo'] === 'owner/repo'
            && $r['card_id'] === null);
    }

    public function test_bad_action_alerts_with_the_card_id_it_does_know(): void
    {
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        (new KanbanBlockReasonHandler)->handle(
            ReactionTarget::make('kanban_block_reason', '5', payload: ['repo' => 'owner/repo', 'action' => 'bogus']),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'repo_or_action_invalid'
            && $r['card_id'] === 5);
    }

    public function test_absent_writeback_json_still_logs_and_cannot_push(): void
    {
        // The Branch-#3 degradation, asserted rather than assumed: this arm routes through
        // the paired primitive like every other, but the notifier loads its channel from
        // the very file that is absent — so no push is structurally possible here and the
        // durable log is the whole record.
        $this->writeToken();
        Log::spy();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle('set');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'writeback is not configured'));
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    // --- card#7212: the success record names the board the write LANDED on ---

    public function test_a_successful_set_records_the_cards_own_board_beside_the_mapped_one(): void
    {
        // Same asymmetry as its move-handler twin: the old single `board` key was the
        // config's INTENDED board, emitted whether or not the card written to was on it.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'block_reason' => null]])]);
        Log::spy();

        $this->handle('set');

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => $m === 'kanban_block_reason: set'
            && $ctx['card_board'] === 8 && $ctx['mapped_board'] === 8);
    }

    public function test_the_set_record_reads_the_cards_own_board_and_is_not_a_second_copy_of_the_mapped_one(): void
    {
        // The control, in the same shape as the move handler's: the accepted interval
        // (DL-292) is the only divergence reachable behind the guard, and `===` on the
        // numeric STRING is what a mapped-board echo cannot produce.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => '8', 'block_reason' => null]])]);
        Log::spy();

        $this->handle('set');

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => $m === 'kanban_block_reason: set'
            && $ctx['card_board'] === '8' && $ctx['mapped_board'] === 8);
    }

    /**
     * ⭐ AN EXPLICIT RULING, PINNED AS A TEST RATHER THAN LEFT AS PROSE (card#8557): a card
     * pinned by the `no-automove` TAG ALONE still takes this overlay's draft marker, and
     * that is the DESIGNED outcome, not a hole the pin forgot to cover.
     *
     * The finding that raised it: the add-if-missing guard tests `block_reason` only, so a
     * tag-only pin does not stop the write — which reads like the pin-blindness card#8557
     * was filed on. It is not, for three reasons, and they are recorded here because the
     * decision is only falsifiable if the reasoning is written where the behaviour is.
     *  1. **The rule as ruled.** The pin governs a card's STAGE, its LIFECYCLE and the
     *     fields `PinGuard::PINNED_FIELDS` names. `block_reason` is deliberately outside
     *     that set, on the same reading that keeps the correlation stamps in policy: this
     *     write records what happened TO the card (its PR went to draft), it does not
     *     restate what the card IS.
     *  2. **It only ever STRENGTHENS the hold.** The marker is a non-empty `block_reason`,
     *     so the card comes out of this write pinned by BOTH spellings — which is DL-193's
     *     stated intent (a drafted card is gated against the branch-push promote), not a
     *     side effect.
     *  3. **The inverse cannot weaken it.** `clear` is clear-if-OURS: it nulls a
     *     `block_reason` only when it is byte-identical to this handler's own marker, and
     *     it never touches `tags` at all — so no path here can remove the operator's pin.
     * Accept-current-state, evidence-backed. ⚠ THIS METHOD PINS GROUNDS 1 AND 2 — it drives a
     * tag-only pinned card through the write and asserts the marker lands and the `tags` key
     * is absent. Ground 3, clear-if-OURS, is pinned by
     * {@see test_clear_leaves_a_human_block_reason_intact} elsewhere in this file, and saying
     * so here is the point: a docblock claiming one method reds on all three would send a
     * reader who deleted the OTHER test looking for a red that never comes.
     */
    public function test_a_tag_pinned_card_still_takes_the_draft_marker_and_that_is_the_ruling(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => [
            'id' => 5, 'board_id' => 8, 'block_reason' => null, 'tags' => ['no-automove'],
        ]])]);

        $this->handle('set');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['block_reason' => KanbanBlockReasonHandler::MARKER]);
        // …and the operator's own pin is untouched: this write names no `tags` key, and
        // kanban replaces `tags` WHOLESALE, so sending one would have been the deletion.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && array_key_exists('tags', $r->data()));
    }
}
