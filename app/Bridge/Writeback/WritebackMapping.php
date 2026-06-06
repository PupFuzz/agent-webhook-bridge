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
     * @param  bool  $createDependabotCards  opt-in: a dependabot PR (head `dependabot/*`)
     *                                       with no tracking card gets one created on
     *                                       open and moved on close (keyed by PR number)
     * @param  ?int  $swimlaneId  optional lane for CREATED cards only (DL-027) — on a
     *                            lane-per-repo board the per-repo mapping IS the lane
     *                            discriminator. Applied at create (incl. create-on-missed);
     *                            never on move, so a human re-laning a card survives.
     */
    public function __construct(
        public readonly int $boardId,
        public readonly array $stages,
        public readonly bool $createDependabotCards = false,
        public readonly ?int $swimlaneId = null,
    ) {}

    /** The configured stage id for a GitHub-PR outcome, or null when unmapped. */
    public function stageFor(string $outcome): ?int
    {
        return $this->stages[$outcome] ?? null;
    }
}
