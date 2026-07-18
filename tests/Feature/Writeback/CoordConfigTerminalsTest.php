<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\CoordConfigTerminals;
use Tests\TestCase;

/**
 * DL-200 — the PHP mirror of the framework's `coord.kanban_common.terminals_for_board`
 * + `_terminal_columns_by_board` (Python), which `bridge:check` needs to resolve what
 * the OTHER mover considers terminal.
 *
 * THE VECTORS BELOW ARE PORTED VERBATIM from the framework's own conformance test
 * (coord `templates/kanban/examples/test_inbox_closure_intent.py`, the
 * `terminals_for_board` + `_terminal_columns_by_board` blocks). The bridge is PHP and
 * structurally cannot import the Python primitive, so this IS a second implementation
 * of one rule — the known runtime ceiling. A "keep these in sync" comment would
 * enforce nothing; sharing the ORIGINAL's vectors is the mechanical part. If the
 * framework changes the rule, re-port these vectors and this test reds.
 *
 * Scope: the READ-SITE path only. `terminals_for_board`'s docstring is explicit that
 * the read-site sees no adapter kwargs ("only terminals present in CONFIG are visible
 * to the read-site"), so the deprecated done_column=/terminal_columns= kwargs are
 * deliberately NOT mirrored.
 */
class CoordConfigTerminalsTest extends TestCase
{
    // ---- terminals_for_board: ported vectors ----

    public function test_explicit_terminal_columns_win_with_no_lane_fallback(): void
    {
        $this->assertSame(
            ['Released to main', "Won't Do"],
            CoordConfigTerminals::terminalsForBoard(['terminal_columns' => ['Released to main', "Won't Do"]]),
        );
    }

    public function test_lane_model_board_falls_back_to_done(): void
    {
        // THE case the card's spec text missed: the canonical `issues` board declares
        // user_lanes and NO terminal_columns, so it resolves to Done via the fallback.
        // A literal `terminal_columns` read would see nothing here and report
        // CANNOT-VERIFY forever on the reference shape.
        $this->assertSame(['Done'], CoordConfigTerminals::terminalsForBoard(['user_lanes' => ['Next', 'Later']]));
    }

    public function test_explicit_terminals_suppress_the_lane_fallback(): void
    {
        // No spurious "Done".
        $this->assertSame(
            ["Won't Do"],
            CoordConfigTerminals::terminalsForBoard(['user_lanes' => ['Now'], 'terminal_columns' => ["Won't Do"]]),
        );
    }

    public function test_neither_terminals_nor_user_lanes_yields_no_terminals(): void
    {
        // The read-side "no surface" case — a board that never concludes anything.
        $this->assertSame([], CoordConfigTerminals::terminalsForBoard([]));
    }

    public function test_declared_in_config_means_both_sides_agree(): void
    {
        $this->assertSame(['Done'], CoordConfigTerminals::terminalsForBoard(['terminal_columns' => ['Done']]));
    }

    // ---- _terminal_columns_by_board: ported vectors ----

