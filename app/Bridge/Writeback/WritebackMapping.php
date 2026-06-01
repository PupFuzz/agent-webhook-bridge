<?php

namespace App\Bridge\Writeback;

/**
 * Operator-config mapping for one repo → a kanban board + the stage each
 * GitHub-PR outcome moves a card to (DL-009/019). The stage is keyed on the
 * GitHub-CONTROLLED outcome (opened / merged / merged_to_main / closed_unmerged),
 * never on attacker-settable PR text — which card is decided by the classifier
 * (correlation), but which board+stage is decided HERE, from operator config the
 * webhook body can't influence.
 */
final class WritebackMapping
{
    /**
     * @param  array<string, int>  $stages  outcome => workflow_stage_id
     */
    public function __construct(
        public readonly int $boardId,
        public readonly array $stages,
    ) {}

    /** The configured stage id for a GitHub-PR outcome, or null when unmapped. */
    public function stageFor(string $outcome): ?int
    {
        return $this->stages[$outcome] ?? null;
    }
}
