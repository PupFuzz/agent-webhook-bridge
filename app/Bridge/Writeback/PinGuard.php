<?php

namespace App\Bridge\Writeback;

use Illuminate\Support\Facades\Log;

/**
 * The pinned-card opt-out predicate (DL-178, cross-mover contract framework #113):
 * a non-empty `block_reason` OR a `no-automove` tag, regardless of the card's stage —
 * and, since card#8523, the ONE place a refusal on that predicate is REPORTED
 * ({@see refuses}), the same compare-and-report pairing {@see MappedBoardGuard} owns
 * for the DL-009 board rule.
 *
 * ⛔ {@see isPinned} ANSWERS "IS THIS CARD PINNED?", NOT "WILL IT MOVE?" — and the two were
 * conflated here. The docblock this replaces said the predicate was *"Shared by the
 * event-driven move handler and the reconciler so both honor a human pin identically"*,
 * which reads as a guarantee about the writeback and was a statement about two files:
 * a third mover already consulted it, two more never did, and the event path consulted
 * it on ONE of its six outcomes, so a merge moved a pinned card from DL-178 (2026-07-09)
 * until card#8289 while this sentence read as though it could not. {@see refuses} does
 * not change that: a caller with an override (the DL-194 unpark, the DL-195 revive) still
 * decides whether to ask, and this class still never answers "will it move".
 *
 * Which movers honour a pin is a property of the CALL SITES, so derive it rather than
 * trusting a list in the callee — `grep -rnP '^(?!\s*\*).*PinGuard::(isPinned|refuses)\(' app/`
 * against the `moveCard(` AND `archiveCard(` call sites is the whole method, and it takes
 * seconds. ⚠ The anchor and the trailing `\(` are load-bearing: without them the recipe also
 * returns comment prose — this paragraph included — and `@see` mentions that consult nothing.
 * ⛔ AND THE YIELD IS NOT WRITTEN OUT HERE, WHICH IS A CORRECTION TO THIS PARAGRAPH RATHER
 * THAN A GAP IN IT (card#8557). It carried a dated snapshot of the movers — *"as of
 * card#8523 it yields…"* — one sentence after telling the reader to derive the set rather
 * than trust a list in the callee. A snapshot beside its own recipe is the list, and this
 * class has now watched two of them go short: the field-write clause below named a subset
 * and called ITSELF the roster, and the prose roster the card#8557 ruling was argued from
 * named the NAME producers and missed one — `board_correct_card`, which was a live
 * pin-blind name write. Run the recipe; it takes seconds and it cannot be stale.
 *
 * WHAT A GREP CANNOT TELL YOU, and is therefore what this paragraph keeps: (a) two arms
 * OVERRIDE a pin deliberately and alert instead of refusing — the DL-194 unpark and the
 * DL-195 revive, both outcomes of the PR move handler, and both tested BEFORE the consult
 * so they never reach it; (b) one consult is not at a call site at all — the shared
 * duplicate-collapse kernel {@see CardCollapse::toSurvivor()} (DL-340) carries the
 * predicate to every one of its callers at once, the board-tools create outside the
 * mapped-board regime included, so a handler's own census does not reach it.
 * {@see CardCollapse} is the ONE owner of that caller population and of the recipe that
 * re-derives it; no count is restated here.
 *
 * ⭐ ONE CORRECTION THIS DOCBLOCK OWES, because it is a claim this docblock ITSELF made and
 * card#8557 falsified — history, not a roster: KanbanCoordCardMoveHandler's `revive` and
 * `relane` legs are no longer outside the pin. What they were before is worth keeping,
 * because the reasoning is what made the gap survive review: both write a LANE and both
 * already refused any card whose current stage was not SERVICE-set
 * (`KanbanCoordCardMoveHandler::serviceSet()`),
 * so a human PLACEMENT was not overridden there — which is not the same as a HOLD being
 * honoured, and PR #649's review confirmed both legs were live-reachable on a pinned card
 * (pinning is a field PATCH, so `last_stage_move` stays service-set). The operator approved
 * the widening under card#8523's gate, because it changes what the system refuses.
 *
 * ⛔ A ROW THAT CARRIES NEITHER PIN FIELD IS A DEGRADED READ, AND IT IS DETECTED HERE
 * ({@see reportUnreadableRow}) rather than assumed away. DL-340 first shipped saying this
 * seam was undetectable from the bridge side; that was false, and the detector is what
 * replaces the claim.
 *
 * ⛔ ARCHIVE IS A WRITE IN THIS POPULATION, not only `moveCard`: card#8454's instance was a
 * closed-unmerged dependabot PR RETIRING a pinned card, which a `moveCard(`-only census
 * would not have surfaced — and card#8523's second instance was the collapse's archive,
 * one layer under its callers, where no handler's own census reaches it.
 *
 * ⚑ AND THE RECIPE'S SCOPE IS THE OTHER HALF OF READING IT. `moveCard(` / `archiveCard(`
 * is not every PATCH the bridge sends to a card, because the pin does not govern every
 * FIELD — only the ones {@see PINNED_FIELDS} names, which is the rule and the only place
 * it is written down. DL-178's 2026-08-30 annotation read *"the pin governs the card's
 * STAGE only"*; DL-335 widened it by the ARCHIVE, on the reading that retiring a card is a
 * lifecycle act and not a field, and card#8557 widened it again by the NAME. Everything
 * outside that const still lands on a pinned card BY DESIGN, not by omission — which is
 * what keeps the correlation-ref stamp
 * (`KanbanMoveCardHandler::stampCorrelationRefs()`) running on a refused move, the
 * property DL-178's own annotation depends on. ⛔ WHICH PRODUCERS those are is NOT restated
 * here. An earlier draft of this clause named a subset of them and called ITSELF the roster,
 * which is what an auditor reads as a complete list — and the prose roster the card#8557
 * ruling was argued from then missed a NAME producer that was live and pin-blind. The
 * `PATCH /api/v3/tasks/{id}.json` row of `docs/kanban-integration-contract.md` owns that
 * population, and it is re-derivable in seconds rather than trusted:
 * `grep -rnP '^(?!\s*[/*]).*->patchCard\(' app/`. ⚠ The character class is spelt `[/*]` and NOT the
 * other way round, which would close this docblock at that character; the anchor and the
 * trailing `\(` are load-bearing for the same reason they are on the census above.
 * {@see KanbanClient::patchCard} is the single primitive a field write is expressed in
 * ({@see KanbanClient::archiveCard} deliberately is not — it sends a top-level `_action` CONTROL
 * key, the same distinction that puts the ARCHIVE inside the pin and a field write outside it),
 * so a producer absent from that census is absent from the bridge. ⭐ AND THE CENSUS IS NO
 * LONGER A THING A READER HAS TO REMEMBER TO RUN:
 * `PinnedFieldWriteCoverageTest` derives that same population every run and reds on a
 * `patchCard` site nobody has dispositioned — which is how a NEW name producer is made to
 * arrive as a review event instead of as a silence. The `moveCard(` / `archiveCard(` census
 * above surfaces none of these sites, and should not: read DL-178's annotation and DL-335's
 * widening for why, rather than treating the absence as a further instance of this card's
 * defect.
 */