    /** @return array<string, mixed> */
    private function vectorConfig(): array
    {
        // Ported verbatim from the framework's own vector block.
        return ['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => 2, 'user_lanes' => ['Next', 'Later']],              // lane model → {"Done"}
            ['key' => 'prs', 'board_id' => 3, 'terminal_columns' => ['Released to main']],        // shares board 3
            ['key' => 'product-tasks', 'board_id' => 3, 'terminal_columns' => ['Released to main', "Won't Do"]],
            ['key' => 'docs', 'board_id' => 4],                                                    // no terminals, no lanes → dropped
            ['key' => 'unconfigured', 'board_id' => 'REPLACE_ME', 'terminal_columns' => ['X']],   // placeholder → skipped
        ]]];
    }

    public function test_lane_model_board_resolves_through_the_by_board_join(): void
    {
        $this->assertSame(['Done'], CoordConfigTerminals::terminalNamesForBoardId($this->vectorConfig(), 2));
    }

    public function test_two_entries_sharing_one_board_id_union_their_terminals(): void
    {
        // The join key is board_id, NOT the `key` string — and entries sharing an id UNION.
        $this->assertSame(
            ['Released to main', "Won't Do"],
            CoordConfigTerminals::terminalNamesForBoardId($this->vectorConfig(), 3),
        );
    }

    public function test_a_board_with_no_terminals_resolves_empty(): void
    {
        $this->assertSame([], CoordConfigTerminals::terminalNamesForBoardId($this->vectorConfig(), 4));
    }

    public function test_a_replace_me_placeholder_board_id_is_skipped(): void
    {
        // "REPLACE_ME" is not numeric — it must never match a real board id.
        $this->assertSame([], CoordConfigTerminals::terminalNamesForBoardId($this->vectorConfig(), 0));
    }

    public function test_an_unmatched_board_id_resolves_empty(): void
    {
        $this->assertSame([], CoordConfigTerminals::terminalNamesForBoardId($this->vectorConfig(), 999));
    }

    public function test_explicit_terminals_do_not_get_the_done_fallback_added(): void
    {
        $cfg = ['kanban' => ['boards' => [
            ['key' => 'prs', 'board_id' => 9, 'user_lanes' => ['Now'], 'terminal_columns' => ["Won't Do"]],
        ]]];

        $this->assertSame(["Won't Do"], CoordConfigTerminals::terminalNamesForBoardId($cfg, 9));
    }

    public function test_issue_population_explicit_all_resolves_all(): void
    {
        $cfg = ['kanban' => ['boards' => [['board_id' => 8, 'issue_population' => 'all']]]];
        $this->assertSame(['all'], CoordConfigTerminals::issuePopulationsForBoardId($cfg, 8));
    }

    public function test_issue_population_absent_key_resolves_prefixed_default(): void
    {
        // sola's contract: absent ⇒ prefixed. An entry-present-but-key-absent resolves
        // 'prefixed', NOT [] — [] is reserved for "no entry for this board" (cannot verify).
        $cfg = ['kanban' => ['boards' => [['board_id' => 8]]]];
        $this->assertSame(['prefixed'], CoordConfigTerminals::issuePopulationsForBoardId($cfg, 8));
    }

    public function test_issue_population_explicit_prefixed_resolves_prefixed(): void
    {
        $cfg = ['kanban' => ['boards' => [['board_id' => 8, 'issue_population' => 'prefixed']]]];
        $this->assertSame(['prefixed'], CoordConfigTerminals::issuePopulationsForBoardId($cfg, 8));
    }

    public function test_issue_population_no_entry_for_board_is_empty_cannot_verify(): void
    {
        $cfg = ['kanban' => ['boards' => [['board_id' => 8, 'issue_population' => 'all']]]];
        $this->assertSame([], CoordConfigTerminals::issuePopulationsForBoardId($cfg, 999));
    }

    public function test_issue_population_replace_me_board_is_skipped(): void
    {
        $cfg = ['kanban' => ['boards' => [['board_id' => 'REPLACE_ME', 'issue_population' => 'all']]]];
        $this->assertSame([], CoordConfigTerminals::issuePopulationsForBoardId($cfg, 0));
    }

    public function test_empty_or_missing_kanban_block_resolves_empty(): void
    {
        $this->assertSame([], CoordConfigTerminals::terminalNamesForBoardId([], 2));
        $this->assertSame([], CoordConfigTerminals::terminalNamesForBoardId(['kanban' => []], 2));
    }

    // ---- shapes real configs actually carry (not in the Python vectors) ----

    public function test_a_string_board_id_matches_a_numeric_board_id(): void
    {
        // Real configs carry board_id as a STRING ("5"/"8"/"12"); the framework's
        // vectors use ints. Both must resolve, or the compare silently never matches.
        $cfg = ['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => '8', 'user_lanes' => ['Now']],
        ]]];

        $this->assertSame(['Done'], CoordConfigTerminals::terminalNamesForBoardId($cfg, 8));
    }

    public function test_a_malformed_boards_entry_is_skipped_not_fatal(): void
    {
        $cfg = ['kanban' => ['boards' => ['not-an-array', ['board_id' => 8, 'user_lanes' => ['Now']]]]];

        $this->assertSame(['Done'], CoordConfigTerminals::terminalNamesForBoardId($cfg, 8));
    }

    // ---- reading $COORD_CONFIG from disk ----

    public function test_load_returns_null_for_an_absent_path(): void
    {
        // Absent ⇒ null ⇒ the caller reports CANNOT-VERIFY, never AGREE.
        $this->assertNull(CoordConfigTerminals::load('/nonexistent/coordination.config.json'));
        $this->assertNull(CoordConfigTerminals::load(null));
        $this->assertNull(CoordConfigTerminals::load(''));
    }

    public function test_load_returns_null_for_malformed_json(): void
    {
        $p = sys_get_temp_dir().'/coordcfg-'.uniqid().'.json';
        file_put_contents($p, '{not json');
        try {
            $this->assertNull(CoordConfigTerminals::load($p));
        } finally {
            @unlink($p);
        }
    }

    public function test_load_parses_a_well_formed_config(): void
    {
        $p = sys_get_temp_dir().'/coordcfg-'.uniqid().'.json';
        file_put_contents($p, (string) json_encode($this->vectorConfig()));
        try {
            $cfg = CoordConfigTerminals::load($p);
            $this->assertNotNull($cfg);
            $this->assertSame(['Done'], CoordConfigTerminals::terminalNamesForBoardId($cfg, 2));
        } finally {
            @unlink($p);
        }
    }
}
