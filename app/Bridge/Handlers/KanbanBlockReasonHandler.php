<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\CardTokenCorroboration;
use App\Bridge\Writeback\WritebackAlertNotifier;
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
 * DURABLE, with the same transient(5xx → retry) / permanent(4xx → alert + log + no-op)
 * split as the move handler (DL-020/DL-274), and the same belongs-to-mapped-board
 * security guard. Its non-4xx refusals (a non-card target_id, a malformed payload, no
 * writeback.json, the board guard) signal too since DL-285 — the board guard's twin in
 * the move handler always did, and the asymmetry was inside one guard.
 * Idempotent: a no-op SET/CLEAR (already-marker / not-ours) writes nothing.
 *
 * A SET additionally honors the optional `card_token_uncorroborated` flag + `pr_number`
 * the classifier attaches to a title-only `card#` residual, through the same
 * {@see CardTokenCorroboration} primitive the move handler gates on (card#5287 /
 * DL-270, extended here by card#5953). That refusal is a security refusal like the move
 * path's twin, so it signals rather than joining the log-only set.
 */
final class KanbanBlockReasonHandler implements DurableReaction, Handler
{
    /** The marker written by an add-if-missing SET; a CLEAR only nulls a block_reason equal to it. */
    public const MARKER = 'PR is in draft';

    /**
     * The synthetic `outcome` this handler's alerts carry. It has no PR outcome of its
     * own (it is an overlay, not a move), but the alert dedup tuple is
     * `(repo, outcome, reason)` — so a constant naming the reaction keeps this handler's
     * signals from colliding with the move handler's on a shared repo.
     */
    private const ALERT_OUTCOME = 'draft_overlay';

    private WritebackAlertNotifier $alerts;

    public function __construct(?WritebackAlertNotifier $alerts = null)
    {
        $this->alerts = $alerts ?? new WritebackAlertNotifier;
    }

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        // The card id is the target_id (opaque to the bridge, meaningful here) — a
        // JSON round-trip through the durable inbox keeps it a numeric string.
        $cardIdRaw = $target->targetId;
        if (! ctype_digit($cardIdRaw)) {
            // The card id is what is malformed, so the alert carries a null one; the repo
            // is read straight off the payload (unvalidated here — it is validated at the
            // next branch) so the signal still names a repo where it can.
            $repoRaw = $target->payload['repo'] ?? null;
            $this->alerts->warnAndNotify(
                'kanban_block_reason: target_id is not a card id; ignoring',
                ['target_id' => $cardIdRaw],
                is_string($repoRaw) ? $repoRaw : '', self::ALERT_OUTCOME, null, 'target_id_not_card_id',
            );

            return;
        }
        $cardId = (int) $cardIdRaw;

        $payload = $target->payload;
        $repo = $payload['repo'] ?? null;
        $action = $payload['action'] ?? null;
        if (! is_string($repo) || $repo === '' || ($action !== 'set' && $action !== 'clear')) {
            // Malformed payload = a deterministic classifier bug → permanent: alert + log
            // + no-op, never a durable throw (which would 5xx-storm an identically-failing event).
            $this->alerts->warnAndNotify(
                'kanban_block_reason: payload.repo must be a non-empty string and payload.action must be set|clear; ignoring',
                ['card_id' => $cardId, 'payload' => $payload],
                is_string($repo) ? $repo : '', self::ALERT_OUTCOME, $cardId, 'repo_or_action_invalid',
            );

            return;
        }

        $writeback = WritebackConfig::loadDefault();
        if ($writeback === null) {
            // Degrades to log-only (docs/writeback.md, *Branch-#3 degradation*); the call
            // is kept so this arm cannot drift out of the paired primitive.
            $this->alerts->warnAndNotify(
                'kanban_block_reason: writeback is not configured (no writeback.json); ignoring',
                ['card_id' => $cardId, 'repo' => $repo],
                $repo, self::ALERT_OUTCOME, $cardId, 'writeback_not_configured',
            );

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
            if (RefusalContext::isPermanent($e)) {
                $this->alerts->warnAndNotify(
                    'kanban_block_reason: getCard refused by kanban (4xx) — ignoring (see `body` for the reason kanban gave)',
                    ['card_id' => $cardId] + RefusalContext::from($e),
                    $repo, self::ALERT_OUTCOME, $cardId, RefusalContext::readReason('getcard', $e),
                );

                return;
            }
            throw $e;   // transient → 5xx → retry
        }

        if (($card['board_id'] ?? null) !== $mapping->boardId) {
            // SECURITY (belongs-to-mapped-board, DL-009): refuse to touch a card that
            // isn't on the operator-mapped board for this repo. Permanent — alert + log
            // + no-op. Same reason string as the move handler's twin, kept distinct in the
            // dedup tuple by the synthetic outcome (DL-274(3)).
            $this->alerts->warnAndNotify(
                'kanban_block_reason: REFUSED — card is not on the mapped board',
                ['card_id' => $cardId, 'repo' => $repo, 'card_board' => $card['board_id'] ?? null, 'mapped_board' => $mapping->boardId],
                $repo, self::ALERT_OUTCOME, $cardId, 'card_not_on_mapped_board',
            );

            return;
        }

        // An UNCORROBORATED title-only `card#` (card#5287 / DL-270, extended to this
        // overlay by card#5953). The classifier found the token in the PR TITLE with
        // nothing agreeing in the head branch, so the PR's prose is the sole claim that
        // this PR is work on this card — and on the SAME mapped board every guard above
        // passes, so a descriptive citation of another card lands a marker that PINS it
        // against the `started` auto-promote (DL-178). Same evidence, same primitive and
        // same refusal as the move handler.
        //
        // Scoped to SET, and that is a ruling rather than an omission: CLEAR is
        // clear-if-ours, so it can only null a block_reason that EXACTLY equals our
        // marker — a human's differing text is untouchable (bounded by the constant-
        // sentinel ambiguity noted in docs/writeback.md). Gating it would instead
        // STRAND any marker on a card that now tracks a different PR — including those
        // set before this shipped — leaving the guard permanently pinning the card it
        // exists to protect. Accepted residual: a foreign PR's ready_for_review can
        // clear the marker (and release the DL-178 pin) that another PR's draft set.
        if ($action === 'set' && CardTokenCorroboration::refuses($payload['card_token_uncorroborated'] ?? null, $card, $payload['pr_number'] ?? null)) {
            $this->alerts->warnAndNotify(
                'kanban_block_reason: REFUSED — the card# token appears only in the PR title, with no corroborating token in the head branch, and the card already tracks a DIFFERENT PR',
                [
                    'card_id' => $cardId, 'repo' => $repo,
                    'card_pr_number' => CardTokenCorroboration::cardPr($card),
                    'event_pr_number' => $payload['pr_number'] ?? null,
                ],
                $repo, self::ALERT_OUTCOME, $cardId, 'card_token_uncorroborated',
            );

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
            if (RefusalContext::isPermanent($e)) {
                $this->alerts->warnAndNotify(
                    'kanban_block_reason: setBlockReason refused by kanban (4xx) — ignoring (see `body` for the reason kanban gave)',
                    ['card_id' => $cardId] + RefusalContext::from($e),
                    $repo, self::ALERT_OUTCOME, $cardId, RefusalContext::writeReason('blockreason', $e),
                );

                return;
            }
            throw $e;   // transient → 5xx → retry (add-if-missing / clear-if-ours is idempotent)
        }
        Log::info('kanban_block_reason: '.$action, ['card_id' => $cardId, 'board' => $mapping->boardId, 'repo' => $repo]);
    }
}
