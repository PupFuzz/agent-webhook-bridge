<?php

namespace Tests\Unit\Tools;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Tools\ConfigSeenLedger;
use App\Models\BoardToolsConfigSeen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\UsesUnmigratedDatabase;
use Tests\TestCase;

/**
 * The write primitive behind the config-seen row (card#8973 / DL-360).
 *
 * ⭐ WHAT THIS CLASS HAS TO PROVE is not that a row appears — it is that the row's THREE
 * independent stamps move independently, because each one carries a different claim the LOST
 * line makes to an operator:
 *   - `first_seen_at` must NOT move on a repeat sighting, or the window the line prints
 *     collapses to "just now" and stops distinguishing a seat that ran for a year from one
 *     configured this morning — and it must BE STAMPED where it is still NULL, or a row born
 *     by a retirement and revived by a later sighting keeps no left edge at all and the LOST
 *     line prints a headless window;
 *   - `last_seen_at` MUST move, or the window's right edge is the install date;
 *   - the two tombstone columns must CLEAR on an enabled sighting and must NOT erase the seen
 *     window when a retirement lands.
 * A test asserting only that one row exists would pass against a writer that got every one of
 * those backwards.
 *
 * ⚑ THE TOMBSTONE-CLEAR TEST IS ALSO THE MariaDB EVIDENCE. `upsert()`'s update list compiles
 * to `col = values(col)` on MySQL, which reads the INSERT row — so a column named in the
 * update list but absent from that row would clear to the column default rather than to what
 * the writer meant. The two NULLs are written explicitly for that reason, and this is the leg
 * that would red if someone dropped them; it runs on the MariaDB matrix as well as here.
 */
class ConfigSeenLedgerTest extends TestCase
{
    use RefreshDatabase;
    use UsesUnmigratedDatabase;

    public function test_the_first_sighting_stamps_both_ends_of_the_window(): void
    {
        ConfigSeenLedger::recordEnabled('impl', $this->enabled());

        $row = BoardToolsConfigSeen::query()->where('agent', 'impl')->sole();

        $this->assertNotNull($row->first_seen_at);
        $this->assertNotNull($row->last_seen_at);
        $this->assertSame('ssh', $row->transport);
        $this->assertSame(10, $row->board_id);
        $this->assertSame(4, $row->swimlane_id);
        $this->assertNull($row->retired_seen_at);
        $this->assertNull($row->retired_reason);
    }

    public function test_a_second_sighting_moves_only_the_right_edge_of_the_window(): void
    {
        $this->travelTo(now()->subDays(30));
        ConfigSeenLedger::recordEnabled('impl', $this->enabled());
        $first = BoardToolsConfigSeen::query()->where('agent', 'impl')->sole();
        $firstSeen = $first->first_seen_at;
        $lastSeen = $first->last_seen_at;

        $this->travelBack();
        ConfigSeenLedger::recordEnabled('impl', $this->enabled(boardId: 99));

        $rows = BoardToolsConfigSeen::query()->where('agent', 'impl')->get();
        $this->assertCount(1, $rows, 'the second sighting minted a second row instead of rewriting the first');

        $row = $rows[0];
        $this->assertTrue(
            $row->first_seen_at->equalTo($firstSeen),
            'first_seen_at moved on a repeat sighting — the LOST line\'s window collapses to "just now" and stops telling a long-running seat from a fresh one',
        );
        $this->assertTrue(
            $row->last_seen_at->greaterThan($lastSeen),
            'last_seen_at did not advance — the window\'s right edge would freeze at the first run that ever saw the block',
        );
        $this->assertSame(99, $row->board_id, 'the scope did not follow the sighting, so the LOST line would name a board the seat no longer used');
    }

