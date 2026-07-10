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
        $reason = $card['block_reason'] ?? null;
        if (is_string($reason) && trim($reason) !== '') {
            return true;
        }

        $tags = $card['tags'] ?? [];

        return is_array($tags) && in_array('no-automove', $tags, true);
    }
}
