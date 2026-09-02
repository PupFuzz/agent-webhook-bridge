<?php

namespace App\Bridge\Writeback;

/**
 * The pinned-card opt-out predicate (DL-178, cross-mover contract framework #113):
 * a non-empty `block_reason` OR a `no-automove` tag, regardless of the card's stage.
 *
 * ⛔ THIS CLASS ANSWERS "IS THIS CARD PINNED?", NOT "WILL IT MOVE?" — and the two were
 * conflated here. The docblock this replaces said the predicate was *"Shared by the
 * event-driven move handler and the reconciler so both honor a human pin identically"*,
 * which reads as a guarantee about the writeback and was a statement about two files:
 * a third mover already consulted it, two more never did, and the event path consulted
 * it on ONE of its six outcomes, so a merge moved a pinned card from DL-178 (2026-07-09)
 * until card#8289 while this sentence read as though it could not.
 *
 * Which movers honour a pin is a property of the CALL SITES, so derive it rather than
 * trusting a list in the callee — `grep -rn "PinGuard::isPinned" app/` against the
 * `moveCard(` AND `archiveCard(` call sites is the whole method, and it takes seconds. As
 * of card#8454 it yields: the live event path (KanbanMoveCardHandler, on every outcome,
 * minus the DL-194 unpark and DL-195 revive, which override a pin deliberately and alert),
 * `bridge:reconcile` (ReconcileCommand, unconditional), the release-promote sweep
 * (KanbanPromoteReleasedHandler, in its candidate filter), and KanbanDependabotCardHandler
 * (DL-335 — its closed-unmerged archive and its survivor move, with no override) — and it
 * does NOT yield KanbanCoordCardMoveHandler (close / revive / relane), which moves a pinned
 * card today. Whether the pin should reach that one is an open question for the operator,
 * not an omission this class may close on its own: it changes what the system refuses.
 *
 * ⛔ ARCHIVE IS A WRITE IN THIS POPULATION, not only `moveCard`: card#8454's instance was a
 * closed-unmerged dependabot PR RETIRING a pinned card, which a `moveCard(`-only census
 * would not have surfaced. `CardCollapse::toSurvivor()`'s duplicate-archive is the one
 * write still outside it, deliberately — it retires a bridge-minted create-race twin rather
 * than acting on a card's lifecycle, and it is shared with a caller outside the mapped-board
 * regime; DL-335 recorded that rather than widening the predicate into it.
 */
final class PinGuard
{
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
