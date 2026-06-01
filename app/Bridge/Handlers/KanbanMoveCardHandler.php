<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Move a kanban card to a workflow stage — the bridge's first WRITEBACK
 * (FR #2016 / DL-009/020). DURABLE: a failed move must not be silently dropped,
 * so it implements DurableReaction (runs first; its throw propagates → 5xx →
 * redelivery), and it is IDEMPOTENT (no-op when the card is already in the
 * target stage), as the marker contract requires.
 *
 * The classifier (correlation) supplies WHICH card + the repo + the
 * GitHub-controlled outcome in the payload; the BOARD + STAGE come exclusively
 * from operator config (`writeback.json`), keyed on the outcome — the webhook
 * body can't choose a board or stage (DL-009). Two failure modes, treated
 * differently:
 *  - TRANSIENT / operator-fixable (missing-or-insecure writeback token, a
 *    kanban API error) → THROW → 5xx → redelivery retries once it's fixed.
 *  - PERMANENT / refused (writeback off, no repo mapping, no stage for the
 *    outcome, or the card is NOT on the mapped board) → log + NO-OP. These can
 *    never succeed, so 5xx-retrying would storm; the dispatch acks (a refused
 *    move is not a delivery failure). The card-not-on-mapped-board case is the
 *    security guard (belongs-to-mapped-board) and is logged as a refusal.
 *
 * Payload: card_id (int), repo ("owner/repo"), outcome (one of
 * WritebackConfig::OUTCOMES). Any payload board_id/stage_id is IGNORED — the
 * config mapping is authoritative.
 */
final class KanbanMoveCardHandler implements DurableReaction, Handler
{
    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $payload = $target->payload;
        $cardId = $payload['card_id'] ?? null;
        $repo = $payload['repo'] ?? null;
        $outcome = $payload['outcome'] ?? null;
        // A malformed payload is a deterministic CLASSIFIER bug — permanent, so it
        // must NOT throw (a durable throw → 5xx → ~11-day retry-storm of an event
        // that fails identically every time). Log + no-op; the operator fixes the
        // classifier (the event couldn't be moved anyway — we don't know the card).
        if (! is_int($cardId) && ! (is_string($cardId) && ctype_digit($cardId))) {
            Log::warning('kanban_move_card: payload.card_id is not an integer; ignoring', ['payload' => $payload]);

            return;
        }
        $cardId = (int) $cardId;
        if (! is_string($repo) || $repo === '' || ! is_string($outcome) || $outcome === '') {
            Log::warning('kanban_move_card: payload.repo and payload.outcome must be non-empty strings; ignoring', ['card_id' => $cardId]);

            return;
        }

        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        if ($writeback === null) {
            // No writeback.json — the move can't be configured; a target reached
            // us anyway. Permanent: log + no-op (don't 5xx-retry a config gap).
            Log::warning('kanban_move_card: writeback is not configured (no writeback.json); ignoring move', ['card_id' => $cardId, 'repo' => $repo]);

            return;
        }

        $mapping = $writeback->mappingFor($repo);
        if ($mapping === null) {
            Log::info('kanban_move_card: no writeback mapping for repo; ignoring', ['repo' => $repo, 'card_id' => $cardId]);

            return;
        }
        $stageId = $mapping->stageFor($outcome);
        if ($stageId === null) {
            Log::info('kanban_move_card: no stage mapped for outcome; ignoring', ['repo' => $repo, 'outcome' => $outcome, 'card_id' => $cardId]);

            return;
        }

        $client = WritebackClientFactory::make();   // throws (→ 5xx) on a missing/insecure token or base url

        // A kanban 4xx (deleted card, a stage that doesn't exist on the card's
        // board) is PERMANENT — log + no-op, never 5xx-retry it. Only a 5xx /
        // timeout / connection error is transient (throw → redelivery retries).
        try {
            $card = $client->getCard($cardId);
        } catch (RequestException $e) {
            if ($this->isPermanent($e)) {
                Log::warning('kanban_move_card: getCard refused by kanban (4xx) — ignoring', ['card_id' => $cardId, 'status' => $e->response->status()]);

                return;
            }
            throw $e;   // transient → 5xx → retry
        }

        $boardId = $card['board_id'] ?? null;
        if ($boardId !== $mapping->boardId) {
            // SECURITY (belongs-to-mapped-board, DL-009): refuse to move a card
            // that isn't on the operator-mapped board for this repo. Permanent
            // refusal — log + no-op, never retry.
            Log::warning('kanban_move_card: REFUSED — card is not on the mapped board', [
                'card_id' => $cardId, 'repo' => $repo, 'card_board' => $boardId, 'mapped_board' => $mapping->boardId,
            ]);

            return;
        }

        if (($card['workflow_stage_id'] ?? null) === $stageId) {
            return;   // idempotent: already in the target stage
        }

        try {
            $client->moveCard($cardId, $stageId);
        } catch (RequestException $e) {
            if ($this->isPermanent($e)) {
                // e.g. the mapped stage isn't on the card's board (config typo):
                // permanent, log + no-op rather than 5xx-storm.
                Log::warning('kanban_move_card: moveCard refused by kanban (4xx) — check the writeback.json stage maps to a stage on the card\'s board', [
                    'card_id' => $cardId, 'stage' => $stageId, 'status' => $e->response->status(),
                ]);

                return;
            }
            throw $e;   // transient → 5xx → retry
        }
        Log::info('kanban_move_card: moved', ['card_id' => $cardId, 'board' => $mapping->boardId, 'stage' => $stageId, 'outcome' => $outcome]);
    }

    /** A 4xx is a permanent refusal (don't retry); 5xx/timeout/connection is transient. */
    private function isPermanent(RequestException $e): bool
    {
        $status = $e->response->status();

        return $status >= 400 && $status < 500;
    }
}
