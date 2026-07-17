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
     *                                  real time, keyed (idempotent) on the `id:<sid>` tag. This flag
     *                                  is create-only; its sibling $moveCoordCards (DL-200) carries the
     *                                  column moves, and the periodic reconcile owns column/lifecycle
     *                                  wherever that one is off. Default false ⇒ no coord card is
     *                                  created (byte-identical).
     * @param  ?int  $coordCardStageId  the stage a new coord card lands in (DL-198) — REQUIRED when
     *                                  createCoordCards is true (a create with no stage can't POST →
     *                                  the config fails closed at load, not silently at dispatch). null
     *                                  when createCoordCards is false. Doubles as the REVIVE target
     *                                  under moveCoordCards (DL-200), so it is required there too.
     * @param  bool  $moveCoordCards  opt-in (DL-200): a coordination issue CLOSING moves its tracking
     *                                card to coordCardTerminalStageId, and a REOPEN revives a card whose
     *                                terminal this bridge set back to coordCardStageId. Correlated by the
     *                                same `id:<sid>` tag createCoordCards writes. Separately opt-in — it
     *                                does NOT ride createCoordCards (roundtable #18 "opt-in first").
     *                                Default false ⇒ no coord card is ever moved (byte-identical).
     * @param  ?int  $coordCardTerminalStageId  the terminal stage a coord card moves to when its issue
     *                                          closes (DL-200) — REQUIRED when moveCoordCards is true,
     *                                          and MUST differ from coordCardStageId (equal ⇒ close and
     *                                          revive resolve to one stage ⇒ the leg can express neither).
     * @param  bool  $promoteOnRelease  opt-in (DL-207 / card-4483): on a release-PR merge into
     *                                  main (a `merged_to_main` event), scan this board for cards at
     *                                  the Shipped stage (`stages.merged`) whose merged PR's commit is
     *                                  now reachable from main, and promote each to the Released stage
     *                                  (`stages.merged_to_main`) — the steady-state Shipped→Released
     *                                  transition a card-first board otherwise never gets (base=dev
     *                                  cards get no merged_to_main event of their own). Reuses
     *                                  stages.merged/stages.merged_to_main; both are REQUIRED when this
     *                                  is on (fail-closed at load). Default false ⇒ no scan (byte-identical).
     * @param  ?string  $cardIdTagTemplate  opt-in (#75 / card-4485): free-form template rendered into an
     *                                      `id:` provenance tag stamped on each dependabot card
     *                                      KanbanDependabotCardHandler creates, so a tag-keyed
     *                                      Shipped→Released promoter can find them (the bridge otherwise
     *                                      mints dependabot cards with no `id:` tag, unlike their
     *                                      impl-created siblings). Placeholders: {n}/{pr_number} = the PR
     *                                      number, {repo} = the repo NAME (last path segment). Per-tenant
     *                                      grammar, e.g. `id:DEV-pr-{n}` or `id:dep:{repo}#{n}`. null ⇒
     *                                      no tag is added (back-compat, byte-identical).
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
        public readonly bool $moveCoordCards = false,
        public readonly ?int $coordCardTerminalStageId = null,
        public readonly ?string $cardIdTagTemplate = null,
        public readonly bool $promoteOnRelease = false,
    ) {}

    /** The configured stage id for a GitHub-PR outcome, or null when unmapped. */
    public function stageFor(string $outcome): ?int
    {
        return $this->stages[$outcome] ?? null;
    }
}
