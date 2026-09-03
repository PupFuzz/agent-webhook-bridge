<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanCoordCardMoveHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * DL-200 — the coord-card MOVE handler: close → terminal, reopen → revive.
 *
 * The revive half carries the actor-gate (roundtable #18 Q5): revive IFF the
 * terminal was SERVICE-set. A human-set terminal is a human's closure intent and
 * must never be reversed by the bridge — anything that is not literally
 * `actor_type == "service"` fails CLOSED.
 */
class KanbanCoordCardMoveHandlerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/coordmoveh-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
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

    /** @param array<string, mixed> $mapping */
    private function writeMapping(array $mapping, string $repo = 'org/coord'): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => [$repo => $mapping],
        ]));
    }

    private const ALERT_URL = 'http://127.0.0.1:9935/';

    /**
     * The default mapping WITH an alert channel. This handler had no notifier wiring of
     * any kind before card#5968, so none of its refusal arms could signal.
     *
     * @param  array<string, mixed>  $overrides  merged over the default mapping — the lane
     *                                           legs need `coord_card_lane_stage_ids` and an
     *                                           alert channel at once
     */
    private function writeMappingWithAlert(array $overrides = []): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['org/coord' => array_merge(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
                'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99], $overrides)],
        ]));
    }

    private function isAlertPush(Request $r): bool
    {
        return $r->method() === 'POST' && str_starts_with($r->url(), self::ALERT_URL);
    }

    /** @param array<string, mixed> $overrides */
    private function handle(array $overrides = []): void
    {
        $payload = array_merge([
            'repo' => 'org/coord', 'issue_number' => 4, 'sid' => 'QUERY-4', 'disposition' => 'terminal',
        ], $overrides);

        (new KanbanCoordCardMoveHandler)->handle(
            ReactionTarget::make('kanban_coord_card_move', 'issue-'.$payload['issue_number'], payload: $payload),
            AgentConfig::fromArray('me', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    /**
     * ⛔ `$card` IS THE ROW THE PIN CONSULT READS, so it is defaulted to the shape kanban
     * actually serves. A row carrying neither `block_reason` nor `tags` makes
     * `PinGuard::isPinned` answer "not pinned" because nobody could READ the pin, which made
     * `test_close_moves_the_tagged_card_to_the_terminal_stage` — the control for the two
     * pinned terminal legs — pass without exercising the predicate at all (card#8523 R2).
     * A caller's own `block_reason` / `tags` still win: `+` keeps the left operand's keys.
     *
     * @param  array<string, mixed>  $card
     * @param  list<int>  $byTag
     */
    private function fakeBoard(array $card, array $byTag = [7]): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => array_map(fn ($id) => ['id' => $id], $byTag)]),
            '*/tasks/7.json' => Http::response(['data' => $card + ['block_reason' => null, 'tags' => []]]),
        ]);
    }

    private function assertMovedTo(int $stage): void
    {
        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/7.json')
            && ($r->data()['workflow_stage_id'] ?? null) === $stage);
    }

    private function assertNoMove(): void
    {
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    // ---- close → terminal ----

    public function test_close_moves_the_tagged_card_to_the_terminal_stage(): void
    {
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50]);

        $this->handle(['disposition' => 'terminal']);

        $this->assertMovedTo(99);
    }

    public function test_close_with_no_tagged_card_moves_nothing(): void
    {
        // Nothing carries id:<sid> — an un-carded issue (never created, or the
        // create leg is off). Nothing to conclude; the reconcile backstops.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50], byTag: []);

        $this->handle(['disposition' => 'terminal']);

        $this->assertNoMove();
    }

    public function test_close_is_idempotent_when_already_terminal(): void
    {
        // Redelivery-safe: at-least-once delivery must not re-PATCH.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99]);

        $this->handle(['disposition' => 'terminal']);

        $this->assertNoMove();
    }

    public function test_close_moves_a_card_a_human_had_placed_in_a_user_lane(): void
    {
        // Ruled on #18: user_lanes YIELD to a terminal — "close→Done IS the terminal
        // case, both movers agree". A human's priority placement does not survive
        // closure, so there is no PinGuard side to pick here.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50,
            'last_stage_move' => ['to_stage_id' => 50, 'actor_type' => 'human', 'actor_id' => 3]]);

        $this->handle(['disposition' => 'terminal']);

        $this->assertMovedTo(99);
    }

    // ---- reopen → revive (the actor-gate) ----

    public function test_revive_returns_a_service_set_terminal_card_to_the_create_stage(): void
    {
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99,
            'last_stage_move' => ['to_stage_id' => 99, 'actor_type' => 'service', 'actor_id' => 3]]);

        $this->handle(['disposition' => 'revive']);

        $this->assertMovedTo(21);
    }

    public function test_revive_refuses_a_human_set_terminal(): void
    {
        // THE actor-gate. A human dragged this card to the terminal — that is their
        // closure intent, and the bridge must never reverse it. "human" is the REAL
        // value kanban emits for a UI move (ChangeSource::actorTypeFor: 'human' iff
        // source === 'ui'); it never emits "user".
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99,
            'last_stage_move' => ['to_stage_id' => 99, 'actor_type' => 'human', 'actor_id' => 3]]);

        $this->handle(['disposition' => 'revive']);

        $this->assertNoMove();
    }

    public function test_revive_fails_closed_when_actor_type_is_absent(): void
    {
        // The REAL pre-feature shape: kanban always sends last_stage_move, with null
        // fields when the row predates the feature (ChangeSource::actorTypeFor returns
        // null iff source is null). null is not "service" ⇒ fail CLOSED.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99,
            'last_stage_move' => ['to_stage_id' => 99, 'actor_type' => null, 'actor_id' => null, 'at' => null]]);

        $this->handle(['disposition' => 'revive']);

        $this->assertNoMove();
    }

    public function test_revive_fails_closed_when_last_stage_move_is_missing_entirely(): void
    {
        // Defensive: the contract says last_stage_move is always sent, so this shape
        // shouldn't occur — but a kanban that drops or renames it must make the gate
        // REFUSE, not mis-revive. Degrading toward "never revive" is the safe direction.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99]);

        $this->handle(['disposition' => 'revive']);

        $this->assertNoMove();
    }

    public function test_revive_fails_closed_on_a_malformed_last_stage_move(): void
    {
        // A scalar where an object is expected must not fatal, and must not revive.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99,
            'last_stage_move' => 'nonsense']);

        $this->handle(['disposition' => 'revive']);

        $this->assertNoMove();
    }

    public function test_revive_fails_closed_on_an_unknown_actor_type(): void
    {
        // Fail-closed is an allow-list of exactly "service" — NOT a deny-list of the
        // human value. kanban resolves any unrecognized non-ui source to "service"
        // today, so this is defensive: if the enum ever widens, an unheard-of
        // actor_type must refuse rather than revive.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99,
            'last_stage_move' => ['to_stage_id' => 99, 'actor_type' => 'integration']]);

        $this->handle(['disposition' => 'revive']);

        $this->assertNoMove();
    }

    public function test_revive_does_not_drag_back_a_card_that_is_not_in_the_terminal(): void
    {
        // The card is live in In-Progress — someone is working it. Reviving it to the
        // create stage would drag it BACKWARD (the DL-163 regression this leg must not
        // reintroduce). Revive only un-does OUR terminal.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 49,
            'last_stage_move' => ['to_stage_id' => 49, 'actor_type' => 'service']]);

        $this->handle(['disposition' => 'revive']);

        $this->assertNoMove();
    }

    public function test_revive_with_no_tagged_card_moves_nothing(): void
    {
        // create-if-absent is the coord-card-create family's half of the reopen
        // composition. This handler never creates — exactly one leg acts.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99], byTag: []);

        $this->handle(['disposition' => 'revive']);

        $this->assertNoMove();
    }

    // ---- gates + payload ----

    public function test_non_prefixed_close_moves_by_ref_card_under_population_all(): void
    {
        // #4553: population=all moves a non-prefixed card correlated by github_issue by-ref.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99, 'issue_population' => 'all']);
        Http::fake([
            '*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50]]),
        ]);

        $this->handle(['sid' => null, 'disposition' => 'terminal']);

        $this->assertMovedTo(99);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains(urldecode($r->url()), 'system=github_issue')
            && str_contains(urldecode($r->url()), 'ref=4'));
        Http::assertNotSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/search.json'));
    }

    public function test_non_prefixed_close_with_no_by_ref_card_moves_nothing(): void
    {
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99, 'issue_population' => 'all']);
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []])]);

        $this->handle(['sid' => null, 'disposition' => 'terminal']);

        $this->assertNoMove();
    }

    public function test_opt_out_moves_nothing(): void
    {
        // DL-204 (#4357): opting out is now EXPLICIT move_coord_cards:false — omission defaults
        // the leg ON where a terminal is configured, so the opt-out must be explicit to win.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => false,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50]);

        $this->handle();

        $this->assertNoMove();
    }

    public function test_default_on_without_the_flag_moves(): void
    {
        // DL-204 fleet default: a complete move config (terminal present, revive stage present,
        // differ) WITHOUT move_coord_cards fires the move — the activation a terminal-configured
        // install gets for free, no flag needed.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50],
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50]);

        $this->handle();   // disposition defaults to close→terminal

        $this->assertMovedTo(99);
    }

    public function test_unmapped_repo_moves_nothing(): void
    {
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50]);

        $this->handle(['repo' => 'other/repo']);

        $this->assertNoMove();
    }

    public function test_malformed_payload_moves_nothing(): void
    {
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50]);

        $this->handle(['sid' => '']);

        $this->assertNoMove();
    }

    public function test_unknown_disposition_moves_nothing(): void
    {
        // Fail-closed on the dispositions the classifier can emit — a value the
        // handler doesn't recognize must never fall through to a move.
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50]);

        $this->handle(['disposition' => 'archive']);

        $this->assertNoMove();
    }

    public function test_a_card_on_another_board_is_not_moved(): void
    {
        // Defense against a tag collision across boards: only act on cards that
        // belong to the mapped board.
        $this->fakeBoard(['id' => 7, 'board_id' => 12, 'workflow_stage_id' => 50]);

        $this->handle(['disposition' => 'terminal']);

        $this->assertNoMove();
    }

    // ---- transient vs permanent ----

    public function test_a_4xx_is_permanent_and_does_not_throw(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['message' => 'gone'], 404),
        ]);

        $this->handle();   // must not throw — a config/data gap never 5xx-retries

        $this->assertNoMove();
    }

    public function test_a_permanent_4xx_on_one_card_does_not_abandon_the_others(): void
    {
        // PER-CARD error isolation. A tag can legitimately match several cards, and a
        // 4xx is PERMANENT — it is deliberately never redelivered. So if one card's read
        // aborted the whole loop, every later card would be stranded in an active column
        // FOREVER, with no event left to fix it. Card 7 is gone (deleted between the
        // search and the read); card 9 must still conclude.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7], ['id' => 9]]]),
            '*/tasks/7.json' => Http::response(['message' => 'gone'], 404),
            '*/tasks/9.json' => Http::response(['data' => ['id' => 9, 'board_id' => 8, 'workflow_stage_id' => 50]]),
        ]);

        $this->handle(['disposition' => 'terminal']);

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/9.json')
            && ($r->data()['workflow_stage_id'] ?? null) === 99);
    }

    public function test_a_5xx_on_one_card_still_propagates_for_redelivery(): void
    {
        // Per-card isolation must NOT swallow a transient: a 5xx has to escape the loop
        // so redelivery re-runs the whole set (the already-moved cards then no-op as
        // idempotent). Isolating 4xx must not accidentally isolate 5xx too.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7], ['id' => 9]]]),
            '*/tasks/7.json' => Http::response(['message' => 'boom'], 503),
            '*/tasks/9.json' => Http::response(['data' => ['id' => 9, 'board_id' => 8, 'workflow_stage_id' => 50]]),
        ]);

        $this->expectException(RequestException::class);
        $this->handle(['disposition' => 'terminal']);
    }

    public function test_a_5xx_is_transient_and_throws_for_redelivery(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['message' => 'boom'], 503),
        ]);

        $this->expectException(RequestException::class);
        $this->handle();
    }

    // --- card#5968 / DL-285: issue-keyed alerts. The two 4xx arms carry DIFFERENT
    //     reasons on purpose — they share `(repo, outcome)`, so one reason would give
    //     them one dedup marker and whichever fired second would alert zero times. ---

    public function test_a_per_card_4xx_alerts_with_that_cards_id_and_the_issue_number(): void
    {
        $this->writeMappingWithAlert();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['message' => 'gone'], 404),
        ]);

        $this->handle();

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'coord_card_move_card_4xx'
            && $r['outcome'] === 'coord_card_move'
            && $r['card_id'] === 7
            && $r['issue_number'] === 4);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $ctx) => str_contains($m, 'refused (4xx) for this card')
            && $ctx['status'] === 404);
    }

    public function test_a_correlation_read_4xx_alerts_under_a_distinct_reason(): void
    {
        // Both arms fire on ONE repo+outcome across two deliveries. A single Http::fake
        // with a sequence, not two calls — a second Http::fake() APPENDS stubs and the
        // first match wins, so the later stub would never be reached (G-020).
        $this->writeMappingWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => [['id' => 7]]])            // delivery 1: correlates, then the card 404s
                ->push(['message' => 'bad query'], 422),      // delivery 2: the read itself refuses
            '*/tasks/7.json' => Http::response(['message' => 'gone'], 404),
        ]);

        $this->handle();
        $this->handle();

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'coord_card_move_card_4xx');
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'coord_card_move_lookup_4xx'
            && $r['card_id'] === null
            && $r['issue_number'] === 4);
    }

    public function test_a_card_on_another_board_alerts_like_its_move_handler_twin(): void
    {
        // card#7133. The refusal itself is correct — nothing moves — but a cross-board
        // write that SUCCEEDS emits no event on this path, so this arm is the only
        // surface that can tell an operator the collision is happening at all. As a bare
        // Log::info it was indistinguishable from the handler never having been invoked.
        $this->writeMappingWithAlert();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 12, 'workflow_stage_id' => 50]]),
        ]);

        $this->handle(['disposition' => 'terminal']);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'card_not_on_mapped_board'
            && $r['outcome'] === 'coord_card_move'
            && $r['card_id'] === 7
            && $r['issue_number'] === 4);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $ctx) => str_contains($m, 'not on the mapped board')
            && $ctx['card_board'] === 12 && $ctx['mapped_board'] === 8);
        $this->assertNoMove();
    }

    public function test_a_non_numeric_board_id_that_casts_onto_the_mapped_board_refuses(): void
    {
        // THE VECTOR FOR THE `is_numeric` DISJUNCT, and the only one that is: `'8abc'`
        // is not numeric, but `(int) '8abc' === 8` — so with `is_numeric` deleted the
        // `(int)` compare would AGREE with the mapped board and this handler would move a
        // card that is not on it. Deleting the disjunct reds this method; deleting it
        // with a `null`/`'abc'` fixture instead reds nothing, because those cast to 0 and
        // the second disjunct refuses them on its own.
        //
        // ⛔ Direction, because it was reported backwards once: `is_numeric` does not make
        // this predicate stricter. `!==` against a readonly int refused ANY non-int, so
        // the twins ALSO refused `"8"` and `8.0` — legitimate cards, mis-refused. This
        // predicate's refusal set is a strict SUBSET of theirs: the most PERMISSIVE of
        // the three spellings, and the most correct. `is_numeric` is here to stop `(int)`
        // coercing a non-numeric value INTO the mapped id, which is the one thing
        // tolerating numeric strings opens up. card#7138 / DL-292 picked it as the
        // canonical form and hoisted it into `MappedBoardGuard`, so this method now
        // exercises the SHARED predicate — the twins carry the same leg (their tests say
        // so) and the mutation that deletes `is_numeric` reds all three.
        $this->writeMappingWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => '8abc', 'workflow_stage_id' => 50]]),
        ]);

        $this->handle(['disposition' => 'terminal']);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'card_not_on_mapped_board'
            && $r['card_id'] === 7);
        $this->assertNoMove();
    }

    public function test_an_absent_board_id_refuses(): void
    {
        // Kept as its own leg, and honestly labelled: a null `board_id` is refused by the
        // `(int)` compare alone (0 !== 8), so this method pins the ARM's behaviour on a
        // card whose board is unreadable — it is NOT a vector for the disjunct above.
        $this->writeMappingWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => null, 'workflow_stage_id' => 50]]),
        ]);

        $this->handle(['disposition' => 'terminal']);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'card_not_on_mapped_board'
            && $r['card_id'] === 7);
        $this->assertNoMove();
    }

    public function test_malformed_payload_alerts_on_the_empty_repo_key(): void
    {
        $this->writeMappingWithAlert();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle(['repo' => null]);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'coord_card_move_payload_invalid'
            && $r['repo'] === ''
            && $r['issue_number'] === 4);
    }

    public function test_empty_sid_under_prefixed_population_alerts(): void
    {
        $this->writeMappingWithAlert();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle(['sid' => '']);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'coord_card_move_no_correlation_key'
            && $r['repo'] === 'org/coord');
        $this->assertNoMove();
    }

    public function test_absent_writeback_json_still_logs_and_cannot_push(): void
    {
        File::delete($this->dir.'/writeback.json');
        Log::spy();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'writeback not configured'));
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    public function test_a_5xx_still_throws_and_never_alerts(): void
    {
        $this->writeMappingWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['message' => 'boom'], 503),
        ]);

        try {
            $this->handle();
            $this->fail('a 5xx must propagate for redelivery');
        } catch (RequestException) {
            // expected
        }
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    // =====================================================================
    // The lane-aware revive + the relane leg (card#6393)
    // =====================================================================
    //
    // The class card#6393 names is "a coord-card stage minted or kept with NO lane
    // input": on a lane-model board the consumer's kanban-writeback pass maps the
    // card's lane back onto the issue's `stage:*` label before its issues-sync runs, so
    // a bridge move that ignores the lane REWRITES the sequencing ruling the issue
    // states. Instance 1 is the revive arm; instance 2 is a `[TASK]` labelled after it
    // was carded.

    /** @param array<string, mixed> $overrides */
    private function writeLaneMapping(array $overrides = []): void
    {
        $this->writeMapping(array_merge([
            'board_id' => 8,
            'stages' => ['opened' => 50],
            'create_coord_cards' => true,
            'move_coord_cards' => true,
            'coord_card_stage_id' => 21,
            'coord_card_terminal_stage_id' => 99,
            'coord_card_lane_stage_ids' => ['now' => 40, 'next' => 41, 'later' => 42, 'maybe' => 43],
        ], $overrides));
    }

    /**
     * A card in $stage whose last move was made by $actorType.
     *
     * @return array<string, mixed>
     */
    private function card(int $stage, string $actorType = 'service'): array
    {
        return ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => $stage,
            'last_stage_move' => ['to_stage_id' => $stage, 'actor_type' => $actorType, 'actor_id' => 3]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function handleTask(array $overrides): void
    {
        $this->handle(array_merge(['sid' => 'TASK-4', 'title' => '[TASK] do the thing'], $overrides));
    }

    // ---- instance 1: the revive arm is lane-aware ----

    public function test_revive_returns_a_task_to_the_lane_its_label_declares(): void
    {
        // THE DEFECT: pre-card#6393 this moved the card to the fixed 21, and the
        // consumer's lane→label pass then rewrote the issue's `stage:later` to whatever
        // lane 21 sits in. The vector is deliberately NOT `later`: `later` is the
        // no-label default, so a leg that stopped deriving would land in the same stage
        // as a working one and could not fail.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(99));

        $this->handleTask(['disposition' => 'revive', 'labels' => ['stage:now']]);

        $this->assertMovedTo(40);
    }

    public function test_revive_on_a_move_on_create_off_install_lands_in_the_declared_lane(): void
    {
        // ⛔ DL-294 / card#7126 — THE BEHAVIOUR THE WIDENING EXISTS TO ENABLE, and the
        // reason "it loads now" is not the control. This mapping creates NO coord cards:
        // its cards are the consumer reconcile's, correlated by the shared `id:<sid>` tag.
        // Before DL-294 the config was unloadable, so this install's only route to a
        // lane-aware revive was enabling `create_coord_cards` — which changes WHICH mover
        // creates cards there and races the reconcile (docs/writeback.md).
        //
        // The vector is `stage:now` (40), never `later`: 42 is the no-label default, so a
        // leg that stopped deriving would land in the same stage as a working one. 21 is
        // the fixed revive target — the answer this leg gave before the lane ids were read.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50],
            'move_coord_cards' => true, 'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99,
            'coord_card_lane_stage_ids' => ['now' => 40, 'next' => 41, 'later' => 42, 'maybe' => 43]]);
        $this->fakeBoard($this->card(99));

        $this->handleTask(['disposition' => 'revive', 'labels' => ['stage:now']]);

        $this->assertMovedTo(40);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && ($r->data()['workflow_stage_id'] ?? null) === 21);
    }

    public function test_revive_of_an_unlabelled_task_uses_the_default_lane(): void
    {
        // `_task_lane`'s own default — Later, not the fixed create stage.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(99));

        $this->handleTask(['disposition' => 'revive', 'labels' => ['from:pm']]);

        $this->assertMovedTo(42);
    }

    public function test_revive_without_a_lane_map_is_byte_identical_and_uses_the_fixed_stage(): void
    {
        // THE OPT-IN PIN: an install that configured no lane stage ids must behave
        // exactly as it did before card#6393 — the fixed coord_card_stage_id — even for
        // a `[TASK]` carrying a `stage:*` label.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);
        $this->fakeBoard($this->card(99));

        $this->handleTask(['disposition' => 'revive', 'labels' => ['stage:now']]);

        $this->assertMovedTo(21);
    }

    public function test_revive_of_a_non_task_title_keeps_the_fixed_stage(): void
    {
        // The lane model governs an anchored `[TASK]` title only — `classify_coord`'s
        // own gate. A `[QUERY]` keeps the pre-existing fixed-stage behaviour.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(99));

        $this->handle(['disposition' => 'revive', 'sid' => 'QUERY-4', 'title' => '[QUERY] ask', 'labels' => ['stage:now']]);

        $this->assertMovedTo(21);
    }

    public function test_revive_warns_when_the_declared_lane_is_not_mapped(): void
    {
        $this->writeLaneMapping(['coord_card_lane_stage_ids' => ['now' => 40, 'next' => 41, 'later' => 42]]);
        $this->fakeBoard($this->card(99));
        Log::spy();

        $this->handleTask(['disposition' => 'revive', 'labels' => ['stage:maybe']]);

        $this->assertMovedTo(42);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => str_contains($m, 'lane') && str_contains($m, 'not mapped')
            && $c['unmapped_lanes'] === ['maybe']
            && $c['moved_to_lane'] === 'later'
            && $c['mapped_lanes'] === ['now', 'next', 'later'])->once();
    }

    public function test_revive_still_refuses_a_human_set_terminal_with_a_lane_map(): void
    {
        // The actor-gate is unchanged by the lane derivation — it runs first.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(99, 'human'));

        $this->handleTask(['disposition' => 'revive', 'labels' => ['stage:now']]);

        $this->assertNoMove();
    }

    // ---- instance 2: relane ----

    public function test_relane_moves_a_task_to_the_lane_its_new_label_declares(): void
    {
        // The shipped case: a `[TASK]` opened unlabelled is carded in `later`; labelling
        // it `stage:now` afterwards used to leave the card in `later` and let the
        // consumer's writeback converge the brand-new label back to `stage:later`.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(42));

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        $this->assertMovedTo(40);
    }

    public function test_relane_is_idempotent_when_the_card_is_already_in_the_declared_lane(): void
    {
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(40));

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        $this->assertNoMove();
    }

    public function test_relane_refuses_a_lane_a_human_placed_the_card_in(): void
    {
        // THE GATE THAT KEEPS THIS FROM RE-MINTING card#6393 IN REVERSE. A human dragged
        // this card to `later`; letting a `stage:now` label pull it back would make the
        // label override a deliberate board move — the same two-movers-disagree defect
        // pointing the other way.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(42, 'human'));

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        $this->assertNoMove();
    }

    public function test_relane_fails_closed_when_actor_type_is_absent(): void
    {
        // The pre-feature row shape: kanban sends last_stage_move with null fields. null
        // is not "service" ⇒ fail CLOSED, exactly as the revive gate does.
        $this->writeLaneMapping();
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 42,
            'last_stage_move' => ['to_stage_id' => 42, 'actor_type' => null, 'actor_id' => null, 'at' => null]]);

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        $this->assertNoMove();
    }

    public function test_relane_never_pulls_a_concluded_card_out_of_the_terminal(): void
    {
        // The card of a CLOSED issue sits in the terminal, and the close leg put it
        // there as a service actor — so the actor-gate alone would let a label edit
        // resurrect it. Relane is a lane→lane move; the terminal is not a lane.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(99));

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        $this->assertNoMove();
    }

    public function test_relane_refuses_a_card_that_is_not_in_a_mapped_lane(): void
    {
        // A card someone has advanced to a working column is live work with a placement
        // of its own; a label edit must not yank it back into a lane.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(50));

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        $this->assertNoMove();
    }

    public function test_relane_without_a_lane_map_moves_nothing(): void
    {
        // THE OPT-IN PIN for instance 2: with no lane stage ids there is no lane to
        // write, so the leg is inert rather than falling back to the fixed stage (which
        // would be a brand-new move on a board with no lane model at all).
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);
        $this->fakeBoard($this->card(42));
        Log::spy();

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        $this->assertNoMove();
        // The CAUSE is in the line, not just the silence: this one is a config gap the
        // operator can close, and its twin below is the design working. One message for
        // both would tell the reader which of the two it was: nothing.
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $m) => str_contains($m, 'no lane model is configured for this repo'));
    }

    public function test_relane_of_a_non_task_title_moves_nothing(): void
    {
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(42));
        Log::spy();

        $this->handle(['disposition' => 'relane', 'sid' => 'QUERY-4', 'title' => '[QUERY] ask', 'labels' => ['stage:now']]);

        $this->assertNoMove();
        // The other cause of the same gate — the lane model is configured and simply does
        // not govern a `[QUERY]`. Nothing for the operator to do, which is exactly what the
        // line above must not be confused with.
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $m) => str_contains($m, 'the lane model does not govern this issue'));
    }

    public function test_an_unknown_disposition_is_refused_as_a_malformed_payload(): void
    {
        // The allow-list, re-pinned now that it has three members: a disposition nobody
        // enumerated must never fall through to a move.
        $this->writeMappingWithAlert();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle(['disposition' => 'archive']);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'coord_card_move_payload_invalid');
        $this->assertNoMove();
    }

    // ---- the WIRE, end to end ----
    //
    // Every lane leg above hands the handler a `labels` list the TEST wrote, and the
    // classifier legs assert the payload key in isolation — neither exercises the wire
    // between them. These two drive a real GitHub body through classify → resolve →
    // handle, so a classifier that stopped stamping `title`/`labels`, or renamed either
    // key, reds here even though both sides' own legs stay green.
    //
    // The moving vectors are deliberately `stage:now` and never `stage:later`: `later` is
    // the no-labels default, so a broken wire would land the card in the same stage as a
    // working one and the leg could not fail.

    /**
     * Classify one real webhook body under $family and run every target it emits through
     * the registry, exactly as the dispatcher would. Returns the target count, so a leg
     * asserting NOTHING happened proves it at the board and not merely at the classifier.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $eventType, array $payload, string $family): int
    {
        $agent = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => [$family]]],
        ]);

        $result = (new CoordinationClassifier)->classify(new ClassifyContext(
            $eventType, $payload, new Actor(id: '99', name: null, isKnownAgent: false), 'github', 'org/coord', $agent,
        ));

        foreach ($result->targets as $target) {
            $handler = (new HandlerRegistry)->resolve($target->handler);
            $this->assertNotNull($handler);
            $handler->handle($target, $agent);
        }

        return count($result->targets);
    }

    /**
     * A real `issues.labeled` body announcing $addedLabel on a `[TASK]` thread.
     *
     * @return array<string, mixed>
     */
    private function labeledBody(string $addedLabel): array
    {
        return [
            'action' => 'labeled',
            'issue' => [
                'number' => 4,
                'title' => '[TASK] do the thing',
                'html_url' => 'https://github.com/org/coord/issues/4',
                'labels' => [['name' => 'from:pm'], ['name' => $addedLabel]],
            ],
            'label' => ['id' => 11488592716, 'name' => $addedLabel, 'color' => '0e8a16', 'default' => false],
        ];
    }

    public function test_full_dispatch_revive_derives_the_lane_from_the_webhook_labels(): void
    {
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(99));

        $this->assertSame(1, $this->dispatch('issues.reopened', ['issue' => [
            'number' => 4,
            'title' => '[TASK] do the thing',
            'html_url' => 'https://github.com/org/coord/issues/4',
            'labels' => [['name' => 'from:pm'], ['name' => 'stage:now']],
        ]], 'coord-card-move'));

        $this->assertMovedTo(40);
    }

    public function test_full_dispatch_relane_moves_the_card_to_the_newly_labelled_lane(): void
    {
        // The shipped shape of instance 2, from a real `issues.labeled` body: the card
        // sits in `later` (where the unlabelled open put it) and the delivery announces
        // `stage:now` at the top level.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(42));

        $this->assertSame(1, $this->dispatch('issues.labeled', $this->labeledBody('stage:now'), 'coord-card-relane'));

        $this->assertMovedTo(40);
    }

    public function test_full_dispatch_a_stage_prefixed_non_lane_label_moves_no_card(): void
    {
        // THE PERMISSIVE-DIRECTION FAILURE, asserted at the BOARD rather than at the
        // classifier — the classifier's own leg proves no target is emitted, and this
        // proves the consequence that would follow if one were.
        //
        // The vector is chosen so a permissive filter CANNOT pass: the card sits in `now`
        // (40) and `stage:done` names no lane, so a `stage:`-PREFIX trigger resolves the
        // placement to the DEFAULT_LANE and PATCHes the card to `later` (42) — demoting a
        // sequenced task on a label that expressed no sequencing at all, and handing the
        // consumer's lane→label pass a `stage:later` to write back onto the issue. On a
        // stream of 641 already-arriving `issues.labeled` deliveries — the operator's
        // measurement, not one re-derived here — that is not one misfire.
        $this->writeLaneMapping();
        $this->fakeBoard($this->card(40));

        $emitted = $this->dispatch('issues.labeled', $this->labeledBody('stage:done'), 'coord-card-relane');

        // The board consequence is asserted FIRST, deliberately: it is the claim that
        // matters, and a leg that reds on the target count instead would report the
        // symptom the operator never sees.
        $this->assertNoMove();
        $this->assertSame(0, $emitted);
    }

    public function test_handler_is_registered_under_its_reaction_name(): void
    {
        // The silent-registration trap: an unregistered handler makes the whole leg
        // a no-op that still looks shipped.
        $this->assertInstanceOf(
            KanbanCoordCardMoveHandler::class,
            (new HandlerRegistry)->resolve('kanban_coord_card_move'),
        );
    }

    // --- card#7212: the success record names the board the write LANDED on ---

    public function test_a_successful_terminal_move_records_both_boards(): void
    {
        // This arm logged NEITHER board before card#7212 — not even the intended one — so a
        // write here left no board record at all. Its cross-board REFUSAL twin above
        // (test_a_card_on_another_board_alerts_like_its_move_handler_twin) asserts the same
        // pair on the other arm; that only one of the two emitted it was the defect.
        Log::spy();
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50]]),
        ]);

        $this->handle(['disposition' => 'terminal']);

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => $m === 'kanban_coord_card_move: moved to terminal'
            && $ctx['card_board'] === 8 && $ctx['mapped_board'] === 8);
    }

    public function test_the_terminal_move_record_reads_the_cards_own_board_not_a_second_copy_of_the_mapped_one(): void
    {
        // The control: the accepted interval (DL-292) is the divergence this guarded arm
        // can reach, and `===` on the numeric string is what an echo cannot produce.
        Log::spy();
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => '8', 'workflow_stage_id' => 50]]),
        ]);

        $this->handle(['disposition' => 'terminal']);

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => $m === 'kanban_coord_card_move: moved to terminal'
            && $ctx['card_board'] === '8' && $ctx['mapped_board'] === 8);
    }

    // ---- close → terminal: the DL-178 pin (card#8523 / DL-340) ----

    /**
     * card#8523 — the close leg had NO gate of any kind: `serviceSet()` guards revive and
     * relane only, and the pin was never read here, so an `issues.closed` concluded a card a
     * human had parked with nothing between it and the write but the `move_coord_cards`
     * opt-in. DL-335's Bounds said this leg was "actor-gated (DL-200)" and that was FALSE;
     * PR #639 R1 corrected the sentence and this closes the behaviour.
     *
     * The UNPINNED control on the identical fixture is
     * {@see test_close_moves_the_tagged_card_to_the_terminal_stage} — same card, same
     * disposition, no pin, PATCH sent — so a green here cannot be a fixture that never
     * reached the write.
     */
    public function test_close_does_not_conclude_a_card_pinned_with_a_block_reason(): void
    {
        $this->writeMappingWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'block_reason' => 'parked pending a decision']]),
        ]);
        Log::spy();

        $this->handle(['disposition' => 'terminal']);

        $this->assertNoMove();
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'pinned_no_automove'
            && $r['repo'] === 'org/coord'
            && $r['outcome'] === 'coord_card_move'
            && $r['card_id'] === 7
            && $r['issue_number'] === 4);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'terminal move refused — card is pinned')
            && $ctx['card_id'] === 7 && $ctx['issue'] === 4 && $ctx['card_board'] === 8 && $ctx['mapped_board'] === 8)->once();
    }

    /**
     * PinGuard's OTHER signal on the same leg — the tag, which is the spelling an operator
     * reaches for when the card carries no block text.
     */
    public function test_close_does_not_conclude_a_card_tagged_no_automove(): void
    {
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'tags' => ['no-automove']]);

        $this->handle(['disposition' => 'terminal']);

        $this->assertNoMove();
    }

    /**
     * The bound, and the reason the consult sits AFTER the already-concluded no-op (DL-335
     * Decision 3, same placement): a pinned card already in the terminal has no write to
     * refuse, and an alert there would report a permanent failure that did not happen.
     *
     * ⭐ THE `GET` IS A PRESENCE WITNESS, NOT DECORATION (card#8523 R1). Every other assertion
     * here is an ABSENCE, and a test asserting only absences certifies whatever replaces the
     * behaviour: an early return added anywhere upstream of `moveOne()` — a classifier change,
     * a correlation guard, a config gate — leaves all of them green while nothing ever reaches
     * the arm this leg exists to bound. The card read is the first thing `moveOne()` does, so
     * requiring it pins that the run got INTO the code under test.
     */
    public function test_a_pinned_card_already_in_the_terminal_stage_raises_no_refusal_signal(): void
    {
        $this->writeMappingWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99, 'tags' => ['no-automove']]]),
        ]);

        $this->handle(['disposition' => 'terminal']);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/7.json'));
        $this->assertNoMove();
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    // =====================================================================
    // card#8557 — the pin reaches the two LANE-writing legs
    // =====================================================================
    //
    // ⛔ WHY THESE LEGS NEEDED ANYTHING AT ALL, since both already refuse a card whose
    // stage was not SERVICE-set. That gate asks who made the card's LAST STAGE MOVE, and
    // pinning is a field PATCH — so a card the BRIDGE parked and an operator then pinned
    // is still service-set, walks every gate, and moves. Both fixtures below are exactly
    // that shape (`actor_type: service` AND a pin), which is what makes them reachable
    // rather than hypothetical, and it is why the fixture keeps the service actor instead
    // of relying on the pin alone to stop the write.

    /**
     * ⭐ THE REFUSAL SEEN TO FIRE on the revive leg. Its control is
     * {@see test_revive_returns_a_service_set_terminal_card_to_the_create_stage} — same
     * card, same disposition, same service-set terminal, no pin, PATCH sent.
     */
    public function test_revive_does_not_return_a_pinned_card_and_the_refusal_is_loud(): void
    {
        $this->writeMappingWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99,
                'block_reason' => 'parked pending a decision', 'tags' => [],
                'last_stage_move' => ['to_stage_id' => 99, 'actor_type' => 'service', 'actor_id' => 3]]]),
        ]);
        Log::spy();

        $this->handle(['disposition' => 'revive']);

        $this->assertNoMove();
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'pinned_no_automove'
            && $r['repo'] === 'org/coord'
            && $r['outcome'] === 'coord_card_move'
            && $r['card_id'] === 7
            && $r['issue_number'] === 4);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'revive refused — card is pinned')
            && $ctx['card_id'] === 7 && $ctx['issue'] === 4 && $ctx['card_board'] === 8 && $ctx['mapped_board'] === 8)->once();
    }

    /**
     * The pin's other spelling on the revive leg — the predicate is a disjunction.
     *
     * ⭐ THE READ IS A PRESENCE WITNESS, NOT DECORATION. Every other assertion here is an
     * ABSENCE, and an absence-only test certifies whatever replaces the behaviour: an early
     * return added anywhere upstream leaves it green while nothing ever reaches the guard.
     * Requiring the read pins that the run got INTO the code under test.
     */
    public function test_revive_leaves_a_card_tagged_no_automove(): void
    {
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99, 'tags' => ['no-automove'],
            'last_stage_move' => ['to_stage_id' => 99, 'actor_type' => 'service', 'actor_id' => 3]]);

        $this->handle(['disposition' => 'revive']);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/7.json'));
        $this->assertNoMove();
    }

    /**
     * ⭐ THE REFUSAL SEEN TO FIRE on the relane leg. Its control is
     * {@see test_relane_moves_a_task_to_the_lane_its_new_label_declares} — same card in the
     * same mapped lane, same label, no pin, PATCH sent.
     */
    public function test_relane_does_not_move_a_pinned_card_and_the_refusal_is_loud(): void
    {
        $this->writeMappingWithAlert(['create_coord_cards' => true,
            'coord_card_lane_stage_ids' => ['now' => 40, 'next' => 41, 'later' => 42, 'maybe' => 43]]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 42,
                'block_reason' => 'parked pending a decision', 'tags' => [],
                'last_stage_move' => ['to_stage_id' => 42, 'actor_type' => 'service', 'actor_id' => 3]]]),
        ]);
        Log::spy();

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        $this->assertNoMove();
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'pinned_no_automove'
            && $r['outcome'] === 'coord_card_move'
            && $r['card_id'] === 7);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => str_contains($m, 're-lane refused — card is pinned')
            && $ctx['card_id'] === 7 && $ctx['issue'] === 4)->once();
    }

    /**
     * The pin's other spelling on the relane leg.
     *
     * ⭐ THE READ IS A PRESENCE WITNESS, NOT DECORATION. Every other assertion here is an
     * ABSENCE, and an absence-only test certifies whatever replaces the behaviour: an early
     * return added anywhere upstream leaves it green while nothing ever reaches the guard.
     * Requiring the read pins that the run got INTO the code under test.
     */
    public function test_relane_leaves_a_card_tagged_no_automove(): void
    {
        $this->writeLaneMapping();
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 42, 'tags' => ['no-automove'],
            'last_stage_move' => ['to_stage_id' => 42, 'actor_type' => 'service', 'actor_id' => 3]]);

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/7.json'));
        $this->assertNoMove();
    }

    /**
     * THE BOUND, and the reason both consults sit LAST — after every gate that ends in no
     * write. A pinned card the relane gates were never going to move has no write to
     * refuse, and an alert there would report a permanent failure that did not happen. The
     * fixture is a pinned card in the TERMINAL, which gate 2 (must be in a mapped lane)
     * already refuses.
     *
     * ⭐ THE `GET` IS A PRESENCE WITNESS, NOT DECORATION: every other assertion here is an
     * absence, and an absence-only test certifies whatever replaces the behaviour. The card
     * read is the first thing `moveOne()` does, so requiring it pins that the run got INTO
     * the code under test.
     */
    public function test_a_pinned_card_the_lane_gates_already_refuse_raises_no_alert(): void
    {
        $this->writeMappingWithAlert(['create_coord_cards' => true,
            'coord_card_lane_stage_ids' => ['now' => 40, 'next' => 41, 'later' => 42, 'maybe' => 43]]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 99,
                'tags' => ['no-automove'],
                'last_stage_move' => ['to_stage_id' => 99, 'actor_type' => 'service', 'actor_id' => 3]]]),
        ]);

        $this->handleTask(['disposition' => 'relane', 'labels' => ['stage:now']]);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/7.json'));
        $this->assertNoMove();
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }
}
