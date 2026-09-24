<?php

namespace App\Bridge\Writeback;

/**
 * What ONE `boards/{id}/preload.json` read says about a board's columns and lanes, so a caller
 * that needs more than the stage names pays for the read once rather than once per projection.
 *
 * ⚠ `$swimlaneIds` keeps {@see KanbanClient::boardSwimlaneIds}'s null-versus-empty split: `[]`
 * is a board with no lanes, null is a read that carried no swimlane collection at all.
 * `$terminalStageIds` has no such split, because the stage collection's absence is already
 * reported by the client's own warning and leaves every stage list here empty together.
 *
 * ⭐ `$terminalStageIds` TRAVELS WITH `$terminalBasis`, and a consumer that reports the set owes
 * the basis alongside it. The set alone cannot say whether the board declared it or whether the
 * bridge stood in for a board that has declared nothing, and those are different claims about
 * the same list — see {@see TerminalBasis} for the rule that picks between them.
 */
final class BoardStructure
{
    /**
     * @param  array<int, string>  $stageNames  id => name, in the board's own column order
     * @param  list<int>  $stageIds  every stage id the read carried, named or not
     * @param  list<int>  $terminalStageIds  the stages this board's `$terminalBasis` declares terminal
     * @param  list<int>|null  $swimlaneIds  null ⇒ the read carried no swimlane collection
     * @param  TerminalBasis  $terminalBasis  which declaration `$terminalStageIds` was read from
     */
    public function __construct(
        public readonly array $stageNames,
        public readonly array $stageIds,
        public readonly array $terminalStageIds,
        public readonly ?array $swimlaneIds,
        public readonly TerminalBasis $terminalBasis,
    ) {}
}