    /**
     * ⭐ RE-ADDING THE BLOCK RE-OPENS THE QUESTION THE RETIREMENT CLOSED, so the tombstone has
     * to go. Left standing, the seat would be permanently exempt from the leg — a mute that
     * nothing in the config says is in force.
     */
    public function test_an_enabled_sighting_clears_a_tombstone(): void
    {
        ConfigSeenLedger::recordRetired('impl', '2026-09-08 — decommissioned');
        $this->assertNotNull(
            BoardToolsConfigSeen::query()->where('agent', 'impl')->sole()->retired_reason,
            'the fixture did not actually write a tombstone, so this says nothing about clearing one',
        );

        ConfigSeenLedger::recordEnabled('impl', $this->enabled());

        $row = BoardToolsConfigSeen::query()->where('agent', 'impl')->sole();
        $this->assertNull($row->retired_seen_at);
        $this->assertNull($row->retired_reason);
    }

    /**
     * ⭐ THE REVIVED SEAT'S ROW MUST GAIN A LEFT EDGE, and this is the path where it was
     * missing. A retirement can be the FIRST thing this install records (the operator
     * retiring a seat whose block was already gone), and that INSERT writes `first_seen_at`
     * as NULL — so if the enabled sighting that revives the seat does not write the column,
     * NOTHING ever does, and the LOST line the seat later produces prints a HEADLESS window:
     * *"was seen from  to <last>"*. The sequence is the one the product itself prescribes:
     * retire a never-seen seat, then remove the `retired:` key and re-add the block, which
     * is verbatim what the RETIRED line tells the operator to do.
     */
    public function test_an_enabled_sighting_stamps_the_left_edge_a_retirement_born_row_never_had(): void
    {
        ConfigSeenLedger::recordRetired('impl', '2026-09-08 — decommissioned');
        $this->assertNull(
            BoardToolsConfigSeen::query()->where('agent', 'impl')->sole()->first_seen_at,
            'the fixture did not produce a NULL left edge, so this says nothing about stamping one',
        );

        ConfigSeenLedger::recordEnabled('impl', $this->enabled());

        $row = BoardToolsConfigSeen::query()->where('agent', 'impl')->sole();
        $this->assertNotNull(
            $row->first_seen_at,
            'the revived seat has no left edge, so its LOST line renders "was seen from  to <last>" — a malformed sentence to the operator',
        );
        $this->assertNotNull($row->last_seen_at);
    }

    /**
     * ⛔ THE CONTROL ON THE TEST ABOVE, and the half a write that simply stamped
     * `first_seen_at` on every sighting would fail. Retiring and re-adding a seat this
     * install DID see enabled must keep the original left edge: the window's whole job is to
     * distinguish a seat that ran for a month from one configured this morning, and a
     * revive that reset it would answer "this morning" for both.
     */
    public function test_reviving_a_seat_that_was_seen_enabled_keeps_its_original_left_edge(): void
    {
        $this->travelTo(now()->subDays(30));
        ConfigSeenLedger::recordEnabled('impl', $this->enabled());
        $firstSeen = BoardToolsConfigSeen::query()->where('agent', 'impl')->sole()->first_seen_at;

        $this->travelBack();
        ConfigSeenLedger::recordRetired('impl', '2026-09-08 — decommissioned');
        ConfigSeenLedger::recordEnabled('impl', $this->enabled());

        $row = BoardToolsConfigSeen::query()->where('agent', 'impl')->sole();
        $this->assertTrue(
            $row->first_seen_at->equalTo($firstSeen),
            'the revive moved first_seen_at — the window collapses to "just now" for a seat that ran for a month',
        );
        $this->assertNull($row->retired_reason);
    }

    /**
     * The inverse of the test above, and the one that keeps the seen WINDOW out of the update
     * list: a retirement records a decision, it does not erase the history that made the
     * decision worth recording.
     */
    public function test_a_retirement_keeps_the_seen_window(): void
    {
        ConfigSeenLedger::recordEnabled('impl', $this->enabled());
        $seen = BoardToolsConfigSeen::query()->where('agent', 'impl')->sole()->first_seen_at;

        ConfigSeenLedger::recordRetired('impl', '2026-09-08 — decommissioned');

        $row = BoardToolsConfigSeen::query()->where('agent', 'impl')->sole();
        $this->assertTrue($row->first_seen_at->equalTo($seen));
        $this->assertNotNull($row->last_seen_at);
        $this->assertSame('2026-09-08 — decommissioned', $row->retired_reason);
        $this->assertNotNull($row->retired_seen_at);
    }

