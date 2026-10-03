<?php

namespace App\Bridge\Writeback;

use Illuminate\Support\Facades\Log;

/**
 * card#10869, operator ruling A: a bridge move that takes a card OUT OF A START COLUMN on a card
 * that records NO OWNER — neither a kanban assignee nor a legacy `owner:` tag — is MADE, and then
 * raised: a durable log line and a live alert, "moved card N with no owner recorded".
 *
 * A start column is one of the mapping's `started_from_stages` / `unpark_from_stages` (the columns
 * a card is pulled from when work begins), or the `closed_unmerged` abandon stage a `reopened`
 * revival pulls a card back out of. The predicate is the column the card LEAVES, not the outcome
 * that moved it, so every mover that can take such a move asks this one class: the PR/branch
 * writeback (`started`, `opened`, a `reopened` revival or unpark) and `bridge:reconcile --fix`.
 *
 * ⭐ A SIGNAL, NEVER A REFUSAL (ruling A: "never refuse"). The owner is recorded by the SEAT — the
 * toolkit's card-start claim or `board_take_card`; a GitHub event or a reconcile pass names no
 * seat, so the bridge cannot record one itself. This makes "a card started with nobody on it"
 * visible where no seat claimed it.
 *
 * ⚠ BOUNDS. The card read is the one the mover already holds, taken BEFORE its move: a claim
 * written between that read and the move is not seen, which errs toward an alert that says too
 * much. A mapping that declares no start set has no start columns the bridge knows of, so only
 * revivals are checked there. An absent `assigned_user_id` key reads as "no assignee" — kanban
 * projects the key on both reads the movers take, the event path's `GET /tasks/{id}.json` and
 * `bridge:reconcile`'s board-scan search (`docs/kanban-integration-contract.md` § 2 declares both),
 * and a kanban that stopped would make this alert on every start, loudly rather than silently.
 */
final class OwnerlessStart
{
    /**
     * Log and alert when this move left a start column on a card with no owner recorded; do
     * nothing otherwise. Call it only AFTER the move has landed.
     *
     * @param  array<string, mixed>  $card  the card as the mover read it before the move
     * @param  bool  $fromAbandon  the move is a `reopened` revival out of the abandon stage
     */
    public static function noteAfterMove(
        WritebackAlertNotifier $alerts,
        array $card,
        WritebackMapping $mapping,
        bool $fromAbandon,
        int $cardId,
        string $repo,
        string $outcome,
        int $toStage,
    ): void {
        $current = $card['workflow_stage_id'] ?? null;
        $startColumns = array_merge($mapping->startedFromStages ?? [], $mapping->unparkFromStages ?? []);
        if ((! $fromAbandon && ! in_array($current, $startColumns, true)) || is_numeric($card['assigned_user_id'] ?? null)) {
            return;
        }
        $tags = CardTags::readable($card);
        if ($tags !== null && array_filter($tags, OwnerTag::is(...)) !== []) {
            return;
        }

        $fromStage = is_numeric($current) ? (int) $current : null;
        Log::warning('writeback: moved a card with no owner recorded — no kanban assignee and no owner: tag; the seat working it has not claimed it', [
            'catalog_id' => 'owner.moved_without_owner',
            'handler' => BoardMoverScope::handler(),
            'op' => 'move',
            'card_id' => $cardId, 'repo' => $repo, 'outcome' => $outcome, 'from_stage' => $fromStage, 'to_stage' => $toStage,
            'tags_readable' => $tags !== null,
        ]);
        $alerts->notifyMovedWithoutOwner($repo, $cardId, $outcome, $fromStage, $toStage);
    }
}
