<?php

namespace App\Bridge\Writeback;

/**
 * The coordination board's PRIORITY-LANE model as the coord-card CREATE path needs
 * it: which `stage:*` label declares which lane, which lane an undeclared issue
 * defaults to, and which coordination itype the lane model governs at all.
 *
 * WHY THIS EXISTS. The bridge creates a coord card in real time (DL-198) at the
 * mapping's fixed `coord_card_stage_id`, ignoring the issue's `stage:*` label. On a
 * board whose lane model is live that is not a placement, it is a REWRITE: the
 * consumer's periodic reconcile adopts the bridge's card by its `id:<sid>` tag and
 * then PRESERVES its lane (config `user_lanes`), and the consumer's board→issue
 * writeback propagates lane→label — so a `[TASK]` filed `stage:later` is carded in
 * the create stage, preserved there, and its label rewritten to match. Measured on
 * the reference install: 9 issues flipped to `stage:now`, one within 7 minutes of
 * filing (card#6348). The create stage must therefore be DERIVED from the label the
 * issue already carries, not fixed.
 *
 * WHY A SECOND IMPLEMENTATION. The rule's home is Python — the consumer's
 * `kanban-issues-sync` `_STAGE_LANE` / `_task_lane` / `classify_b2` — and the bridge
 * is PHP and cannot import it. This is a deliberate mirror of a rule the bridge does
 * not own, the same shape (and the same obligation to re-port on a rule change) as
 * {@see CoordConfigTerminals}. The `coordination.config.json` read that would let the
 * bridge derive the lane NAMES itself is deliberately CLI-only — see that class — so
 * the lane→stage-id half is operator config (`coord_card_lane_stage_ids`), exactly
 * like every other stage id the writeback targets.
 *
 * WHAT IS MIRRORED, precisely:
 *   - the four labels and their lane order — `_STAGE_LANE`'s insertion order, which is
 *     what makes a multi-labelled issue resolve to the same lane on both movers;
 *   - the `later` default for an issue that declares no recognized lane — `_task_lane`'s
 *     `return "Later"`;
 *   - the itype the lane model governs. `classify_b2` routes ONLY `_itype == "task"`
 *     through `_task_lane`; every other open itype goes to a fixed column. So the lane
 *     derivation is scoped to {@see LANE_MODEL_ITYPE} and a `[BRIEF]`/`[QUERY]`/
 *     `[REVIEW]` card keeps landing in the mapping's fixed `coord_card_stage_id`. A
 *     bridge that lane-derived every itype would place a fresh brief in `Later` where
 *     the reconcile places it in the create stage — two movers disagreeing at create,
 *     which is the failure {@see CoordConfigTerminals} exists to prevent, in a
 *     direction nothing would repair (the lane is then preserved).
 *
 * `itype` is the bridge's own `CoordinationClassifier::coordItype()` (named in prose,
 * not an `@see`: the FQCN makes the formatter import a classifier into this namespace
 * for a docblock), already a byte-exact mirror of the same Python `_itype` — so "task"
 * here means what it means there, including its fallback breadth (any title the
 * BRIEF/ANNOUNCE/QUERY/REVIEW scan misses).
 */
final class CoordLaneStages
{
    /**
     * The lane keys, in `_STAGE_LANE` order — the order a multi-labelled issue is
     * resolved in, and the exact key set `coord_card_lane_stage_ids` may carry.
     *
     * @var list<string>
     */
    public const LANES = ['now', 'next', 'later', 'maybe'];

    /**
     * The lane an issue declaring no recognized `stage:*` label lands in
     * (`_task_lane`'s `return "Later"`), and the fallback for a declared lane the
     * operator's map does not carry. Its presence in the map is REQUIRED at load —
     * without it neither fallback has a target.
     */
    public const DEFAULT_LANE = 'later';

    /**
     * The one coordination itype `classify_b2` routes through the lane model. Every
     * other itype keeps the mapping's fixed create stage (see the class docblock).
     */
    public const LANE_MODEL_ITYPE = 'task';

    private const LABEL_PREFIX = 'stage:';

    /** Whether the lane model governs this coordination itype at all. */
    public static function governs(string $itype): bool
    {
        return $itype === self::LANE_MODEL_ITYPE;
    }

    /**
     * The lane key an issue's labels DECLARE, or null when they declare none the
     * lane model recognizes (no `stage:*` label at all, or only unrecognized ones
     * like `stage:someday`). Null is the caller's cue to use {@see DEFAULT_LANE} —
     * kept distinct from a declared-but-unmappable lane, which the caller reports.
     *
     * Resolution order is {@see LANES}, mirroring `_task_lane`'s iteration over
     * `_STAGE_LANE`: an issue carrying BOTH `stage:now` and `stage:later` resolves
     * to `now` on both movers rather than to whichever the label list happens to
     * list first. Labels are lowercased here (the Python read-site lowercases too),
     * so a `Stage:Now` label from a hand-edit still resolves.
     *
     * @param  list<string>  $labels
     */
    public static function laneFromLabels(array $labels): ?string
    {
        $names = [];
        foreach ($labels as $label) {
            $names[strtolower($label)] = true;
        }
        foreach (self::LANES as $lane) {
            if (isset($names[self::LABEL_PREFIX.$lane])) {
                return $lane;
            }
        }

        return null;
    }
}