    /**
     * A retirement can legitimately be the FIRST thing this install records for an agent —
     * the operator retiring a seat whose block was already gone, which is exactly the cure
     * the LOST line prescribes. Both stamps stay NULL so the leg's population (seats SEEN
     * enabled) does not silently gain a seat nobody ever saw.
     */
    public function test_a_retirement_of_a_never_seen_agent_leaves_both_stamps_null(): void
    {
        ConfigSeenLedger::recordRetired('ghost', '2026-09-08 — never provisioned here');

        $row = BoardToolsConfigSeen::query()->where('agent', 'ghost')->sole();
        $this->assertNull($row->first_seen_at);
        $this->assertNull($row->last_seen_at);
        $this->assertSame('2026-09-08 — never provisioned here', $row->retired_reason);
    }

    /**
     * ⭐ THE LOOP'S FOUR INPUTS, COMPARED AS A SET. Each arm asserted alone would pass against
     * a loop stuck on its own verdict — one recording everything would satisfy the two
     * positive cases, one recording nothing would satisfy the two negative ones — and only the
     * comparison shows it DISCRIMINATES. The two it must NOT record are the point: a present
     * block in any form is not lost, so it needs no witness, and recording `enabled: false`
     * would turn `bridge:check`'s own "NO ⇒ set enabled: false" advice into a permanent mute.
     */
    public function test_the_sighting_loop_records_enabled_and_retired_blocks_and_nothing_else(): void
    {
        ConfigSeenLedger::recordSightings([
            $this->config('live', ['transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]),
            $this->config('retired', ['retired' => '2026-09-08 — decommissioned']),
            $this->config('declined', ['enabled' => false]),
            $this->config('broken', ['board_id' => 10]),   // default-on, unsatisfiable ⇒ suppressed
            $this->config('none', null),
        ]);

        $recorded = BoardToolsConfigSeen::query()->orderBy('agent')->pluck('agent')->all();

        $this->assertSame(['live', 'retired'], $recorded);
        $this->assertNotNull(BoardToolsConfigSeen::query()->where('agent', 'live')->sole()->last_seen_at);
        $this->assertNull(
            BoardToolsConfigSeen::query()->where('agent', 'retired')->sole()->last_seen_at,
            'the retired agent was recorded as SEEN ENABLED, which would put a seat nobody ever ran into the lost leg\'s population',
        );
    }

    /**
     * ⚑ THE FAILURE IS REAL, NOT SYNTHETIC — the query runs against a genuinely unmigrated
     * SQLite connection and comes back with the driver's own `no such table`. An install that
     * pulled the code and has not run `php artisan migrate` reaches this on every check run
     * and on every tool call, and neither may be aborted by an audit row.
     */
    public function test_an_unmigrated_table_logs_the_consequence_and_does_not_throw(): void
    {
        Log::spy();

        $this->withUnmigratedDatabase(function (): void {
            ConfigSeenLedger::recordEnabled('impl', $this->enabled());
            ConfigSeenLedger::recordRetired('impl', '2026-09-08 — decommissioned');
        });

        // The CONSEQUENCE, not the exception: what the operator loses is the ability to be
        // told this seat's block went missing.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'cannot report this seat\'s block as LOST'))
            ->twice();
    }

    private function enabled(int $boardId = 10): BoardToolsConfig
    {
        return new BoardToolsConfig(
            enabled: true, tokenPath: null, boardId: $boardId, swimlaneId: 4, createStageId: 55,
            sharedSwimlaneId: null, coordBoardId: null, addressTags: [], transport: 'ssh',
        );
    }

    /** @param array<string, mixed>|null $block */
    private function config(string $name, ?array $block): AgentConfig
    {
        $raw = ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []];
        if ($block !== null) {
            $raw['board_tools'] = $block;
        }

        return AgentConfig::fromArray($name, $raw);
    }
}
