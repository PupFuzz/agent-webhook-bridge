<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Writeback\BoardDivergenceLedger;
use App\Bridge\Writeback\CardCollapse;
use App\Bridge\Writeback\MappedBoardGuard;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackMapping;
use App\Models\WritebackBoardDivergence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * card#7212, the DURABLE half (DL-300) — a row per writeback (card board, mapped board)
 * pair that DIVERGED, and nothing at all on the happy path.
 *
 * #556 made the pair visible on both arms. It did not make it SURVIVE: the log is
 * retention-bounded (14 days, and the receiver prunes on its own gate since DL-199), so
 * "has a cross-board write ever landed here?" was answerable for a fortnight and no
 * longer. A record that expires is an absence on a timer.
 *
 * ⛔ THE NEGATIVE LEG IS THE HARD ONE HERE, and every one of them is PAIRED. A test that
 * asserts only "no row was written" certifies whatever replaces the code — including code
 * that writes nothing anywhere, or that stopped writing to kanban at all. So each
 * happy-path leg below asserts, in the SAME measurement, that the write really happened
 * and the pair really rendered: the absence is then a measurement, not a silence.
 *
 * ⚑ WHY THE INTERVAL LEG EXISTS. The row's trigger is `! MappedBoardGuard::belongs()`, not
 * an inequality of the two rendered values, and the two are NOT the same predicate: the
 * accepted set is an interval (DL-292), so kanban answering `'8'` for a mapping of 8 is the
 * SAME board spelled differently. A ledger keyed on raw inequality would mint a row for it
 * — a permanent, unprunable record of nothing — and would still say nothing new about the
 * case that matters. Seen to fail: swapping the guard's `belongs()` call for
 * `$context['card_board'] !== $context['mapped_board']` reds that leg alone.
 */
class WritebackBoardDivergenceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const MAPPED_BOARD = 8;

    private const FOREIGN_BOARD = 12;

    private const ALERT_URL = 'http://127.0.0.1:9938/';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/divergence-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
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

    // ------------------------------------------------------------------ the divergent case

    public function test_a_write_that_lands_on_a_foreign_card_persists_one_row(): void
    {
        // A REAL Group-B write, on the primitive card#7211 named as the likeliest to touch a
        // foreign card: the shared collapse archives every non-survivor of a board-scoped
        // correlate. Its callers inside the mapped-board regime are gated by DL-298, so this
        // exercises the primitive the way an UNGATED caller would — which is exactly the state
        // this ledger exists to make answerable, and the only way to reach it on purpose.
        $this->fakeArchive();

        CardCollapse::toSurvivor(
            WritebackClientFactory::make(),
            [7 => $this->card(7, self::FOREIGN_BOARD), 9 => $this->card(9, self::FOREIGN_BOARD)],
            'kanban_dependabot_card',
            ['repo' => 'owner/repo'],
            $this->mapping(),
        );

        // The write LANDED — without this the row below would be recording an intention.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/9.json'));

        $row = $this->onlyRow();
        $this->assertSame(BoardDivergenceLedger::DISPOSITION_RECORDED, $row->disposition);
        $this->assertSame(9, $row->card_id);       // the archived non-survivor, not the survivor
        $this->assertSame('12', $row->card_board); // the card's OWN board…
        $this->assertSame(8, $row->mapped_board);  // …never an echo of the mapping
        $this->assertStringContainsString('CardCollapse::toSurvivor', (string) $row->site);
        $this->assertStringContainsString('CardCollapse.php:', (string) $row->site);
    }

    public function test_a_card_with_no_readable_board_records_the_absence(): void
    {
        // Fail-closed both ways: a row kanban handed back with no board is refused like a
        // foreign one, so it is a divergence — and the ledger records the ABSENCE rather than
        // falling back to the mapped board, which would manufacture the agreement the pair
        // exists to test.
        $this->fakeArchive();

        CardCollapse::toSurvivor(
            WritebackClientFactory::make(),
            [7 => $this->card(7, self::MAPPED_BOARD), 9 => ['id' => 9]],
            'kanban_dependabot_card',
            ['repo' => 'owner/repo'],
            $this->mapping(),
        );

        $row = $this->onlyRow();
        $this->assertNull($row->card_board);
        $this->assertSame(9, $row->card_id);
    }

    public function test_a_board_that_is_not_a_number_is_stored_verbatim(): void
    {
        // `'8abc'` is the one input `is_numeric` exists to stop (bare `(int)` would coerce it
        // onto the mapped board of 8 — the class docblock's named hole), so it is a divergence
        // AND the case where what kanban literally said matters most. Stored as it came: a
        // string goes in verbatim, so a reader querying a board id finds one spelling per
        // board, and a value that is not a board id cannot masquerade as one.
        $this->fakeArchive();

        CardCollapse::toSurvivor(
            WritebackClientFactory::make(),
            [7 => $this->card(7, self::MAPPED_BOARD), 9 => $this->card(9, '8abc')],
            'kanban_dependabot_card',
            ['repo' => 'owner/repo'],
            $this->mapping(),
        );

        $this->assertSame('8abc', $this->onlyRow()->card_board);
    }

    public function test_a_refused_write_persists_a_refused_row_and_the_card_it_wrote_persists_none(): void
    {
        // END TO END, through a real handler, on a MIXED set — card 6 foreign, card 7 mapped,
        // identical in every other respect. One delivery therefore measures both directions:
        // the refusal is durable, and the write that actually happened leaves NO row. A ledger
        // that recorded every writeback would be indistinguishable here from one that recorded
        // the divergence, which is why the pairing is in one test and not two.
        config(['bridge.writeback.correlation' => 'scan']);
        $prUrl = 'https://github.com/owner/repo/pull/42';
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/repo' => [
                'board_id' => self::MAPPED_BOARD,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
            ]],
        ]));
        Http::fake([
            self::ALERT_URL.'*' => Http::response('', 204),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 6, 'payload' => ['pr_number' => 42]],
                ['id' => 7, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/6.json' => Http::response(['data' => ['id' => 6, 'board_id' => self::FOREIGN_BOARD, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => self::MAPPED_BOARD, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);

        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', 'pr-42', payload: [
                'repo' => 'owner/repo', 'outcome' => 'merged', 'pr_number' => 42,
                'pr_title' => 'chore(deps): Bump x from 1 to 2', 'pr_url' => $prUrl,
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        // PRESENCE WITNESS — the mapped card was really written to on this same delivery.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json'));

        $row = $this->onlyRow();
        $this->assertSame(BoardDivergenceLedger::DISPOSITION_REFUSED, $row->disposition);
        $this->assertSame(6, $row->card_id);
        $this->assertSame('12', $row->card_board);
        $this->assertStringContainsString('KanbanDependabotCardHandler', (string) $row->site);
    }

    // -------------------------------------------------------------------- the happy path

    public function test_the_happy_path_persists_nothing_and_still_records_the_pair(): void
    {
        $this->fakeArchive();
        Log::spy();

        CardCollapse::toSurvivor(
            WritebackClientFactory::make(),
            [7 => $this->card(7, self::MAPPED_BOARD), 9 => $this->card(9, self::MAPPED_BOARD)],
            'kanban_dependabot_card',
            ['repo' => 'owner/repo'],
            $this->mapping(),
        );

        // PAIRED WITNESSES, both required: the archive really went out, and the record really
        // carried the pair. Alone, `assertDatabaseCount(0)` is equally consistent with a
        // writeback that did nothing at all.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/9.json'));
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'archived duplicate card')
            && $ctx['card_board'] === self::MAPPED_BOARD && $ctx['mapped_board'] === self::MAPPED_BOARD);
        $this->assertDatabaseCount('writeback_board_divergences', 0);
    }

    public function test_the_accepted_interval_is_not_a_divergence(): void
    {
        // `'8'` names the mapped board of 8 — `is_numeric` + `(int)` accepts it (DL-292), so
        // the guard would not have refused this write and the ledger must not record it. The
        // witness is the log line carrying the row's own spelling, which is what makes the
        // empty table mean "same board, different spelling" rather than "nothing ran".
        $this->fakeArchive();
        Log::spy();

        CardCollapse::toSurvivor(
            WritebackClientFactory::make(),
            [7 => $this->card(7, self::MAPPED_BOARD), 9 => $this->card(9, '8')],
            'kanban_dependabot_card',
            ['repo' => 'owner/repo'],
            $this->mapping(),
        );

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'archived duplicate card')
            && $ctx['card_board'] === '8' && $ctx['mapped_board'] === self::MAPPED_BOARD);
        $this->assertDatabaseCount('writeback_board_divergences', 0);
    }

    // ------------------------------------------------------------------------ the mechanism

    public function test_the_stored_columns_are_the_rendered_pairs_own_keys(): void
    {
        // The ledger spreads the rendered pair straight into the insert and never names a
        // column, so the record's keys ARE the columns. This is what keeps the durable row and
        // the log line the same observation; a rename on one side must be a migration on the
        // other, and this is the leg that says so out loud.
        foreach (array_keys(MappedBoardGuard::boardContext(['board_id' => self::MAPPED_BOARD], $this->mapping())) as $key) {
            $this->assertTrue(
                Schema::hasColumn('writeback_board_divergences', (string) $key),
                "the record key '{$key}' has no column — the pair and the ledger have drifted apart",
            );
        }
    }

    public function test_a_persistence_failure_is_logged_and_never_thrown(): void
    {
        // This runs mid-writeback — after kanban has already been written to, and on the
        // refusal arm whose contract is a permanent no-op. An escaping throw would turn a
        // completed write into a 5xx and have the provider redeliver it, so the audit row is
        // the thing that gives way, LOUDLY: the same rendered pair goes to the log, which is
        // the record that still has 14 days. (No `Schema::drop` — DDL commits the
        // RefreshDatabase transaction and takes the table away from every later test.)
        $default = config('database.default');
        Log::spy();

        try {
            config(['database.default' => 'no-such-connection']);
            $context = MappedBoardGuard::boardContext($this->card(9, self::FOREIGN_BOARD), $this->mapping());
        } finally {
            config(['database.default' => $default]);
        }

        $this->assertSame(['card_board' => self::FOREIGN_BOARD, 'mapped_board' => self::MAPPED_BOARD], $context);
        Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'could not be persisted')
            && $ctx['card_board'] === self::FOREIGN_BOARD && $ctx['mapped_board'] === self::MAPPED_BOARD);
    }

    public function test_bridge_stats_prints_the_divergence_counts_including_the_zero(): void
    {
        // The reader's surface. The count is printed on every run, zero included: a line that
        // appeared only when non-empty would make "no cross-board write was ever recorded"
        // indistinguishable from "nothing measured it" — this card's own defect, one level up.
        $this->artisan('bridge:stats')
            ->expectsOutputToContain('reached a write site')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------------------------ helpers

    private function mapping(): WritebackMapping
    {
        return new WritebackMapping(self::MAPPED_BOARD, ['merged' => 52]);
    }

    /** @return array<string, mixed> */
    private function card(int $id, mixed $board): array
    {
        return ['id' => $id, 'board_id' => $board, 'workflow_stage_id' => 50];
    }

    /** Every archive answers "archived", so the collapse takes its success arm. */
    private function fakeArchive(): void
    {
        Http::fake(['*/tasks/*.json' => Http::response(['data' => ['id' => 9, 'archived_at' => '2026-08-22T00:00:00+00:00']])]);
    }

    private function onlyRow(): WritebackBoardDivergence
    {
        $rows = WritebackBoardDivergence::query()->get();
        $this->assertCount(1, $rows, 'exactly one observation was divergent in this delivery');

        return $rows->first();
    }
}
