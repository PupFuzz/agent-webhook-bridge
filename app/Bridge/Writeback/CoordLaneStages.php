<?php

namespace App\Bridge\Writeback;

/**
 * The coordination board's PRIORITY-LANE model as the coord-card CREATE path needs
 * it: which `stage:*` label declares which lane, which lane an undeclared issue
 * defaults to, and which coordination issues the lane model governs at all.
 *
 * WHY THIS EXISTS. The bridge creates a coord card in real time (DL-198) at the
 * mapping's fixed `coord_card_stage_id`, ignoring the issue's `stage:*` label. On a
 * board whose lane model is live that is not a placement, it is a REWRITE: the
 * consumer's `kanban-writeback` pass runs BEFORE its issues-sync and maps the card's
 * lane back onto the issue's `stage:*` label, so the label the bridge's create stage
 * implies is written onto the issue and the sync then agrees with it. Measured on the
 * reference install: 9 issues flipped to `stage:now`, one within 7 minutes of filing
 * (card#6348 — the reporter's install, sola; not resolvable on this repo's board). The
 * create stage must therefore be DERIVED from the label the issue already carries, not
 * fixed.
 *
 * WHY A SECOND IMPLEMENTATION. The rule's home is Python — the consumer's
 * `kanban-issues-sync` `_STAGE_LANE` / `_task_lane` / `classify_coord` — and the
 * bridge is PHP and cannot import it. This is a deliberate mirror of a rule the bridge
 * does not own, the same shape (and the same obligation to re-port on a rule change) as
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
 *   - the availability test's POSITION — inside the resolution loop, so a lane this
 *     board does not map is skipped and the scan continues (see {@see resolveLane});
 *   - which issues the lane model governs. `classify_coord` routes an issue through
 *     `_task_lane` iff `title.upper().startswith("[TASK]")` — an ANCHORED TITLE test,
 *     deliberately not an itype test, and its own comment says why: `_itype` defaults
 *     un-prefixed issues to `task` too, so keying on itype would sweep the whole
 *     un-prefixed population into the lane model. So the gate here is
 *     {@see LANE_MODEL_TITLE_PREFIX} on the raw title ({@see governs}), and a
 *     `[BRIEF]`/`[ANNOUNCE]`/`[QUERY]`/`[REVIEW]`/`[PROPOSAL]`/un-prefixed card keeps
 *     landing in the mapping's fixed `coord_card_stage_id` — the PRE-EXISTING
 *     fixed-stage behaviour, and not a claim that the two movers agree there:
 *     `classify_coord` sends a non-`[TASK]` open issue to *Awaiting ACK* when it is an
 *     announce and to *Now* otherwise, so the bridge agrees with it only where
 *     `coord_card_stage_id` IS the board's Now column, and never for `[ANNOUNCE]`. That
 *     create disagreement predates the lane model and is recorded in DL-286's sibling
 *     audit; lane-deriving more than the consumer's set would ADD a second one — those
 *     cards would land in `later` while the reconcile still wants Now / Awaiting ACK —
 *     which is the failure {@see CoordConfigTerminals} exists to prevent, in a direction
 *     nothing would repair (`user_lanes` then preserves whichever won).
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
     * The lane an issue declaring no MAPPED `stage:*` label lands in (`_task_lane`'s
     * `return "Later"`, reached the same way: after the scan finds nothing). Its
     * presence in the map is REQUIRED at load — without it the fallback has no target.
     */
    public const DEFAULT_LANE = 'later';

    /**
     * The anchored title prefix `classify_coord` gates the lane model on
     * (`title.upper().startswith("[TASK]")`). Everything else keeps the mapping's
     * fixed create stage (see the class docblock).
     */
    public const LANE_MODEL_TITLE_PREFIX = '[TASK]';

    private const LABEL_PREFIX = 'stage:';

    /**
     * Whether the lane model governs this issue at all — the consumer's gate, mirrored
     * expression-for-expression: `title.upper().startswith("[TASK]")` on the title as
     * delivered.
     *
     * The title is deliberately NOT trimmed, where the bridge's own
     * `CoordinationClassifier::stableId()` does trim before its own anchored match
     * (named in prose, not an `@see`: the FQCN makes the formatter import a classifier
     * into this namespace for a docblock). That divergence is the point: this gate's
     * contract is *the set of issues the consumer lane-derives*, not *the set the bridge
     * cards*, so a leading-whitespace title the consumer would send to `Now` must not be
     * lane-derived here just because the bridge's adoption key tolerates it.
     */
    public static function governs(string $title): bool
    {
        return str_starts_with(strtoupper($title), self::LANE_MODEL_TITLE_PREFIX);
    }

    /**
     * The lane an issue's labels resolve to on a board mapping $mappedLanes, together
     * with the lanes it DECLARED that the map does not carry (the caller's warn
     * material — a config gap the operator must see).
     *
     * Mirrors `_task_lane` including WHERE the availability test sits: INSIDE the loop
     * (`if label in names and LANE_TO_COLUMN[lane] in columns`). A declared lane the
     * board does not carry is SKIPPED and the scan continues to the next `stage:*`
     * label in {@see LANES} order; the default is reached only when the scan is
     * exhausted. Testing availability after the loop instead — resolve first, then fall
     * back — would put an issue labelled `stage:now` + `stage:next` on a board with no
     * Now column in `later` here and in `Next` there: a create-time disagreement the
     * consumer's `user_lanes` then preserves, which is the whole failure this mirror
     * exists to prevent.
     *
     * `lane` is null when no MAPPED lane is declared (no `stage:*` label, only
     * unrecognized ones like `stage:someday`, or only unmapped ones) — the caller's
     * {@see DEFAULT_LANE} cue. Labels are lowercased here (the Python read-site
     * lowercases too), so a `Stage:Now` label from a hand-edit still resolves.
     *
     * @param  list<string>  $labels
     * @param  list<string>  $mappedLanes  the lanes `coord_card_lane_stage_ids` carries for this board
     * @return array{lane: ?string, unmapped: list<string>}
     */
    public static function resolveLane(array $labels, array $mappedLanes): array
    {
        $names = [];
        foreach ($labels as $label) {
            $names[strtolower($label)] = true;
        }
        $unmapped = [];
        foreach (self::LANES as $lane) {
            if (! isset($names[self::LABEL_PREFIX.$lane])) {
                continue;
            }
            if (in_array($lane, $mappedLanes, true)) {
                return ['lane' => $lane, 'unmapped' => $unmapped];
            }
            $unmapped[] = $lane;
        }

        return ['lane' => null, 'unmapped' => $unmapped];
    }
}