final class PinGuard
{
    /** The reason code every pinned-card refusal shares (third element of the alert dedup tuple). */
    public const REASON = 'pinned_no_automove';

    /**
     * ⭐ THE FIELD WRITES THE PIN REACHES — the card#8557 narrowing of DL-178's 2026-08-30
     * annotation (*"the pin governs the card's STAGE only"*), which was ruled when ONE arm
     * wrote a field and is not re-affirmed unchanged now that several do. This const IS the
     * rule: every consult that asks "may this field set land on a held card" reads it, so
     * widening or narrowing the rule is one edit here rather than a sweep.
     *
     * ⭐ WHY A NAME AND NOT EVERY FIELD, so the ruling can be attacked rather than inherited.
     * The pin's purpose is that the card stops changing under the operator, and a restamp
     * that silently rewrites the NAME of a frozen card defeats that in exactly the way a
     * stage move does — the operator comes back to a card that does not look like the one
     * they froze, and every producer that writes a name does so with NO HUMAN IN THE LOOP,
     * which is what separates it from a field a caller sets deliberately. A blanket freeze
     * on every field was REJECTED rather than overlooked: it would stop the correlation
     * stamps automation legitimately writes (`pr_number`, `pr_url`, `dl_number`), which
     * record what happened TO a card without restating what the card IS — and a refused
     * move still stamping its refs is the property that keeps a held card inside
     * `bridge:reconcile`'s population, which DL-178's own annotation depends on. Symmetry
     * would have cost a real capability.
     *
     * ⛔ THE PRODUCERS ARE NOT NAMED HERE, and a future edit must not name them: that roster
     * belongs to the `PATCH /api/v3/tasks/{id}.json` row of
     * `docs/kanban-integration-contract.md`, re-derivable by the recipe in this class's
     * docblock and re-derived every run by `PinnedFieldWriteCoverageTest`.
     *
     * @var list<string>
     */
    public const PINNED_FIELDS = ['name'];

