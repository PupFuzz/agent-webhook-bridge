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
 * ⚠ A stage whose `is_terminal` key is ABSENT is not flagged, and that is the whole handling it
 * needs: absent and `false` both mean "this stage is not opted in", and the board-level fallback
 * is what makes a kanban older than v0.47.0 — which sends the key on no stage at all — read
 * exactly as it did before the field existed, rather than as a board with nothing terminal.
 *
 * ⛔ THIS IS NOT THE WRITEBACK'S TERMINAL SET AND NEVER ANSWERS FOR IT. The bridge holds three
 * OTHER terminal notions, and they are deliberately not derivations of the board's property:
 * {@see WritebackMapping::isTerminalStage} (the operator's PR-outcome mapping plus board
 * position), `coord_card_terminal_stage_id` (one operator-named stage) and
 * {@see CoordConfigTerminals} (the coordination config's terminal column NAMES). Those state
 * where the BRIDGE concludes a card; this states what the BOARD declares about its own columns.
 */
enum TerminalBasis: string
{
    /** At least one stage on this board carries `is_terminal: true` — the board answered for itself. */
    case Declared = 'is_terminal';

    /** No stage on this board is flagged, so the `lane_type: done` columns stand in (kanban's default state). */
    case LaneType = 'lane_type';
}
