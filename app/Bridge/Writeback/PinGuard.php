<?php

namespace App\Bridge\Writeback;

/**
 * The pinned-card opt-out predicate (DL-178, cross-mover contract framework #113):
 * a card a human has parked is never auto-moved by the writeback — a non-empty
 * `block_reason` OR a `no-automove` tag, regardless of its stage. Shared by the
 * event-driven move handler (KanbanMoveCardHandler) and the reconciler
 * (bridge:reconcile) so both honor a human pin identically.
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