    /**
     * The reason code the DEGRADED-ROW detector logs under ({@see reportUnreadableRow}).
     * It is deliberately NOT {@see REASON}: "this card is pinned and I refused" and "I
     * could not tell whether this card is pinned" are different facts, and an operator
     * filtering one must not be shown the other.
     */
    public const UNREADABLE_ROW_REASON = 'pin_row_unreadable';

    /**
     * @param  array<string, mixed>  $card
     */
    public static function isPinned(array $card): bool
    {
        self::reportUnreadableRow($card);

        $reason = self::blockReason($card);
        if ($reason !== null && trim($reason) !== '') {
            return true;
        }

        return in_array('no-automove', self::tags($card), true);
    }

    /**
     * The predicate AND its refusal report: true when the card is PINNED and the caller
     * must skip the write it was about to make (permanent refusal — alert + log + no-op,
     * never a 5xx retry); false when it may proceed.
     *
     * ⭐ THE REPORT IS INSIDE THE PRIMITIVE for the reason {@see MappedBoardGuard::refuses}
     * is (DL-292): by card#8523 the consult-then-report shape had four writers and was about
     * to have six, and a shape written six times is one that can be minted with a different
     * reason code, a different log level, or no live signal at all — which is precisely how
     * eleven of twelve refusal arms came to be silent (card#5312 / DL-274). The reason code
     * is a const here rather than a literal at each site for the same reason.
     *
     * $arm is the reaction name the message is prefixed with; $write names WHICH write was
     * refused ("merged move", "archive", "duplicate archive"), because a handler with two
     * writes must be readable from one line. $outcome is the second element of the alert
     * dedup tuple: the arms that carry a synthetic constant (`dependabot_card`,
     * `coord_card_move`) collapse their writes into one marker per repo deliberately —
     * see the callers' own docblocks for what that costs.
     *
     * ⚠ It does NOT decide the OVERRIDES. A caller that may move a pinned card on some
     * outcome (the DL-194 unpark, the DL-195 revive) tests that first and does not ask —
     * folding those in would put an event-shaped condition inside a card-shaped predicate.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $logContext  arm-specific context; `card_id` and `repo` are added here
     */
    public static function refuses(
        WritebackAlertNotifier $alerts,
        array $card,
        string $arm,
        string $write,
        int $cardId,
        string $repo,
        string $outcome,
        array $logContext = [],
        ?int $issueNumber = null,
    ): bool {
        if (! self::isPinned($card)) {
            return false;
        }

        $alerts->warnAndNotify(
            "{$arm}: {$write} refused — card is pinned (block_reason/no-automove)",
            ['card_id' => $cardId, 'repo' => $repo] + $logContext,
            $repo, $outcome, $cardId, self::REASON, $issueNumber,
        );

        return true;
    }

