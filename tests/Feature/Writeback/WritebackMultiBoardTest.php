<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Writeback\MappedBoardGuard;
use App\Models\WritebackBoardDivergence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A REPO MAPS TO ONE BOARD, BUT A COORDINATION PR CITES CARDS ON SEVERAL — so the destination
 * board is resolved FROM THE CARD, out of a set the operator DECLARES (card#9850 / DL-404).
 *
 * ⛔ THE DEFECT THIS CLASS MAKES FALSIFIABLE, measured on two independent installs before any
 * code was written. `writeback.json` keys its mapping BY REPO and each mapping carried exactly
 * one `board_id` plus one stage map. A coordination repo's own `[TASK]` pull requests cite
 * cards on the SPRINT boards — not on the coordination board its mapping names — so one repo →
 * one board could not express the right destination. Every such merge wrote (or, where the
 * mapping carries no `stages` at all, wrote nothing) and NEVER TOUCHED THE SPRINT CARD ITS
 * BRANCH CITED. The board then reports as unfinished work that is merged and shipped, and the
 * sprint burn-down is derived from that board.
 *
 * ⭐ THE THREE CELLS, and why they are three rather than one:
 *  1. {@see test_a_cited_card_on_another_board_is_silently_ignored_under_a_single_board_mapping}
 *     — the PRE-FIX SHAPE, measured rather than asserted, on a mapping written exactly as every
 *     mapping was written before the `boards` key existed. It is also the compatibility cell:
 *     the ruling's non-negotiable is that such a mapping behaves EXACTLY as it does today (less
 *     the two stated changes docs/writeback.md § Optional: a repo whose PRs cite cards on
 *     SEVERAL boards names, neither on this input), and
 *     "exactly as today" here means the silent no-op — so this leg pins the defect and the
 *     compatibility guarantee with one measurement.
 *  2. {@see test_the_declared_board_the_card_is_on_supplies_the_stage_and_the_move_lands} — the
 *     fix: the same fixture under a multi-board mapping moves the cited card, on ITS board, to
 *     THAT board's own stage id. Stage ids are per-board arbitrary integers, so this asserts
 *     the id, the board in the board-order read, and the board-scoped lookup order.
 *  3. {@see test_a_card_on_no_declared_board_is_refused_and_the_refusal_names_only_what_it_checked}
 *     — the refusal, which is the half that makes the fix safe to ship: a miss across the
 *     declared set REFUSES rather than falling back to the repo's mapped board, because a
 *     silent write to the wrong board is worse than no write (it moves *a* card, just not the
 *     cited one). That the refusal is TERMINAL — no write however the rest of the fixture would
 *     answer — is pinned separately, by
 *     {@see test_a_refusal_across_the_declared_set_sends_no_write_even_where_the_mapped_board_maps_the_outcome},
 *     on a mapping whose mapped board does map the outcome.
 *
 * ⛔⛔ CELL 3 ALSO PINS A SECURITY BOUNDARY, and it is the one a future author will be tempted
 * to "improve". The refusal names the boards it CHECKED — a measurement — and never the board
 * the card is actually on. Learning that would take an unscoped read of an author-supplied id
 * against a GLOBAL kanban id space (card#8375), and "but it is only logged" is not a mitigation:
 * LOGGING IS THE LEAK. The assertion is written as a negative on the true board id precisely so
 * that adding such a read to enrich the message reds here.
 */
class WritebackMultiBoardTest extends TestCase
{
    use RefreshDatabase;

    private const ALERT_URL = 'http://127.0.0.1:9971/';

    /** The repo's mapped board — the coordination board, which holds none of the cited sprint cards. */
    private const COORD_BOARD = 2;

    /** A sprint board this repo's PRs really do cite cards on. */
    private const SPRINT_BOARD = 13;

    /** That board's OWN `merged` stage id. Unrelated to any stage id on any other board. */
    private const SPRINT_MERGED_STAGE = 97;

    /** That board's OWN `opened` stage id — where the card sits before the merge. */
    private const SPRINT_OPENED_STAGE = 96;

    /** That board's OWN `started` (In Progress) stage id. */
    private const SPRINT_STARTED_STAGE = 95;

    /** That board's OWN backlog stage — the stage a `started` move may promote a card FROM. */
    private const SPRINT_BACKLOG_STAGE = 94;

    /** That board's OWN parked stage — the stage a `started` move may UNPARK a pinned card from. */
    private const SPRINT_PARKED_STAGE = 93;

    /** The MAPPED board's own backlog stage: a promote-from id that means nothing on board 13. */
    private const COORD_BACKLOG_STAGE = 30;

    /** The card the branch cites. It lives on {@see SPRINT_BOARD}. */
    private const CITED_CARD = 9451;

    /** A board this install declares nowhere — cell 3's card is on it, and no output may name it. */
    private const UNDECLARED_BOARD = 9002;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wbmulti-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * The mapping shape EVERY install had before the `boards` key existed: one repo, one board,
     * and — on a coordination repo — no PR-outcome stage map at all, because the coordination
     * board has no PR lifecycle. Measured on this shape on the aimla install
     * (`AIMLA-org/aimla-coordination` → board 10) and on the sola install
     * (`BWtek-Medical/sola-coordination` → board 2).
     */
    private function writeSingleBoardMapping(): void
    {
        $this->writeMapping([
            'board_id' => self::COORD_BOARD,
            'create_coord_cards' => true,
            'coord_card_stage_id' => 21,
        ]);
    }

    /** The same mapping, opted in to the declared-board set the fix reads. */
    private function writeMultiBoardMapping(): void
    {
        $this->writeMapping([
            'board_id' => self::COORD_BOARD,
            'create_coord_cards' => true,
            'coord_card_stage_id' => 21,
            'boards' => [
                (string) self::SPRINT_BOARD => [
                    'opened' => self::SPRINT_OPENED_STAGE,
                    'merged' => self::SPRINT_MERGED_STAGE,
                ],
            ],
        ]);
    }

    /** @param  array<string, mixed>  $mapping */
    private function writeMapping(array $mapping): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/coord-repo' => $mapping],
        ]));
    }

    private function handleMerge(int $cardId = self::CITED_CARD): void
    {
        $this->handleOutcome('merged', $cardId);
    }

    private function handleOutcome(string $outcome, int $cardId = self::CITED_CARD): void
    {
        (new KanbanMoveCardHandler)->handle(
            ReactionTarget::make('kanban_move_card', (string) $cardId, payload: [
                'card_id' => $cardId, 'repo' => 'owner/coord-repo', 'outcome' => $outcome,
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
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

    /**
     * The board-scoped lookups this delivery issued, in order, as board ids — the derivation
     * the resolution's cost and its ORDER are both read off. `id=` distinguishes a membership
     * lookup from the `visibility` control, which carries the board term alone.
     *
     * @return list<int>
     */
    private function scopedLookupBoards(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/search.json')
                && str_contains(urldecode($r->url()), ' id='))
            ->map(fn (Request $r) => (int) (preg_match('/board_id=(\d+)/', urldecode($r->url()), $m) === 1 ? $m[1] : 0))
            ->values()->all();
    }

    // ------------------------------------------------------------------------------------
    // CELL 1 — the PRE-FIX shape, and the compatibility guarantee, in one measurement.
    // ------------------------------------------------------------------------------------

    /**
     * ⛔ THE DEFECT. A merge of a coordination-repo PR citing a card on the sprint board, under
     * the single-board mapping every install had: the move is a SILENT no-op. Nothing is
     * PATCHed, nothing is alerted, and the only trace is an `info` line that reads as a
     * deliberately untracked outcome rather than as a cited card left behind.
     *
     * ⭐ It is asserted on the ABSENCE OF THE WRITE and on the exact log line, not on a
     * screenshot of a board: `moveCard()` is the one verb that moves a card, and it is reached
     * through `PATCH /tasks/{id}.json`. That there is no request AT ALL is the sharper half —
     * the handler returns before a client is even built, which is why no alert fires and why
     * this went unnoticed for as long as it did.
     *
     * ⚑ This leg is ALSO the compatibility cell required by the ruling: a mapping carrying no
     * `boards` key must behave EXACTLY as it does today (less the two stated changes
     * docs/writeback.md § Optional: a repo whose PRs cite cards on SEVERAL boards names, neither
     * on this input), and today's behaviour on this input is
     * precisely this silent return. It reds if the multi-board path ever starts running on a
     * mapping that did not opt in.
     */
    public function test_a_cited_card_on_another_board_is_silently_ignored_under_a_single_board_mapping(): void
    {
        Log::spy();
        $this->writeSingleBoardMapping();
        Http::fake($this->alertStub() + [
            // Deliberately permissive: if the handler reached kanban at all, it would get a
            // helpful answer and the assertions below would have to earn their keep against a
            // WORKING backend rather than against a fixture that refuses everything.
            '*' => Http::response(['data' => [['id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD]]]),
        ]);

        $this->handleMerge();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Http::assertNothingSent();
        $this->assertSame([], $this->alerts(), 'the defect is SILENT — a cited card left behind raises nothing');
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $msg, array $ctx) => $msg === 'kanban_move_card: no stage mapped for outcome; ignoring'
                && ($ctx['card_id'] ?? null) === self::CITED_CARD,
        );
    }

    // ------------------------------------------------------------------------------------
    // CELL 2 — the fix: the card supplies the board, the board supplies the stage.
    // ------------------------------------------------------------------------------------

    /**
     * ⭐ THE FIX. The same repo, the same PR, the same card — with the sprint board DECLARED.
     * The card is established on board 13 by a board-scoped lookup, and the stage written is
     * board 13's OWN `merged` id. That last clause is the whole reason a declared board carries
     * a stage map rather than being a bare id: stage ids are per-board arbitrary integers, so
     * swapping the board while keeping one flat stage map would write a confident, meaningless
     * number.
     *
     * The assertions cover the three things that must all move together, because any one of
     * them alone is satisfiable by a wrong implementation:
     *  - the PATCH names the CARD and board 13's stage id (not the coordination board's);
     *  - the board-order read the no-regression guard makes is of board 13 — the narrowing
     *    reached every downstream read, not just the compare;
     *  - the lookups are board-scoped and in declared order, mapped board FIRST, so the
     *    single-board happy path keeps costing exactly one request.
     */
    public function test_the_declared_board_the_card_is_on_supplies_the_stage_and_the_move_lands(): void
    {
        $this->writeMultiBoardMapping();
        Http::fake($this->alertStub() + [
            '*/tasks/search.json?q=board_id%3D'.self::COORD_BOARD.'%20id%3D'.self::CITED_CARD.'*' => Http::response(['data' => []]),
            '*/tasks/search.json?q=board_id%3D'.self::SPRINT_BOARD.'%20id%3D'.self::CITED_CARD.'*' => Http::response(['data' => [
                ['id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD],
            ]]),
            '*/tasks/'.self::CITED_CARD.'.json' => Http::response(['data' => [
                'id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD,
                'workflow_stage_id' => self::SPRINT_OPENED_STAGE, 'block_reason' => null, 'tags' => [],
            ]]),
            '*/boards/'.self::SPRINT_BOARD.'/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [
                    ['id' => self::SPRINT_OPENED_STAGE, 'position' => 3.0],
                    ['id' => self::SPRINT_MERGED_STAGE, 'position' => 4.0],
                ]],
            ]]]),
        ]);

        $this->handleMerge();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/'.self::CITED_CARD.'.json')
            && $r->data() === ['workflow_stage_id' => self::SPRINT_MERGED_STAGE]);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/boards/'.self::SPRINT_BOARD.'/preload.json'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/boards/'.self::COORD_BOARD.'/preload.json'));
        $this->assertSame(
            [self::COORD_BOARD, self::SPRINT_BOARD],
            $this->scopedLookupBoards(),
            'the declared boards must be asked in order, mapped board first, each with its own BOARD-SCOPED '
            .'lookup — never one unscoped read of the card to ask it which board it is on (card#8375)',
        );
        $this->assertSame([], $this->alerts(), 'a move that lands on the cited card raises nothing');
    }

    /**
     * The narrowing is not cosmetic: the mapped board stays FIRST, so a mapping whose cards do
     * live on its own board still resolves in ONE request and writes its own stage id. Without
     * this the multi-board path could be "correct" while making every single-board install pay
     * for boards it does not have — and it pins the direction of the fallback, which is that
     * there is none: board 2 is a declared board like any other, not a destination of last
     * resort.
     */
    public function test_a_card_on_the_mapped_board_still_resolves_first_and_writes_that_boards_stage(): void
    {
        $this->writeMapping([
            'board_id' => self::COORD_BOARD,
            'stages' => ['merged' => 22],
            'boards' => [(string) self::SPRINT_BOARD => ['merged' => self::SPRINT_MERGED_STAGE]],
        ]);
        Http::fake($this->alertStub() + [
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 5, 'board_id' => self::COORD_BOARD]]]),
            '*/tasks/5.json' => Http::response(['data' => [
                'id' => 5, 'board_id' => self::COORD_BOARD, 'workflow_stage_id' => 20, 'block_reason' => null, 'tags' => [],
            ]]),
            '*/boards/'.self::COORD_BOARD.'/preload.json' => Http::response(['data' => ['workflows' => []]]),
        ]);

        $this->handleMerge(5);

        $this->assertSame([self::COORD_BOARD], $this->scopedLookupBoards(),
            'a hit on the first declared board must not go on to probe the rest');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r->data() === ['workflow_stage_id' => 22]);
    }

    /**
     * A declared board need not map every outcome, and the handler must read that off the
     * RESOLVED board rather than off the union it used as a cheap pre-check. Here the sprint
     * board maps `merged` only, so a `closed_unmerged` event resolves the card and then writes
     * nothing — rather than reaching for the coordination board's stage map, which would be a
     * stage id from the wrong board.
     */
    public function test_a_resolved_board_that_does_not_map_this_outcome_writes_nothing(): void
    {
        Log::spy();
        $this->writeMapping([
            'board_id' => self::COORD_BOARD,
            'stages' => ['closed_unmerged' => 20],
            'boards' => [(string) self::SPRINT_BOARD => ['merged' => self::SPRINT_MERGED_STAGE]],
        ]);
        Http::fake($this->alertStub() + [
            '*/tasks/search.json?q=board_id%3D'.self::COORD_BOARD.'*' => Http::response(['data' => []]),
            '*/tasks/search.json?q=board_id%3D'.self::SPRINT_BOARD.'*' => Http::response(['data' => [
                ['id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD],
            ]]),
            '*' => Http::response(['data' => ['id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD]]),
        ]);

        (new KanbanMoveCardHandler)->handle(
            ReactionTarget::make('kanban_move_card', (string) self::CITED_CARD, payload: [
                'card_id' => self::CITED_CARD, 'repo' => 'owner/coord-repo', 'outcome' => 'closed_unmerged',
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $msg, array $ctx) => $msg === 'kanban_move_card: no stage mapped for this outcome on the declared board the card is on; ignoring'
                && ($ctx['board'] ?? null) === self::SPRINT_BOARD,
        );
    }

    // ------------------------------------------------------------------------------------
    // CELL 3 — the refusal, and the two things it must never do.
    // ------------------------------------------------------------------------------------

    /**
     * ⛔ A MISS ACROSS THE DECLARED SET REFUSES — it never falls back to the repo's mapped
     * board. A silent write to the wrong board is worse than no write: it moves *a* card, just
     * not the cited one, and the board then asserts something that was never true.
     *
     * ⛔⛔ AND THE REFUSAL NAMES ONLY WHAT IT CHECKED. The declared boards are a measurement and
     * are named — an operator whose `boards` list is missing a board this repo cites needs
     * exactly that list to see what is absent. The board the card is REALLY on was never
     * measured: the only way to learn it is an unscoped read of an author-supplied id against a
     * kanban id space that is GLOBAL across the instance (card#8375), and a value that reaches
     * a log has already crossed that boundary onto a durable surface. So the last assertion is
     * a NEGATIVE on the true board id — it reds the moment somebody adds a diagnostic read to
     * make the message more helpful.
     *
     * ⚑ NO DIVERGENCE ROW, and by construction rather than by omission. A
     * `writeback_board_divergences` row asserts a RELATIONSHIP — *this card is on that board
     * instead of this one* — and a miss across N boards establishes only *not in the declared
     * set*. Writing one would put a claim in the ledger wider than its evidence, for a later
     * reader to take as ground; the refusal IS the record.
     */
    public function test_a_card_on_no_declared_board_is_refused_and_the_refusal_names_only_what_it_checked(): void
    {
        Log::spy();
        $this->writeMultiBoardMapping();
        Http::fake($this->alertStub() + [
            // Neither declared board answers a row for this id, live or archived…
            '*/tasks/search.json?q=board_id%3D'.self::COORD_BOARD.'%20id%3D*' => Http::response(['data' => []]),
            '*/tasks/search.json?q=board_id%3D'.self::SPRINT_BOARD.'%20id%3D*' => Http::response(['data' => []]),
            // …while BOTH read back populated, which is the control that earns the strong
            // verdict: a token that had lost a board would otherwise accuse every PR author.
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 12]]),
            // The card read that MUST NOT happen, stubbed to SUCCEED — a widened token handing
            // the foreign card over, board and all. "The card was never read" is then a
            // measurement of this code and not of what the credential could reach.
            '*/tasks/7756.json' => Http::response(['data' => [
                'id' => 7756, 'board_id' => self::UNDECLARED_BOARD, 'workflow_stage_id' => 41,
            ]]),
        ]);

        $this->handleMerge(7756);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/7756.json'));
        $this->assertSame([self::COORD_BOARD, self::SPRINT_BOARD, self::COORD_BOARD, self::SPRINT_BOARD],
            $this->scopedLookupBoards(),
            'every declared board is asked live, and only then is the archive side of each asked (DL-296)');

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts, 'a permanent refusal emits exactly one live signal');
        $this->assertSame(MappedBoardGuard::REASON_ID_OUTSIDE_DECLARED_BOARDS, $alerts[0]['reason']);
        $this->assertNull($alerts[0]['card_id'], 'DL-314: an id never established as ours does not ride the channel');
        $this->assertTrue($alerts[0]['card_id_withheld'] ?? false);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $msg, array $ctx): bool {
            $this->assertStringContainsString('REFUSED', $msg);
            $this->assertStringContainsString('2, 13', $msg, 'the refusal must name the declared set it checked — that is a measurement, and it is the operator\'s only route to a missing `boards` entry');
            $this->assertStringNotContainsString((string) self::UNDECLARED_BOARD, $msg, 'the refusal must NEVER name the board the card is really on: it was not measured, and learning it takes the unscoped read of an author-supplied id this whole check exists to prevent. Logging IS the leak');
            $this->assertSame([self::COORD_BOARD, self::SPRINT_BOARD], $ctx['declared_boards'] ?? null);
            $this->assertNotContains(self::UNDECLARED_BOARD, $ctx['declared_boards'] ?? []);

            return true;
        });

        $this->assertSame(0, WritebackBoardDivergence::query()->count(),
            'a miss across the declared set establishes "not in this set" and NOTHING about where the card is, '
            .'so it must write no divergence row — that row asserts a relationship, and this refusal measured none');
    }

    /**
     * ⛔ THE REFUSAL IS TERMINAL — asserted on a fixture where carrying on WOULD write. The leg
     * above cannot show that: its mapped board maps no `merged` stage, so a fallback onto the
     * mapped board returns at `stageFor('merged') === null` and its no-PATCH assertion has no
     * way to fail. Here the mapped board DOES map `merged`, so every stage lookup a fallback or
     * a report-then-carry-on would make answers with a real stage id.
     *
     * The card is on no declared board, and the unscoped read this refusal exists to prevent is
     * stubbed to SUCCEED, in two shapes — because the post-read {@see MappedBoardGuard::refuses}
     * compare is a second line behind this one, and a single fixture only exercises one of them:
     *  - `undeclared board`: the read hands back the card on the board it is really on. A
     *    fallback is caught by the post-read compare, so what reds is the unscoped READ itself
     *    (and the alert reason, and the divergence row a read-then-refuse would mint).
     *  - `mapped board, moved after the lookups`: the read hands back the card on the MAPPED
     *    board (it moved between the board-scoped lookups and the read, or the read is not the
     *    authority the lookups are). The post-read compare now PASSES, so the only thing standing
     *    between the author-supplied id and a write is this refusal returning — and the
     *    no-write assertion is the one that reds.
     */
    #[DataProvider('whereAnUnscopedReadWouldPlaceTheCard')]
    public function test_a_refusal_across_the_declared_set_sends_no_write_even_where_the_mapped_board_maps_the_outcome(int $readBoard): void
    {
        $this->writeMapping([
            'board_id' => self::COORD_BOARD,
            'stages' => ['opened' => 20, 'merged' => 22],
            'boards' => [(string) self::SPRINT_BOARD => [
                'opened' => self::SPRINT_OPENED_STAGE, 'merged' => self::SPRINT_MERGED_STAGE,
            ]],
        ]);
        Http::fake($this->alertStub() + [
            '*/tasks/search.json?q=board_id%3D'.self::COORD_BOARD.'%20id%3D*' => Http::response(['data' => []]),
            '*/tasks/search.json?q=board_id%3D'.self::SPRINT_BOARD.'%20id%3D*' => Http::response(['data' => []]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 12]]),
            '*/tasks/7756.json' => Http::response(['data' => [
                'id' => 7756, 'board_id' => $readBoard, 'workflow_stage_id' => 20, 'block_reason' => null, 'tags' => [],
            ]]),
            '*/boards/*/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 20, 'position' => 1.0], ['id' => 22, 'position' => 2.0],
            ]]]]]),
        ]);

        $this->handleMerge(7756);

        Http::assertNotSent(fn (Request $r) => ! str_starts_with($r->url(), self::ALERT_URL)
            && in_array($r->method(), ['PATCH', 'POST', 'PUT', 'DELETE'], true));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/7756.json'));
        $alerts = $this->alerts();
        $this->assertCount(1, $alerts, 'a permanent refusal emits exactly one live signal');
        $this->assertSame(MappedBoardGuard::REASON_ID_OUTSIDE_DECLARED_BOARDS, $alerts[0]['reason']);
        $this->assertSame(0, WritebackBoardDivergence::query()->count());
    }

    /** @return array<string, array{int}> */
    public static function whereAnUnscopedReadWouldPlaceTheCard(): array
    {
        return [
            'undeclared board' => [self::UNDECLARED_BOARD],
            'mapped board, moved after the lookups' => [self::COORD_BOARD],
        ];
    }

    /**
     * The CONTROL that keeps cell 3's verdict honest, generalised to N boards: with ONE declared
     * board unreadable the same empty answer must reach the WEAKER verdict. The strong verdict
     * claims the declared SET was checked and the id was in none of it, and a board this token
     * cannot see leaves that claim unearned — so a partially-blind token must not be allowed to
     * accuse the PR author (canon #10: a wrong-but-specific cause is worse than an honest
     * generic one).
     */
    public function test_one_unreadable_declared_board_reaches_the_weaker_verdict_rather_than_accusing_the_id(): void
    {
        $this->writeMultiBoardMapping();
        Http::fake($this->alertStub() + [
            '*/tasks/search.json?q=board_id%3D'.self::COORD_BOARD.'%20id%3D*' => Http::response(['data' => []]),
            '*/tasks/search.json?q=board_id%3D'.self::SPRINT_BOARD.'%20id%3D*' => Http::response(['data' => []]),
            // The coordination board reads back; the sprint board answers empty — the blind-token
            // shape (DL-026), on ONE member of the set.
            '*/tasks/search.json?q=board_id%3D'.self::SPRINT_BOARD.'*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 12]]),
            '*/tasks/7756.json' => Http::response(['data' => ['id' => 7756, 'board_id' => self::UNDECLARED_BOARD]]),
        ]);

        $this->handleMerge(7756);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/7756.json'));
        $this->assertSame(MappedBoardGuard::REASON_DECLARED_BOARD_UNREADABLE, $this->alerts()[0]['reason']);
    }

    /**
     * A board this token cannot even ASK has not answered "no" — so a permanent 4xx on one
     * declared board's lookup must not black-hole a card that another declared board
     * establishes. The positive establishment is a measurement standing on its own; refusing
     * over it would read one board's lapsed membership as a verdict about every card the repo
     * cites.
     */
    public function test_a_4xx_on_one_declared_board_does_not_prevent_establishing_the_card_on_another(): void
    {
        $this->writeMultiBoardMapping();
        Http::fake($this->alertStub() + [
            '*/tasks/search.json?q=board_id%3D'.self::COORD_BOARD.'*' => Http::response(['message' => 'forbidden'], 403),
            '*/tasks/search.json?q=board_id%3D'.self::SPRINT_BOARD.'*' => Http::response(['data' => [
                ['id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD],
            ]]),
            '*/tasks/'.self::CITED_CARD.'.json' => Http::response(['data' => [
                'id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD,
                'workflow_stage_id' => self::SPRINT_OPENED_STAGE, 'block_reason' => null, 'tags' => [],
            ]]),
            '*/boards/'.self::SPRINT_BOARD.'/preload.json' => Http::response(['data' => ['workflows' => []]]),
        ]);

        $this->handleMerge();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['workflow_stage_id' => self::SPRINT_MERGED_STAGE]);
        $this->assertSame([], $this->alerts(), 'a board that could not be asked is not a refusal when another board answered');
    }

    // ------------------------------------------------------------------------------------
    // CELL 4 — the `started` move: a declared board promotes from ITS OWN sets (r1 MAJOR).
    // ------------------------------------------------------------------------------------

    /**
     * The cited card sits on board 13 in board 13's backlog stage, and board 13 names that
     * stage in ITS OWN `started_from_stages`, so a branch-create `started` moves it to board
     * 13's own In Progress id. The mapped board's promote-from set (30) is a board-2 stage id;
     * stage ids are a global auto-increment, so no board-13 card is ever in it — which is why
     * narrowing the stage map alone left `boards.<id>.started` loadable and permanently inert.
     */
    public function test_a_started_move_onto_a_declared_board_promotes_from_that_boards_own_set(): void
    {
        $this->writeMapping([
            'board_id' => self::COORD_BOARD,
            'stages' => ['started' => 31],
            'started_from_stages' => [self::COORD_BACKLOG_STAGE],
            'boards' => [(string) self::SPRINT_BOARD => [
                'started' => self::SPRINT_STARTED_STAGE,
                'opened' => self::SPRINT_OPENED_STAGE,
                'started_from_stages' => [self::SPRINT_BACKLOG_STAGE],
            ]],
        ]);
        $this->fakeSprintCardAt(self::SPRINT_BACKLOG_STAGE);

        $this->handleOutcome('started');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/'.self::CITED_CARD.'.json')
            && $r->data() === ['workflow_stage_id' => self::SPRINT_STARTED_STAGE]);
        $this->assertSame([self::COORD_BOARD, self::SPRINT_BOARD], $this->scopedLookupBoards());
    }

    /**
     * The same move under a PINNED card in board 13's own `unpark_from_stages`: the DL-194
     * override applies on the board the card is on, from that board's own set, and alerts.
     */
    public function test_an_unpark_stage_on_a_declared_board_promotes_a_pinned_card_from_that_boards_own_set(): void
    {
        $this->writeMapping([
            'board_id' => self::COORD_BOARD,
            'stages' => ['started' => 31],
            'started_from_stages' => [self::COORD_BACKLOG_STAGE],
            'boards' => [(string) self::SPRINT_BOARD => [
                'started' => self::SPRINT_STARTED_STAGE,
                'started_from_stages' => [self::SPRINT_BACKLOG_STAGE],
                'unpark_from_stages' => [self::SPRINT_PARKED_STAGE],
            ]],
        ]);
        $this->fakeSprintCardAt(self::SPRINT_PARKED_STAGE, blockReason: 'waiting on the vendor');

        $this->handleOutcome('started');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['workflow_stage_id' => self::SPRINT_STARTED_STAGE]);
        $this->assertSame(['auto_unparked'], array_column($this->alerts(), 'reason'),
            'the override of a human hold is compensated by the unpark alert on this board exactly as on the mapped one');
    }

    /**
     * ⛔ THE CARRY-FORWARD ITSELF, pinned from the other side. The operator's only workaround
     * before per-board sets existed was to put board-13 ids into the TOP-LEVEL list — and that
     * list is the mapped board's, which `bridge:check` validates against board 2. A declared
     * board that names no promote-from set of its own must therefore refuse a `started` move
     * (the DL-160 fail-closed default), never borrow the mapped board's set, however the ids
     * in it happen to line up.
     *
     * @param  array<string, list<int>>  $topLevel  the mapped board's set that must not leak
     */
    #[DataProvider('mappedBoardSets')]
    public function test_the_mapped_boards_sets_never_authorize_a_started_move_on_another_board(array $topLevel, ?string $blockReason): void
    {
        Log::spy();
        $this->writeMapping([
            'board_id' => self::COORD_BOARD,
            'stages' => ['started' => 31],
            ...$topLevel,
            'boards' => [(string) self::SPRINT_BOARD => ['started' => self::SPRINT_STARTED_STAGE]],
        ]);
        $this->fakeSprintCardAt(self::SPRINT_BACKLOG_STAGE, $blockReason);

        $this->handleOutcome('started');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH'
            && array_key_exists('workflow_stage_id', $r->data()));
        $this->assertSame([self::COORD_BOARD, self::SPRINT_BOARD], $this->scopedLookupBoards(),
            'WITNESS: the card was established on board 13, so the no-move is the promote-from decision and not an earlier refusal');
    }

    /** @return array<string, array{0: array<string, list<int>>, 1: ?string}> */
    public static function mappedBoardSets(): array
    {
        return [
            'started_from_stages' => [['started_from_stages' => [self::COORD_BACKLOG_STAGE, self::SPRINT_BACKLOG_STAGE]], null],
            'unpark_from_stages (pinned card)' => [['started_from_stages' => [self::COORD_BACKLOG_STAGE], 'unpark_from_stages' => [self::SPRINT_BACKLOG_STAGE]], 'waiting on the vendor'],
        ];
    }

    /**
     * minor 1 (r1): narrowing must not change what `mapped_board` MEANS. It is the repo's
     * configured board — the value `writeback.json` says and ledger readers key on — and the
     * board the write resolved onto is a SEPARATE key, `declared_board`.
     */
    public function test_a_move_on_a_declared_board_logs_the_mapped_board_and_the_declared_board_apart(): void
    {
        Log::spy();
        $this->writeMultiBoardMapping();
        $this->fakeSprintCardAt(self::SPRINT_OPENED_STAGE);

        $this->handleMerge();

        $moved = null;
        Log::shouldHaveReceived('info')->withArgs(function (string $msg, array $ctx) use (&$moved): bool {
            if ($msg !== 'kanban_move_card: moved') {
                return false;
            }
            $moved = $ctx;

            return true;
        })->once();
        $this->assertSame(
            ['card_board' => self::SPRINT_BOARD, 'mapped_board' => self::COORD_BOARD, 'declared_board' => self::SPRINT_BOARD],
            array_intersect_key($moved ?? [], array_flip(['card_board', 'mapped_board', 'declared_board'])),
            '`mapped_board` stays the repo\'s configured board; the board the write resolved onto is `declared_board`',
        );
    }

    /** The cited card on board 13, at $stage, established there by the board-scoped lookup. */
    private function fakeSprintCardAt(int $stage, ?string $blockReason = null): void
    {
        Http::fake($this->alertStub() + [
            '*/tasks/search.json?q=board_id%3D'.self::COORD_BOARD.'%20id%3D'.self::CITED_CARD.'*' => Http::response(['data' => []]),
            '*/tasks/search.json?q=board_id%3D'.self::SPRINT_BOARD.'%20id%3D'.self::CITED_CARD.'*' => Http::response(['data' => [
                ['id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD],
            ]]),
            '*/tasks/'.self::CITED_CARD.'.json' => Http::response(['data' => [
                'id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD,
                'workflow_stage_id' => $stage, 'block_reason' => $blockReason, 'tags' => [],
            ]]),
            '*/boards/'.self::SPRINT_BOARD.'/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [
                    ['id' => self::SPRINT_OPENED_STAGE, 'position' => 3.0],
                    ['id' => self::SPRINT_MERGED_STAGE, 'position' => 4.0],
                ]],
            ]]]),
        ]);
    }
}
