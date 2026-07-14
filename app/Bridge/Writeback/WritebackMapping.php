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
     * @param  ?list<int>  $startedFromStages  optional promote-from guard for the
     *                                         `started` outcome (DL-160): the workflow_stage_ids a
     *                                         branch-create `started` move may promote a card FROM
     *                                         (the board's Backlog/Prioritized stages). The handler
     *                                         refuses to advance a card whose current stage isn't in
     *                                         this set, so re-creating an old branch never drags an
     *                                         already-progressed card backward. null ⇒ a `started`
     *                                         move is refused (no safe source set configured).
     * @param  bool  $draftOverlay  opt-in (DL-193): mirror the PR's DRAFT state onto the
     *                              correlated card's `block_reason` field (overlay only — NO
     *                              column/stage move). converted_to_draft / opened-as-draft
     *                              SET the marker (add-if-missing); ready_for_review CLEARS it
     *                              (clear-if-ours). Default false ⇒ these actions are ignored.
     * @param  ?list<int>  $unparkFromStages  optional auto-unpark set for the `started` outcome
     *                                        (DL-194): the workflow_stage_ids a branch-create
     *                                        `started` move promotes a card FROM even when the
     *                                        card is PINNED (the DL-178 reversal, scoped to these
     *                                        stages only). Must be DISJOINT from
     *                                        `startedFromStages` (enforced at load). null ⇒ no
     *                                        auto-unpark (DL-178 byte-identical).
     * @param  list<string>  $holdMarkerTags  optional (DL-194): tags identifying a deliberate
     *                                        hold PinGuard doesn't already recognize (e.g.
     *                                        `gate`). WIDENS the unpark alert set; can never
     *                                        narrow/suppress the alert for a PinGuard-pinned
     *                                        card. Default `[]` ⇒ the fail-safe alerts on every
     *                                        non-benign-draft unpark.
     * @param  ?string  $draftBlockReason  optional (DL-194): the benign automated-draft
     *                                     `block_reason` sentinel (DL-193 overlay). A card whose
     *                                     only pin signal is this value is a draft-park, not a
     *                                     deliberate hold, so it does not alert on unpark. null ⇒
     *                                     the handler resolves the KanbanBlockReasonHandler::MARKER
     *                                     default.
     * @param  bool  $reviveOnReopen  opt-in (DL-195): when a PR the writeback parked in the
     *                                `closed_unmerged` (abandon) stage is REOPENED, revive its card
     *                                back to the `opened` (In-Review) stage — the backward move the
     *                                DL-163 no-regression guard otherwise refuses. Scoped to a card
     *                                CURRENTLY in the mapped `closed_unmerged` stage (terminal-safe:
     *                                a Shipped/Released card is never there); a marker-gated alert
     *                                fires on the override. Default false ⇒ a `reopened` action stays
     *                                the `opened` outcome (byte-identical). Reuses `stages.opened` as
     *                                the target — there is NO `stages.reopened` key.
     * @param  bool  $createCoordCards  opt-in (DL-198): a coordination issue opened/reopened with a
     *                                  recognized `[PREFIX]` title gets a tracking card CREATED in
     *                                  real time, keyed (idempotent) on the `id:<sid>` tag. Create-only
     *                                  (the periodic reconcile owns column/lifecycle). Default false ⇒
     *                                  no coord card is created (byte-identical).
     * @param  ?int  $coordCardStageId  the stage a new coord card lands in (DL-198) — REQUIRED when
     *                                  createCoordCards is true (a create with no stage can't POST →
     *                                  the config fails closed at load, not silently at dispatch). null
     *                                  when createCoordCards is false.
     */
    public function __construct(
        public readonly int $boardId,
        public readonly array $stages,
        public readonly bool $createDependabotCards = false,
        public readonly ?int $swimlaneId = null,
        public readonly ?array $startedFromStages = null,
        public readonly bool $draftOverlay = false,
        public readonly ?array $unparkFromStages = null,
        public readonly array $holdMarkerTags = [],
        public readonly ?string $draftBlockReason = null,
        public readonly bool $reviveOnReopen = false,
        public readonly bool $createCoordCards = false,
        public readonly ?int $coordCardStageId = null,
    ) {}

    /** The configured stage id for a GitHub-PR outcome, or null when unmapped. */
    public function stageFor(string $outcome): ?int
    {
        return $this->stages[$outcome] ?? null;
    }
}