    /**
     * THE FIELD-WRITE ARM OF THE SAME PAIRING — the predicate AND its refusal report, for a
     * caller about to PATCH a flat field set rather than move or retire a card: true when
     * $fields writes a field {@see PINNED_FIELDS} governs onto a card that is PINNED, and
     * the caller must send nothing.
     *
     * It is a separate entry point from {@see refuses} rather than an extra argument on it
     * because the two answer different questions. `refuses` is asked by a STAGE or
     * LIFECYCLE write, where the whole write is the subject and there is no field set to
     * weigh; this one is asked by a field write, where the answer depends on WHICH fields
     * are being sent — most of them still land on a held card by design. Folding the field
     * set into `refuses` as an optional argument would give every stage/lifecycle caller an
     * argument that means nothing to it and make its ABSENCE the load-bearing thing.
     *
     * ⚠ IT SHORT-CIRCUITS BEFORE THE PIN IS READ when no governed field is present, and that
     * ordering is deliberate rather than an optimisation: {@see isPinned} runs the
     * degraded-row detector, and a `description`-only correction that emitted
     * `pin_row_unreadable` would be reporting a degradation on a read whose answer it never
     * used.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $fields  the flat field set the caller is about to PATCH
     * @param  array<string, mixed>  $logContext  arm-specific context; `card_id` and `repo` are added by {@see refuses}
     */
    public static function refusesFieldWrite(
        WritebackAlertNotifier $alerts,
        array $card,
        array $fields,
        string $arm,
        string $write,
        int $cardId,
        string $repo,
        string $outcome,
        array $logContext = [],
        ?int $issueNumber = null,
    ): bool {
        if (! self::governs($fields)) {
            return false;
        }

        return self::refuses($alerts, $card, $arm, $write, $cardId, $repo, $outcome, $logContext, $issueNumber);
    }

    /**
     * The same question with NO REPORT — for the one caller whose refusal channel is not an
     * `alert_channel` push: the board-tools door, which has no repo and no outcome to key
     * the `(repo, outcome, reason)` dedup tuple on, and whose refusal is SYNCHRONOUS — the
     * seat that asked is told, by name, in the reply to its own call.
     *
     * ⛔ IT IS NOT A LICENCE TO REFUSE QUIETLY. The report lives inside {@see refuses} because
     * a consult-then-report shape written N times is one that gets minted with no live
     * signal at all (DL-274's eleven-of-twelve). A caller reaching for THIS method owes its
     * own loud refusal carrying {@see REASON}, and the silence card#8557 was filed on is
     * exactly what an author who used it and reported nothing would re-mint.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $fields
     */
    public static function isPinnedAgainst(array $card, array $fields): bool
    {
        return self::governs($fields) && self::isPinned($card);
    }

