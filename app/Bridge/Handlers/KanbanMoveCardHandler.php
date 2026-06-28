<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackAlertNotifier;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *
 * The `started` outcome (branch-create push, DL-160) carries its own no-regression
 * guard: it only promotes a card whose current stage is in the mapping's
 * `started_from_stages` (the board's Backlog/Prioritized stages).
 *
 * The four PR outcomes (opened / merged / merged_to_main / closed_unmerged) carry a
 * generalized no-regression guard (#2935, DL-163): a stale or redelivered
 * pull_request event — or a release PR whose title carries a card's DL-NNN — must
 * not drag a card backward (e.g. opened→In-Review on a card already Released). The
 * board's workflow-stage ORDER (positions, read from preload) is the authority;
 * `closed_unmerged` is the lone legitimately-backward outcome and is allowed to
 * regress UNLESS the card has reached a terminal (Shipped/Released) stage. Fail-open
 * when the order can't be read, so the guard never breaks the writeback.
 */
final class KanbanMoveCardHandler implements DurableReaction, Handler
{
    private WritebackAlertNotifier $alerts;

    public function __construct(?WritebackAlertNotifier $alerts = null)
    {
        $this->alerts = $alerts ?? new WritebackAlertNotifier;
    }

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
            $this->alerts->notify(is_string($repo) ? $repo : '', is_string($outcome) ? $outcome : '', null, 'card_id_not_int');

            return;
        }
        $cardId = (int) $cardId;
        if (! is_string($repo) || $repo === '' || ! is_string($outcome) || $outcome === '') {
            Log::warning('kanban_move_card: payload.repo and payload.outcome must be non-empty strings; ignoring', ['card_id' => $cardId]);
            $this->alerts->notify(is_string($repo) ? $repo : '', is_string($outcome) ? $outcome : '', $cardId, 'repo_or_outcome_invalid');

            return;
        }

        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        if ($writeback === null) {
            // No writeback.json — the move can't be configured; a target reached
            // us anyway. Permanent: log + no-op (don't 5xx-retry a config gap).
            Log::warning('kanban_move_card: writeback is not configured (no writeback.json); ignoring move', ['card_id' => $cardId, 'repo' => $repo]);
            // Degrades to log-only: with no writeback.json the notifier has no
            // alert_channel to load, so this branch is inherently quiet (documented
            // in docs/writeback.md). The call is kept for symmetry/correctness.
            $this->alerts->notify($repo, $outcome, $cardId, 'writeback_not_configured');

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
                $this->alerts->notify($repo, $outcome, $cardId, 'getcard_4xx');

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
            $this->alerts->notify($repo, $outcome, $cardId, 'card_not_on_mapped_board');

            return;
        }

        if (($card['workflow_stage_id'] ?? null) === $stageId) {
            return;   // idempotent: already in the target stage
        }

        // No-stage-regression guard for the branch-create `started` outcome
        // (DL-160). A `started` move must only PROMOTE a card from the board's
        // Backlog/Prioritized stages — never drag an already-progressed
        // (In-Review/Shipped/Released) card backward. Re-creating or force-pushing
        // an old branch re-fires the push event; this keeps that a no-op. The
        // allowed source stages are operator config (`started_from_stages`); with
        // none set we can't know what's safe to promote from, so we refuse
        // (fail-closed, mirroring the DL-026 "don't silently do the wrong thing").
        if ($outcome === 'started') {
            $allowed = $mapping->startedFromStages;
            $current = $card['workflow_stage_id'] ?? null;
            if ($allowed === null || $allowed === [] || ! in_array($current, $allowed, true)) {
                Log::info('kanban_move_card: started move skipped — card is not in an allowed promote-from stage (no regression)', [
                    'card_id' => $cardId, 'repo' => $repo, 'current_stage' => $current, 'started_from_stages' => $allowed,
                ]);

                return;
            }
        }

        // No-regression guard for the four PR-driven outcomes (#2935), generalizing
        // the DL-160 `started` guard above. A stale / redelivered pull_request event
        // — or a RELEASE PR whose title carries a card's DL-NNN — can re-fire an
        // outcome on a card that has already advanced past it, dragging it backward
        // (e.g. opened→In-Review on a card already Released). The board's workflow
        // stage ORDER (positions) is the authority. `closed_unmerged` is the lone
        // legitimately-backward outcome (In-Review→In-Progress when a PR is
        // abandoned), so it is allowed to regress UNLESS the card has already
        // reached a terminal (Shipped/Released) stage — a stale close must not
        // resurrect a shipped card. Fail-open: when the order can't be read (preload
        // down, or a stage not on the board) the move proceeds as it did pre-guard.
        if (in_array($outcome, ['opened', 'merged', 'merged_to_main', 'closed_unmerged'], true)) {
            $current = $card['workflow_stage_id'] ?? null;
            if (is_int($current) && $this->isRegressiveMove($outcome, $current, $stageId, $mapping, $client)) {
                Log::info('kanban_move_card: move skipped — would regress the card to an earlier stage (no regression)', [
                    'card_id' => $cardId, 'repo' => $repo, 'outcome' => $outcome, 'current_stage' => $current, 'target_stage' => $stageId,
                ]);

                return;
            }
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

    /**
     * Whether applying $outcome would regress the card to an earlier workflow stage
     * in a way the no-regression guard (#2935) refuses. Fail-open (false) whenever
     * the board order can't be determined — the guard never blocks a move on missing
     * order data, only on a definite backward step.
     */
    private function isRegressiveMove(string $outcome, int $currentStage, int $targetStage, WritebackMapping $mapping, KanbanClient $client): bool
    {
        try {
            $order = $client->boardStageOrder($mapping->boardId);
        } catch (Throwable $e) {
            Log::warning('kanban_move_card: could not read board stage order for the no-regression guard — allowing the move', [
                'board' => $mapping->boardId, 'error' => $e->getMessage(),
            ]);

            return false;   // fail-open: a diagnostic guard must not break the writeback
        }

        $currentPos = $order[$currentStage] ?? null;
        $targetPos = $order[$targetStage] ?? null;
        if ($currentPos === null || $targetPos === null) {
            return false;   // a stage isn't on the board (config drift) → can't order → allow
        }

        if ($outcome === 'closed_unmerged') {
            // Legitimately backward (In-Review → In-Progress). Refuse ONLY once the
            // card has reached a terminal (Shipped/Released) stage, so a stale close
            // can't resurrect a shipped/released card. No terminal stage configured
            // ⇒ no terminal concept on this board ⇒ allow the backward move.
            $terminalFloor = $this->terminalFloor($mapping, $order);

            return $terminalFloor !== null && $currentPos >= $terminalFloor;
        }

        // Forward outcomes (opened / merged / merged_to_main): refuse any move to a
        // stage earlier than the card's current one.
        return $targetPos < $currentPos;
    }

    /**
     * The earliest board position among the mapping's terminal ("done") targets —
     * the `merged` (Shipped) and `merged_to_main` (Released) stages. Null when the
     * mapping configures neither (no terminal concept on this board).
     *
     * @param  array<int, float>  $order
     */
    private function terminalFloor(WritebackMapping $mapping, array $order): ?float
    {
        $positions = [];
        foreach (['merged', 'merged_to_main'] as $terminalOutcome) {
            $stage = $mapping->stageFor($terminalOutcome);
            if ($stage !== null && isset($order[$stage])) {
                $positions[] = $order[$stage];
            }
        }

        return $positions === [] ? null : min($positions);
    }

    /** A 4xx is a permanent refusal (don't retry); 5xx/timeout/connection is transient. */
    private function isPermanent(RequestException $e): bool
    {
        $status = $e->response->status();

        return $status >= 400 && $status < 500;
    }
}
