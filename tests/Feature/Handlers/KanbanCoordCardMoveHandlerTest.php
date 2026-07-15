<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanCoordCardMoveHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
     * @param  array<string, mixed>  $card
     * @param  list<int>  $byTag
     */
    private function fakeBoard(array $card, array $byTag = [7]): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => array_map(fn ($id) => ['id' => $id], $byTag)]),
            '*/tasks/7.json' => Http::response(['data' => $card]),
        ]);
    }

    private function assertMovedTo(int $stage): void
    {
        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/7.json')
            && ($r->data()['task']['workflow_stage_id'] ?? null) === $stage);
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

    public function test_opt_out_moves_nothing(): void
    {
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50],
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);
        $this->fakeBoard(['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50]);

        $this->handle();

        $this->assertNoMove();
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
            && ($r->data()['task']['workflow_stage_id'] ?? null) === 99);
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

    public function test_handler_is_registered_under_its_reaction_name(): void
    {
        // The silent-registration trap: an unregistered handler makes the whole leg
        // a no-op that still looks shipped.
        $this->assertInstanceOf(
            KanbanCoordCardMoveHandler::class,
            (new HandlerRegistry)->resolve('kanban_coord_card_move'),
        );
    }
}
