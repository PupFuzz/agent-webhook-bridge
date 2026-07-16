<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Mirror a PR's DRAFT state onto its correlated card's `block_reason` field
 * (DL-193) — the writeback's OVERLAY reaction. Overlay ONLY: this never moves a
 * card between stages/columns (unlike KanbanMoveCardHandler); it writes a single
 * field. GitHubPrCardMoveClassifier emits this target (keyed by the card id as
 * target_id) for a mapping that opts in with `draft_overlay: true`, on
 * converted_to_draft / opened-as-draft (`action: set`) and ready_for_review
 * (`action: clear`).
 *
 * DATA-PRESERVATION (load-bearing — must not stomp a human's block_reason):
 *  - SET  = add-if-missing: GET the card; write the marker only when block_reason
 *           is currently empty (null / blank, matching PinGuard's trim semantics).
 *           A human reason — or our marker already there — is left untouched (idempotent).
 *  - CLEAR = clear-if-ours: GET the card; null block_reason only when its current
 *           value is EXACTLY the marker. A human-set reason is left intact.
 *
 * DL-178 interaction (intended): setting block_reason PINS the card (PinGuard), so
 * the writeback won't auto-move it while drafted; clearing on ready_for_review
 * releases the pin. No change to PinGuard.
 *
 * DURABLE, with the same transient(5xx → retry) / permanent(4xx → log + no-op) split
 * as the move handler (DL-020), and the same belongs-to-mapped-board security guard.
 * Idempotent: a no-op SET/CLEAR (already-marker / not-ours) writes nothing.
 */
final class KanbanBlockReasonHandler implements DurableReaction, Handler
{
    /** The marker written by an add-if-missing SET; a CLEAR only nulls a block_reason equal to it. */
    public const MARKER = 'PR is in draft';

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        // The card id is the target_id (opaque to the bridge, meaningful here) — a
        // JSON round-trip through the durable inbox keeps it a numeric string.
        $cardIdRaw = $target->targetId;
        if (! ctype_digit($cardIdRaw)) {
            Log::warning('kanban_block_reason: target_id is not a card id; ignoring', ['target_id' => $cardIdRaw]);

            return;
        }
        $cardId = (int) $cardIdRaw;

        $payload = $target->payload;
        $repo = $payload['repo'] ?? null;
        $action = $payload['action'] ?? null;
        if (! is_string($repo) || $repo === '' || ($action !== 'set' && $action !== 'clear')) {
            // Malformed payload = a deterministic classifier bug → permanent: log + no-op,
            // never a durable throw (which would 5xx-storm an identically-failing event).
            Log::warning('kanban_block_reason: payload.repo must be a non-empty string and payload.action must be set|clear; ignoring', ['card_id' => $cardId, 'payload' => $payload]);

            return;
        }

        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        if ($writeback === null) {
            Log::warning('kanban_block_reason: writeback is not configured (no writeback.json); ignoring', ['card_id' => $cardId, 'repo' => $repo]);

            return;
        }
        $mapping = $writeback->mappingFor($repo);
        if ($mapping === null || ! $mapping->draftOverlay) {
            // Unmapped or opt-out: permanent refusal — log + no-op (never 5xx-retry a config gap).
            Log::info('kanban_block_reason: repo not mapped or draft_overlay off; ignoring', ['card_id' => $cardId, 'repo' => $repo]);

            return;
        }

        $client = WritebackClientFactory::make();   // throws (→ 5xx) on a missing/insecure token or base url

        // A kanban 4xx (deleted card) is PERMANENT — log + no-op. Only a 5xx / timeout
        // / connection error is transient (throw → redelivery retries).
        try {
            $card = $client->getCard($cardId);
        } catch (RequestException $e) {
            if ($this->isPermanent($e)) {
                Log::warning('kanban_block_reason: getCard refused by kanban (4xx) — ignoring (see `body` for the reason kanban gave)', ['card_id' => $cardId] + RefusalContext::from($e));

                return;
            }
            throw $e;   // transient → 5xx → retry
        }

        if (($card['board_id'] ?? null) !== $mapping->boardId) {
            // SECURITY (belongs-to-mapped-board, DL-009): refuse to touch a card that
            // isn't on the operator-mapped board for this repo. Permanent — log + no-op.
            Log::warning('kanban_block_reason: REFUSED — card is not on the mapped board', [
                'card_id' => $cardId, 'repo' => $repo, 'card_board' => $card['board_id'] ?? null, 'mapped_board' => $mapping->boardId,
            ]);

            return;
        }

        $current = $card['block_reason'] ?? null;
        $current = is_string($current) ? $current : null;

        if ($action === 'set') {
            // add-if-missing: write the marker only into an empty/blank block_reason
            // (PinGuard's trim semantics — a whitespace-only value is not a human pin).
            // A human reason, or our marker already present, is left (idempotent no-op).
            if ($current !== null && trim($current) !== '') {
                Log::info('kanban_block_reason: set skipped — card already has a block_reason (add-if-missing)', ['card_id' => $cardId, 'repo' => $repo]);

                return;
            }
            $reason = self::MARKER;
        } else {
            // clear-if-ours: null block_reason only when it is EXACTLY our marker; a
            // human-set reason is preserved.
            if ($current !== self::MARKER) {
                Log::info('kanban_block_reason: clear skipped — block_reason is not the draft marker (clear-if-ours)', ['card_id' => $cardId, 'repo' => $repo]);

                return;
            }
            $reason = null;
        }

        try {
            $client->setBlockReason($cardId, $reason);
        } catch (RequestException $e) {
            if ($this->isPermanent($e)) {
                Log::warning('kanban_block_reason: setBlockReason refused by kanban (4xx) — ignoring (see `body` for the reason kanban gave)', ['card_id' => $cardId] + RefusalContext::from($e));

                return;
            }
            throw $e;   // transient → 5xx → retry (add-if-missing / clear-if-ours is idempotent)
        }
        Log::info('kanban_block_reason: '.$action, ['card_id' => $cardId, 'board' => $mapping->boardId, 'repo' => $repo]);
    }

    /** A 4xx is a permanent refusal (don't retry); 5xx/timeout/connection is transient. */
    private function isPermanent(RequestException $e): bool
    {
        $status = $e->response->status();

        return $status >= 400 && $status < 500;
    }
}
