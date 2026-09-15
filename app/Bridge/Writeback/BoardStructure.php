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
 */
final class BoardStructure
{
    /**
     * @param  array<int, string>  $stageNames  id => name, in the board's own column order
     * @param  list<int>  $stageIds  every stage id the read carried, named or not
     * @param  list<int>  $terminalStageIds  the stages whose kanban `lane_type` is `done`
     * @param  list<int>|null  $swimlaneIds  null ⇒ the read carried no swimlane collection
     */
    public function __construct(
        public readonly array $stageNames,
        public readonly array $stageIds,
        public readonly array $terminalStageIds,
        public readonly ?array $swimlaneIds,
    ) {}
}
