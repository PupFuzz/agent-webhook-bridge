<?php

namespace App\Bridge\Writeback;

/**
 * WHICH declaration answered "is this stage terminal?" for a board, on the read that asked.
 *
 * Kanban ships `workflow_stages.is_terminal` (kanban DL-281, deployed v0.47.0) as a distinct
 * per-stage flag, and says in the same breath that it is **deliberately NOT `lane_type ===
 * 'done'`**: `lane_type` is a display and analytics concern, so binding a terminal decision to
 * it means re-typing or renaming a column changes what the system concludes. The flag is the
 * board's own answer to the question; `lane_type` is the board's answer to a DIFFERENT question
 * the bridge has been reading as a proxy for it.
 *
 * ⛔ NOTHING BACKFILLS THE FLAG, and kanban states that a board with no flagged stage is the
 * DEFAULT and legitimate configuration, not a fault (`kanban:terminal-stages` exists to report
 * that gap without reddening on it). So "read `is_terminal` everywhere" is not the alignment —
 * on every board that has not opted in it answers `false` for every stage, which would silently
 * retire the exclusion this repo has today on every live board at once.
 *
 * ⭐ THE RULE IS PRECEDENCE PER BOARD, AND THE UNIT IS THE BOARD, NOT THE STAGE — the same unit
 * kanban's own report groups by. A board that flags ANY stage has opted in and answers for
 * itself ({@see self::Declared}); a board that flags none has not, and the `lane_type: done`
 * columns stand in ({@see self::LaneType}). Per-STAGE precedence would be the bug: it cannot
 * tell "this stage is not terminal" from "this board never opted in", so an opted-in board that
 * deliberately leaves a `done` column unflagged would keep being told it is terminal — which is
 * one of the two divergences this exists to close.
 *
 * ⚠ AND THE BOARD CROSSES WORKFLOWS, WHICH IS THE UNIT'S REACH AND IS DELIBERATE.
 * {@see KanbanClient::stagesIn} concatenates every workflow's stages, so on a two-workflow board a
 * single stage flagged in ONE workflow puts the WHOLE board on this basis and declassifies the
 * OTHER workflow's `done` columns. Per-WORKFLOW precedence is a real third alternative with the
 * same opt-in logic, and the board was chosen because it is kanban's own reporting unit — its
 * `kanban:terminal-stages` joins `workflows` and groups by `board_id`, so a per-workflow rule here
 * would answer a question the far end does not ask. `KanbanClientTest::test_the_board_level_unit_reaches_across_workflows`
 * pins the reach so it stays a measured property rather than a side effect of the descent.
 *
 * ⚠ A stage whose `is_terminal` key is ABSENT is not flagged, and that is the whole handling it
 * needs: absent and `false` both mean "this stage is not opted in", and the board-level fallback
 * is what makes a kanban older than v0.47.0 — which sends the key on no stage at all — read
 * exactly as it did before the field existed, rather than as a board with nothing terminal.
 *
 * ⛔ THIS IS NOT THE WRITEBACK'S TERMINAL SET AND NEVER ANSWERS FOR IT. The bridge holds other
 * terminal notions — `WritebackMapping::isTerminalStage` and `terminalFloor` (the operator's
 * PR-outcome mapping plus board position), `coord_card_terminal_stage_id` (one operator-named
 * stage), `CoordConfigTerminals` (the coordination config's terminal column NAMES) — and they are
 * deliberately not derivations of the board's property: they state where the BRIDGE concludes a
 * card, while this states what the BOARD declares about its own columns.
 *
 * ⚠ THAT LIST IS AN ORIENTATION, NOT THE POPULATION, AND CARRIES NO COUNT ON PURPOSE.
 * `bin/derive-terminal-sites.sh` owns the population and re-derives it; a figure written here is
 * the copy a maintainer adding a fifth notion would read and not update, and this file ships
 * beside the very script that exists so the number is never quoted
 * (`CLAUDE_CONVENTIONS.md` § Derived figures).
 */
enum TerminalBasis: string
{
    /** At least one stage on this board carries `is_terminal: true` — the board answered for itself. */
    case Declared = 'is_terminal';

    /** No stage on this board is flagged, so the `lane_type: done` columns stand in (kanban's default state). */
    case LaneType = 'lane_type';

    /**
     * NO DECLARATION ANSWERED — the preload carried no stage collection, so nothing about this
     * board's columns was read (card#10274 r1).
     *
     * ⛔ THIS IS NOT A THIRD KIND OF BOARD, IT IS THE ABSENCE OF A READ, and it exists because the
     * other two cases are POSITIVE CLAIMS about the operator's configuration. `stagesIn()` warns
     * and yields nothing on a 200 whose body carries no `workflows` collection — a state this repo
     * has already seen live (card#8761) — and without this case the empty flagged set that results
     * is indistinguishable from a board that deliberately flags nothing, so the seat is told
     * `lane_type` about a board nobody read. An empty stage LIST asserts nothing and needs no such
     * case, which is why {@see BoardStructure}'s older fields legitimately have none; an enum that
     * always names a declaration is the opposite.
     *
     * ⚠ `workflows: []` IS NOT THIS CASE. That is a read that WORKED over a board with no columns,
     * and a board with no columns flags none — so it answers {@see self::LaneType}, truthfully. The
     * split is `stagesIn()`'s own and is deliberately not a second predicate: see
     * {@see KanbanClient::stageCollectionReadable}.
     */
    case Unreadable = 'stages_unreadable';
}
