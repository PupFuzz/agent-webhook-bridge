<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanBlockReasonHandler;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * THE TENANT BOUNDARY IS A PROPERTY OF THIS CODE, NOT OF THE WRITEBACK TOKEN'S SCOPE
 * (card#8375, extended to the draft overlay by card#8415).
 *
 * ⛔ WHAT THIS CLASS EXISTS TO MAKE FALSIFIABLE. The card id reaches BOTH token-resolved arms
 * — `kanban_move_card` and the `kanban_block_reason` draft overlay — as a literal parsed out
 * of AUTHOR-CONTROLLED text (`card#NNNN` in a PR title or head ref),
 * and kanban's card id space is GLOBAL across every board on the instance — so an id naming
 * another install's card arrives intact. Before this, each handler resolved it with an
 * UNSCOPED `GET /tasks/{id}.json` and only then compared the returned `board_id` against the
 * mapping. Measured live: a repo event on one install resolved to a card another install
 * owns, and what stopped it was the API answering 403 — i.e. whatever that token's scope
 * happened to be. Nothing asserted the scope stays narrow and NO TEST WENT RED IF IT
 * WIDENED, which by this repo's own standard makes the guarantee a comment rather than a
 * contract.
 *
 * ⭐ SO EVERY LEG HERE ASSERTS AT THE MECHANISM, NEVER BY OBSERVING A 403. The kanban fake
 * in the refusal legs answers the card read HAPPILY — a token whose scope covers the foreign
 * card, which is the state the code must survive — and the assertion is that the read is
 * NEVER MADE. A leg that proved its point by stubbing a 403 would be certifying the
 * credential, exactly the thing this card was filed on.
 *
 * The construction of the scoped call (both terms inside `q=`, no filter hoisted to a
 * droppable top-level parameter) is `BoardScopedReadConstructionTest`'s subject and is
 * deliberately not re-asserted here; this class owns the ORDER and the VERDICTS.
 */
class WritebackTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private const ALERT_URL = 'http://127.0.0.1:9934/';

    /** The mapped board, and a card id that is NOT one of its cards — the foreign id, live-observed. */
    private const BOARD = 8;

    private const FOREIGN_CARD = 7756;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wbscope-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            // `draft_overlay` opts the second token-resolved arm in (card#8415). It is inert
            // for the move legs — only the classifier and `KanbanBlockReasonHandler` read it.
            'mappings' => ['owner/repo' => ['board_id' => self::BOARD, 'stages' => ['merged' => 52], 'draft_overlay' => true]],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function handle(int $cardId = self::FOREIGN_CARD): void
    {
        (new KanbanMoveCardHandler)->handle(
            ReactionTarget::make('kanban_move_card', (string) $cardId, payload: [
                'card_id' => $cardId, 'repo' => 'owner/repo', 'outcome' => 'merged',
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    /**
     * The SECOND token-resolved arm (card#8415): the draft overlay, whose card id comes from
     * the same `card#`/DL token grammar against the same global id space. A `set` is used
     * because it is the writing direction — a refusal that let the read through would be one
     * PATCH away from stamping another install's card.
     */
    private function handleOverlay(int $cardId = self::FOREIGN_CARD): void
    {
        (new KanbanBlockReasonHandler)->handle(
            ReactionTarget::make('kanban_block_reason', (string) $cardId, payload: [
                'repo' => 'owner/repo', 'action' => 'set',
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    /**
     * The card read that MUST NOT happen on a refusal, stubbed to SUCCEED.
     *
     * ⛔ Read this as the widened-token fixture it is: kanban hands the foreign card over,
     * board and all. Every refusal leg below stubs it, so "the card was never read" is a
     * measurement of the bridge rather than of what the credential could reach.
     *
     * @return array<string, mixed>
     */
    private function foreignCardIsReadable(): array
    {
        return ['*/tasks/'.self::FOREIGN_CARD.'.json' => Http::response(['data' => [
            'id' => self::FOREIGN_CARD, 'board_id' => 9002, 'workflow_stage_id' => 41,
        ]])];
    }

    /** @return array<string, mixed> */
    private function alertStub(): array
    {
        return [self::ALERT_URL.'*' => Http::response(['ok' => true])];
    }

    /** @return list<array<string, mixed>> the alert bodies this test pushed */
    private function alerts(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_starts_with($pair[0]->url(), self::ALERT_URL))
            ->map(fn ($pair) => json_decode($pair[0]->body(), true))
            ->values()->all();
    }

    private static function isScopeLookup(Request $r): bool
    {
        return $r->method() === 'GET' && str_contains($r->url(), '/tasks/search.json');
    }

    private static function isUnscopedCardRead(Request $r): bool
    {
        return $r->method() === 'GET' && str_contains($r->url(), '/tasks/'.self::FOREIGN_CARD.'.json');
    }

    /**
     * ⭐ THE HEADLINE LEG. Seen to fail before the fix: without the board-scoped check the
     * handler GETs `/tasks/7756.json`, the fixture hands the card over (widened token), the
     * belongs-to-mapped-board compare then refuses it — a refusal that has already made a
     * cross-tenant read on this install's credential, which is the defect. With the fix, the
     * unscoped read is never issued at all.
     */
    public function test_a_foreign_card_id_is_refused_by_the_scope_check_with_the_unscoped_read_never_made(): void
    {
        Log::spy();
        Http::fake($this->alertStub() + [
            // The scope lookup: this card is on NO board this install maps. Both sides of the
            // archive switch answer empty; the `visibility` control answers a populated board,
            // which is what rules out "this token lost the board" as the explanation.
            '*/tasks/search.json?q=board_id%3D8%20id%3D7756*' => Http::response(['data' => []]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 12]]),
        ] + $this->foreignCardIsReadable());

        $this->handle();

        Http::assertNotSent(fn (Request $r) => self::isUnscopedCardRead($r));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Http::assertSent(fn (Request $r) => self::isScopeLookup($r));

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts, 'a permanent refusal must emit exactly one live signal');
        $this->assertSame('card_id_outside_mapped_board', $alerts[0]['reason']);
        // DL-314: an id this bridge never established as its own must not ride the channel —
        // and here it is established as NOT its own, which is the case that rule is for.
        $this->assertNull($alerts[0]['card_id']);
        $this->assertTrue($alerts[0]['card_id_withheld'] ?? false);
        // …while the local operator's surface keeps it, or the refusal is undiagnosable.
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'REFUSED')
            && str_contains($msg, 'not on the mapped board')
            && ($ctx['card_id'] ?? null) === self::FOREIGN_CARD
            && ($ctx['mapped_board'] ?? null) === self::BOARD);
    }

    /**
     * The CONTROL that keeps the verdict above honest: with the mapped board itself
     * unreadable, the same empty answer must NOT be reported as a foreign card id. Without
     * this discrimination a token that had lost its board would accuse every PR author on
     * the repo, and the operator would never look at their own credential (canon #10 — a
     * wrong-but-specific cause is worse than an honest generic one).
     */
    public function test_an_unreadable_mapped_board_reaches_the_weaker_verdict_rather_than_accusing_the_id(): void
    {
        Http::fake($this->alertStub() + [
            // Everything the token asks of this board comes back empty — the scoped lookup
            // AND the visibility control. That is the blind-token shape (DL-026).
            '*/tasks/search.json*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        ] + $this->foreignCardIsReadable());

        $this->handle();

        Http::assertNotSent(fn (Request $r) => self::isUnscopedCardRead($r));
        $this->assertSame('mapped_board_unreadable_to_this_token', $this->alerts()[0]['reason']);
    }

    /**
     * The third verdict, and the one that must never be mistaken for the first: kanban
     * answered a row that is not this card. A search filter this endpoint does not recognise
     * is dropped in SILENCE behind a 200, so an answer about some other card establishes
     * nothing at all — reporting it as "not on the mapped board" would be a tenant verdict
     * read out of a broken read.
     */
    public function test_a_lookup_that_answers_another_card_is_a_broken_read_not_a_foreign_id(): void
    {
        Http::fake($this->alertStub() + [
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 41, 'board_id' => self::BOARD]]]),
        ] + $this->foreignCardIsReadable());

        $this->handle();

        Http::assertNotSent(fn (Request $r) => self::isUnscopedCardRead($r));
        $this->assertSame('board_scope_lookup_unfiltered', $this->alerts()[0]['reason']);
    }

    /**
     * A row whose own `board_id` is NOT the mapped board is refused even though the query
     * named that board — the DL-298 rule applied to these rows: the scope is a property of
     * the DATA that came back, never of how the call was written.
     */
    public function test_a_row_naming_another_board_does_not_establish_membership(): void
    {
        Http::fake($this->alertStub() + [
            '*/tasks/search.json*' => Http::response(['data' => [['id' => self::FOREIGN_CARD, 'board_id' => 9002]]]),
        ] + $this->foreignCardIsReadable());

        $this->handle();

        Http::assertNotSent(fn (Request $r) => self::isUnscopedCardRead($r));
        $this->assertSame('board_scope_lookup_unfiltered', $this->alerts()[0]['reason']);
    }

    /**
     * ⚑ NO SILENT NARROWING ON THE ARCHIVE AXIS. kanban's search excludes archived rows
     * unless `?archived` is passed and has no both-sides mode (DL-296), so a live-only check
     * would have started refusing every archived card on the mapped board — a second
     * accept/reject change nobody asked for, riding along on this one. The archived probe is
     * made only after the live side misses, and a hit on it proceeds exactly as before.
     */
    public function test_an_archived_card_on_the_mapped_board_is_still_established_and_still_moves(): void
    {
        Http::fake([
            '*/tasks/search.json?q=board_id%3D8%20id%3D7756&limit=1' => Http::response(['data' => []]),
            '*/tasks/search.json*archived=1' => Http::response(['data' => [[
                'id' => self::FOREIGN_CARD, 'board_id' => self::BOARD, 'archived_at' => '2026-08-01T00:00:00+00:00',
            ]]]),
            '*/tasks/'.self::FOREIGN_CARD.'.json' => Http::response(['data' => [
                'id' => self::FOREIGN_CARD, 'board_id' => self::BOARD, 'workflow_stage_id' => 41,
            ]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => []]]),
        ]);

        $this->handle();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/'.self::FOREIGN_CARD.'.json')
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    /**
     * The happy path costs ONE extra request, and it is the FIRST one: the check is only a
     * boundary if nothing reads the card before it. Asserted on the recorded ORDER rather
     * than on a count, because a check that runs after the read would satisfy any count.
     */
    public function test_the_scope_lookup_precedes_the_card_read_and_is_made_once(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 5, 'board_id' => self::BOARD]]]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => self::BOARD, 'workflow_stage_id' => 41]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => []]]),
        ]);

        $this->handle(5);

        $reads = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/'))
            ->map(fn (Request $r) => str_contains($r->url(), '/search.json') ? 'scope' : 'card')
            ->values()->all();

        $this->assertSame(['scope', 'card'], $reads,
            'the board-scoped check must run BEFORE the unscoped card read, exactly once — the whole point is that '
            .'an id outside the mapping is never resolved at all');
    }

    /**
     * A TRANSIENT failure of the check is not a verdict. It throws, the dispatch 5xxs, and
     * kanban redelivers — the same transient/permanent split every other read on this path
     * has. Swallowing it would turn an outage into a silent no-move; alerting on it would
     * spend the `(repo, outcome, reason)` marker on a condition that fixes itself.
     */
    public function test_a_transient_failure_of_the_scope_lookup_throws_for_redelivery(): void
    {
        Http::fake($this->alertStub() + [
            '*/tasks/search.json*' => Http::response('upstream error', 503),
        ] + $this->foreignCardIsReadable());

        try {
            $this->handle();
            $this->fail('a 5xx on the scope lookup must propagate so the event is redelivered');
        } catch (RequestException $e) {
            $this->assertSame(503, $e->response->status());
        }

        Http::assertNotSent(fn (Request $r) => self::isUnscopedCardRead($r));
        $this->assertSame([], $this->alerts(), 'a transient failure must not spend the permanent-refusal marker');
    }

    /**
     * A PERMANENT (4xx) failure of the check is a refusal, not a retry — a 5xx on a body that
     * fails identically every time is the DL-020 storm. Its slug names the token's scope and
     * NOT a foreign card id: the query named this install's own board, so its rejection says
     * nothing about whose card the id is.
     */
    public function test_a_4xx_on_the_scope_lookup_refuses_without_a_retry_and_blames_no_one_for_the_id(): void
    {
        Http::fake($this->alertStub() + [
            '*/tasks/search.json*' => Http::response(['message' => 'forbidden'], 403),
        ] + $this->foreignCardIsReadable());

        $this->handle();

        Http::assertNotSent(fn (Request $r) => self::isUnscopedCardRead($r));
        $alerts = $this->alerts();
        $this->assertSame('boardscope_403_token_scope', $alerts[0]['reason']);
        $this->assertStringNotContainsString('foreign', $alerts[0]['reason']);
        $this->assertTrue($alerts[0]['card_id_withheld'] ?? false);
    }

    // --- card#8415: the SECOND token-resolved arm. The verdicts, the archive switch and the
    //     transient/permanent split are `MappedBoardGuard`'s and are pinned by the move legs
    //     above — one primitive, one rule. What these two legs own is that THIS arm asks it,
    //     and asks it BEFORE the unscoped read. ---

    /**
     * ⭐ THE HEADLINE LEG FOR THE OVERLAY, and the same measurement as the move handler's:
     * the foreign card read is stubbed to SUCCEED (the widened token), so "the card was never
     * read" is a property of the bridge and not of the credential. Seen to fail before the
     * fix: the overlay GETs `/tasks/7756.json`, the fixture hands another install's card over,
     * and only the post-read compare refuses it — a refusal whose precondition is the
     * cross-tenant read that already happened.
     */
    public function test_the_draft_overlay_refuses_a_foreign_card_id_with_the_unscoped_read_never_made(): void
    {
        Log::spy();
        Http::fake($this->alertStub() + [
            '*/tasks/search.json?q=board_id%3D8%20id%3D7756*' => Http::response(['data' => []]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 12]]),
        ] + $this->foreignCardIsReadable());

        $this->handleOverlay();

        Http::assertNotSent(fn (Request $r) => self::isUnscopedCardRead($r));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Http::assertSent(fn (Request $r) => self::isScopeLookup($r));

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts, 'a permanent refusal must emit exactly one live signal');
        $this->assertSame('card_id_outside_mapped_board', $alerts[0]['reason']);
        // The overlay's synthetic outcome, so this refusal cannot share a dedup tuple with the
        // move handler's identical one on the same repo (DL-274(3)).
        $this->assertSame('draft_overlay', $alerts[0]['outcome']);
        $this->assertNull($alerts[0]['card_id']);
        $this->assertTrue($alerts[0]['card_id_withheld'] ?? false);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'kanban_block_reason: REFUSED')
            && str_contains($msg, 'not on the mapped board')
            && ($ctx['card_id'] ?? null) === self::FOREIGN_CARD
            && ($ctx['mapped_board'] ?? null) === self::BOARD);
    }

    /**
     * The order, on the overlay: asserted on the recorded sequence rather than on a count,
     * because a check that ran after the read would satisfy any count. A card on the mapped
     * board still reaches the field write exactly as before — the check costs one request and
     * changes nothing on the happy path.
     */
    public function test_the_overlay_scope_lookup_precedes_the_card_read_and_the_set_still_lands(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 5, 'board_id' => self::BOARD]]]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => self::BOARD, 'block_reason' => null]]),
        ]);

        $this->handleOverlay(5);

        $reads = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/'))
            ->map(fn (Request $r) => str_contains($r->url(), '/search.json') ? 'scope' : 'card')
            ->values()->all();

        $this->assertSame(['scope', 'card'], $reads,
            'the board-scoped check must run BEFORE the unscoped card read, exactly once — the whole point is that '
            .'an id outside the mapping is never resolved at all');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r['block_reason'] === KanbanBlockReasonHandler::MARKER);
    }
}
