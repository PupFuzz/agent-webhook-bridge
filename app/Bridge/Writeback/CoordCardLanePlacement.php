<?php

namespace App\Bridge\Writeback;

/**
 * WHICH STAGE a coord card belongs in — the single resolution every coord-card
 * write reads (card#6393). The rule itself is {@see CoordLaneStages}, the mirror of
 * the consumer's `_task_lane`; this is the mapping-aware wrapper around it that
 * turns a title + a label set into a `workflow_stage_id`.
 *
 * WHY IT IS SHARED, not a helper on the create handler where it started. The defect
 * class card#6393 names is *a coord-card stage minted or kept with NO lane input*:
 * on a lane-model board the consumer's `kanban-writeback` pass runs BEFORE its
 * issues-sync and maps the card's lane back onto the issue's `stage:*` label, so any
 * bridge write that ignores the lane does not merely place the card — it REWRITES a
 * written sequencing ruling on the issue. There are three such writes (create,
 * revive, relane) and they must not be able to disagree about where a `[TASK]`
 * belongs, so the resolution is ONE primitive with three call sites rather than
 * three near-identical derivations (canon #5).
 *
 * PURE — no config load, no I/O, and deliberately NO LOGGING. `unmapped` is returned
 * as the caller's warn material instead: `WritebackRefusalSignalCoverageTest` holds
 * set-equality over the bare log calls in the `Kanban*Handler.php` population, so a
 * diagnostic hoisted in here would leave that guard reporting on a population it no
 * longer covers. The log line stays at each call site, where it can also name what
 * that leg was doing.
 */
final class CoordCardLanePlacement
{
    /**
     * The stage a coord card belongs in, plus the lane that answer came from.
     *
     * `lane` is the DISCRIMINATOR, and it is the reason this returns a shape rather
     * than an int: null means *no lane governs this card* — either the mapping
     * configures no lane model, or the lane model does not govern this issue (a
     * non-`[TASK]` title) — and `stage` is then $fixedStage, byte-identical DL-198.
     * A non-null lane means the answer was DERIVED, and `stage` is that lane's id. A
     * caller that only acts on a derived answer (the relane leg) tests exactly this,
     * so it needs no second copy of the "is the lane model live here" question.
     *
     * WHY THE CALL SITES WRITE `$mapping->coordCardLaneStageIds ?? []`. A non-null `lane`
     * and a non-empty `unmapped` are BOTH reachable only through the second branch below,
     * which has already established that the map is non-null — so a caller reading the map
     * back inside either arm cannot observe null. phpstan cannot carry that fact across the
     * call boundary, so those reads spell the fallback to stay level-7 clean: it is a
     * STATIC-ANALYSIS NARROWING, not a state that occurs. Nothing may grow a runtime guard
     * out of it (canon #6) — if one of those call sites ever needs a real null branch, the
     * discriminator is wrong, not the guard missing.
     *
     * @param  int  $fixedStage  the mapping's fixed stage for this leg — the create stage
     *                           for a create, the revive target for a revive
     * @param  list<string>  $labels
     * @return array{stage: int, lane: ?string, unmapped: list<string>}
     */
    public static function resolve(WritebackMapping $mapping, int $fixedStage, string $title, array $labels): array
    {
        if ($mapping->coordCardLaneStageIds === null || ! CoordLaneStages::governs($title)) {
            return ['stage' => $fixedStage, 'lane' => null, 'unmapped' => []];
        }

        $resolved = CoordLaneStages::resolveLane($labels, array_keys($mapping->coordCardLaneStageIds));
        // WritebackConfig fails the load closed unless the map carries DEFAULT_LANE, so
        // the null arm resolves; a non-null lane is mapped by construction (resolveLane
        // only ever returns one of $mappedLanes).
        $lane = $resolved['lane'] ?? CoordLaneStages::DEFAULT_LANE;

        return ['stage' => $mapping->coordCardLaneStageIds[$lane], 'lane' => $lane, 'unmapped' => $resolved['unmapped']];
    }

    /**
     * The issue's labels as carried on a reaction-target payload. Narrowed at the read
     * — a missing / non-list `labels` key reads as "no labels declared" (the
     * DEFAULT_LANE arm) rather than throwing — because the handlers do not author the
     * targets they are handed: `kanban_coord_card` / `kanban_coord_card_move` are
     * registered unconditionally in `HandlerRegistry`, so any classifier an operator
     * wires (docs/customization.md) can emit one with a payload of its own shape and no
     * `labels` key at all. This is a BOUNDARY read of a foreign payload, not back-compat
     * for a stored wire shape: reaction targets are never persisted (no targets table;
     * `bridge:replay` re-classifies the stored raw webhook body, so a replayed target is
     * always minted by today's classifier).
     *
     * @param  array<mixed>  $p
     * @return list<string>
     */
    public static function labelsFrom(array $p): array
    {
        $out = [];
        foreach (is_array($p['labels'] ?? null) ? $p['labels'] : [] as $label) {
            if (is_string($label) && $label !== '') {
                $out[] = $label;
            }
        }

        return $out;
    }
}
