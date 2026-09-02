<?php

namespace App\Bridge\Writeback;

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
 * trusting a list in the callee — `grep -rn "PinGuard::isPinned\|PinGuard::refuses" app/`
 * against the `moveCard(` AND `archiveCard(` call sites is the whole method, and it takes
 * seconds. As of card#8523 it yields every STAGE-or-LIFECYCLE write the bridge makes:
 * the live event path (KanbanMoveCardHandler, on every outcome, minus the DL-194 unpark
 * and DL-195 revive, which override a pin deliberately and alert), `bridge:reconcile`
 * (ReconcileCommand, unconditional), the release-promote sweep (KanbanPromoteReleasedHandler,
 * in its candidate filter), KanbanDependabotCardHandler (DL-335 — its closed-unmerged
 * archive and its survivor move, with no override), KanbanCoordCardMoveHandler's
 * `terminal` leg (DL-340 — an `issues.closed` no longer concludes a parked card), and the
 * shared duplicate-collapse kernel {@see CardCollapse::toSurvivor()} (DL-340), which
 * carries the predicate to its THREE callers at once, the board-tools create outside the
 * mapped-board regime included.
 *
 * ⚠ TWO legs of KanbanCoordCardMoveHandler are still outside it, and the exclusion is
 * narrow and deliberate rather than an omission: `revive` (:287) and `relane` (:349) both
 * write a LANE and both already refuse any card whose current stage was not SERVICE-set
 * (`KanbanCoordCardMoveHandler::serviceSet()`), so a human placement is not
 * overridden there today. Whether the pin should ALSO reach a service-set card on those
 * two legs was not asked of the operator with card#8523's two, and this class may not
 * close it on its own: it changes what the system refuses.
 *
 * ⛔ ARCHIVE IS A WRITE IN THIS POPULATION, not only `moveCard`: card#8454's instance was a
 * closed-unmerged dependabot PR RETIRING a pinned card, which a `moveCard(`-only census
 * would not have surfaced — and card#8523's second instance was the collapse's archive,
 * one layer under three handlers.
 *
 * ⚑ AND THE RECIPE'S SCOPE IS THE OTHER HALF OF READING IT. `moveCard(` / `archiveCard(`
 * is not every PATCH the bridge sends to a card, because the pin does not govern every
 * field: DL-178's rule as annotated on 2026-08-30 is that the pin governs the card's STAGE,
 * which is why a refused move still stamps its correlation refs
 * (`KanbanMoveCardHandler::stampCorrelationRefs()`) — DL-335 widened it by exactly one
 * write, the ARCHIVE, on the reading that retiring a card is a lifecycle act and not a
 * field. So a field-only PATCH lands on a pinned card BY DESIGN, not by omission: the
 * correlation-ref stamp here, and the DL-328 `{name}`-only restamp on an upstream retitle
 * (`KanbanDependabotCardHandler::restampNames()`, which lands it on a pinned
 * card deliberately — it writes `name` alone, moves no card and retires none). An auditor
 * running the census above will not see either one, and should not: read DL-178's
 * annotation and DL-335's widening for why, rather than treating the absence as a further
 * instance of this card's defect. ⚠ *Deliberate* is a statement about the RULE'S SCOPE, not
 * a ruling that a hold should never stop a field write — nobody has been asked that, and
 * `docs/writeback.md`'s dependabot pin paragraph is where it is filed.
 */
final class PinGuard
{
    /** The reason code every pinned-card refusal shares (third element of the alert dedup tuple). */
    public const REASON = 'pinned_no_automove';

    /**
     * @param  array<string, mixed>  $card
     */
    public static function isPinned(array $card): bool
    {
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