    /**
     * Whether $fields writes any field the pin governs. The ONE reading of
     * {@see PINNED_FIELDS}, so the rule cannot be half-applied by a second spelling of the
     * same test.
     *
     * PRESENCE, not value: a caller sending `['name' => null]` is still writing the name.
     *
     * @param  array<string, mixed>  $fields
     */
    private static function governs(array $fields): bool
    {
        foreach (self::PINNED_FIELDS as $field) {
            if (array_key_exists($field, $fields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ⛔ THE DEGRADED-ROW DETECTOR — the answer to a claim DL-340 first shipped and that was
     * FALSE: that the pin's row-shape dependency on kanban could not be checked from this
     * side. It can, here, with no request to anyone.
     *
     * kanban's `TaskResource::toArray` emits `block_reason` AND `tags` unconditionally on
     * every row it serves — the by-id read and the `tasks/search.json` projection alike, since
     * `TasksController::search` paginates `['*']` into that same resource. So a row reaching
     * this predicate carrying NEITHER key did not come from a healthy read: either the far end
     * slimmed its projection (it has moved this endpoint before — DL-296's archive switch,
     * DL-146's envelope), or a caller handed in a row it never actually read (card#8523's
     * `array_fill_keys($ids, [])`). Both degrade the predicate toward "not pinned", which is
     * degrading toward WRITING — the unsafe direction, and silent until this fires.
     *
     * ⭐ LOUD AND NOTHING ELSE, and that bound is deliberate: this changes what the system
     * REPORTS, never what it accepts or refuses. Refusing a write on an unreadable row is an
     * operator ruling nobody has made — it is the same gate that correctly deferred the coord
     * `revive`/`relane` widening — and it is filed on card#8557, not taken here. The posture is
     * DL-026's, applied at the single read every consult shares: *make the non-erroring
     * degradation LOUD at the one place that sees it*, and let the caller's path stay unchanged.
     *
     * ⚑ ONE LINE PER ROW, WITH NO RATE LIMIT, AND THAT IS A CHOICE RATHER THAN AN OVERSIGHT.
     * `ReconcileCommand` and `KanbanPromoteReleasedHandler` consult the pin once per candidate
     * card, so a genuinely degraded projection emits one warning per card per sweep — a 50-row
     * board is 50 lines (measured on a 50-row fixture at PR #649's R2). Three reasons that is
     * the right shape here. (1) The ACTIONABLE fact is `card_id`: a per-run "N rows were
     * unreadable" line names no card, and the operator's next move is to look at one. (2) The
     * cost is a durable log write — no alert push, no dedup marker, no HTTP, nothing on the
     * writeback's request path — and the volume is BOUNDED by the sweep, not unbounded: the
     * degradation is all-or-nothing (the projection either carries the pair or does not), so
     * the line count is the board size and does not grow while the fault persists. (3) A
     * dedup would need per-run state inside a static predicate that has none, and would hide
     * exactly the per-card detail. ⚠ THE RESIDUAL IS STATED, NOT WAVED: the argument for the
     * AND above is that a detector which cries wolf gets muted, and a full-board sweep on a
     * degraded projection IS loud. If that volume turns out to be what mutes it, the fix is a
     * per-run claim at the SWEEP (which knows how many rows it read), not a counter here.
     *
     * ⚑ BOTH keys absent, not either. `TaskResource` emits the pair together, so their joint
     * absence is the degradation's own shape, while a row carrying exactly one is a partial
     * nothing in this repo constructs. An OR would fire on every legitimately untagged card
     * whose `block_reason` a caller had projected away, and a detector that cries wolf is one
     * an operator mutes.
     *
     * @param  array<string, mixed>  $card
     */
    private static function reportUnreadableRow(array $card): void
    {
        if (array_key_exists('block_reason', $card) || array_key_exists('tags', $card)) {
            return;
        }

        Log::warning(
            'pin consult: this card row carries NEITHER block_reason NOR tags, so the DL-178 pin '
            .'predicate is about to answer "not pinned" for a card whose pin nobody could read — '
            .'kanban emits both fields on every row, so this read has DEGRADED (the projection '
            .'dropped them, or a caller handed in a row it never read) and every pin-guarded '
            .'write reached through this row is unguarded until it is fixed',
            [
                'card_id' => is_numeric($card['id'] ?? null) ? (int) $card['id'] : null,
                'reason' => self::UNREADABLE_ROW_REASON,
            ],
        );
    }

    /**
     * The card's `block_reason` as a string, or null when absent/non-string — the
     * boundary-safe read (a kanban card is a system boundary; `block_reason` may be
     * non-string). Untrimmed: callers apply their own trim (isPinned trims; a
     * draft-sentinel equality check needs the raw value).
     *
     * @param  array<string, mixed>  $card
     */
    public static function blockReason(array $card): ?string
    {
        $reason = $card['block_reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * The card's `tags` as a list, or `[]` when absent/non-array — the boundary-safe
     * read (`tags` may be non-array). A bare `in_array` over a non-array is a PHP 8.5
     * TypeError, so every caller reads tags through here.
     *
     * @param  array<string, mixed>  $card
     * @return array<mixed>
     */
    public static function tags(array $card): array
    {
        $tags = $card['tags'] ?? [];

        return is_array($tags) ? $tags : [];
    }
}
