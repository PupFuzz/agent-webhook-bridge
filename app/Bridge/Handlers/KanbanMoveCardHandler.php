<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\PinGuard;
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
 * WritebackConfig::OUTCOMES, PLUS the handler-internal `reopened` — DL-195 — which
 * has no config stage of its own and reuses the `opened` stage). Any payload
 * board_id/stage_id is IGNORED — the config mapping is authoritative.
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
 * `closed_unmerged` is the lone legitimately-backward outcome AMONG THE FOUR PR
 * outcomes and is allowed to regress UNLESS the card has reached a terminal
 * (Shipped/Released) stage. Fail-open when the order can't be read, so the guard
 * never breaks the writeback. (The opt-in `reopened` outcome below is a fifth,
 * handler-internal, deliberately-backward move — scoped to the abandon stage.)
 *
 * The `reopened` outcome (opt-in `revive_on_reopen`, DL-195) is the writeback's other
 * legitimately-backward move: a reopened PR revives its card from the mapped
 * `closed_unmerged` (abandon) stage back to the `opened` stage. The guard allows that
 * backward move ONLY from the abandon stage (terminal-safe — a Shipped/Released card
 * is never there); elsewhere `reopened` is forward-only like `opened`. A marker-gated
 * override alert (notifyRevive) fires after the move.
 */
final class KanbanMoveCardHandler implements DurableReaction, Handler
{
    private WritebackAlertNotifier $alerts;

    /**
     * Per-instance memo of a board's stage order (the no-regression guard's
     * preload read), keyed by boardId. A bundled PR/DL correlating to N cards
     * emits N `kanban_move_card` targets that all resolve through THIS singleton
     * handler (HandlerRegistry holds one instance) in one request — and, being on
     * the same mapped board, would each re-fetch the identical `/preload.json`.
     * Memoizing collapses that to one read per board. Request-scoped: the bridge
     * runs synchronously per PHP-FPM request, so the singleton — and this memo —
     * lives exactly one request, within which the board's stage order is stable.
     * (This is the handler's only mutable request-state; a future Octane migration
     * — which persists singletons across requests — would need to reset it between
     * requests to avoid serving a stale order.)
     *
     * @var array<int, array<int, float>>
     */
    private array $stageOrderMemo = [];

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

        $writeback = WritebackConfig::loadDefault();
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
        // Won't-Do-revival (DL-195): the `reopened` move outcome has NO config stage of its
        // own — it reuses the `opened` (In-Review) stage. Resolve it explicitly so
        // stageFor('reopened')===null doesn't no-op the revival at the guard below.
        $stageOutcome = $outcome === 'reopened' ? 'opened' : $outcome;
        $stageId = $mapping->stageFor($stageOutcome);
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
            if (RefusalContext::isPermanent($e)) {
                Log::warning('kanban_move_card: getCard refused by kanban (4xx) — ignoring (see `body` for the reason kanban gave)', ['card_id' => $cardId] + RefusalContext::from($e));
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
            // Self-heal: the move is a no-op (already here), but a card# fallback card
            // may still be missing its correlation refs — stamp add-if-missing (#3866).
            $this->stampCorrelationRefs($card, $payload, $cardId, $client);

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
        $isUnpark = false;
        if ($outcome === 'started') {
            $current = $card['workflow_stage_id'] ?? null;
            $unparkStages = $mapping->unparkFromStages ?? [];
            $isUnpark = in_array($current, $unparkStages, true);

            // Pinned-card opt-out (cross-mover contract, agent-board-framework
            // PR #113 / DL-178): never auto-move a card a human has parked — a
            // non-empty block_reason OR a `no-automove` tag. REVERSED for the opt-in
            // `unpark_from_stages` (DL-194): from an unpark stage a branch-cut is an
            // unambiguous "work has begun" override, so a pinned card IS promoted
            // (and a compensating alert is emitted after the move). Everywhere else
            // DL-178 is byte-identical. Loud so a refused promotion stays visible.
            if (PinGuard::isPinned($card) && ! $isUnpark) {
                Log::warning('kanban_move_card: started move refused — card is pinned (block_reason/no-automove)', [
                    'card_id' => $cardId, 'repo' => $repo, 'current_stage' => $current,
                ]);
                $this->alerts->notify($repo, $outcome, $cardId, 'pinned_no_automove');

                return;
            }

            // The promote-from set is the union of the (refuse-if-pinned) started
            // stages and the (move-if-pinned) unpark stages — both null-coalesced to
            // []. Both absent ⇒ [] ⇒ refuse (DL-160 fail-closed preserved).
            $allowed = array_merge($mapping->startedFromStages ?? [], $unparkStages);
            if ($allowed === [] || ! in_array($current, $allowed, true)) {
                Log::info('kanban_move_card: started move skipped — card is not in an allowed promote-from stage (no regression)', [
                    'card_id' => $cardId, 'repo' => $repo, 'current_stage' => $current,
                    'started_from_stages' => $mapping->startedFromStages, 'unpark_from_stages' => $mapping->unparkFromStages,
                ]);

                return;
            }
        }

        // Won't-Do-revival (DL-195): a `reopened` PR whose card CURRENTLY sits in the
        // mapped `closed_unmerged` (abandon) stage is revived to the `opened` (In-Review)
        // stage — the backward move the DL-163 guard below otherwise refuses. Detected
        // here so the post-move alert can fire; the guard carve-out is in isRegressiveMove.
        // Terminal-safe by construction: a Shipped/Released card is never in the abandon
        // stage (and GitHub can't reopen a merged PR). `reopened` reaches the handler only
        // when the mapping opted in (the classifier emits `opened` otherwise), so revive-off
        // is byte-identical. Anywhere else, a `reopened` move behaves exactly like `opened`.
        $isRevive = false;
        if ($outcome === 'reopened') {
            $current = $card['workflow_stage_id'] ?? null;
            $abandon = $mapping->stageFor('closed_unmerged');
            $isRevive = is_int($current) && $abandon !== null && $current === $abandon;
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
        // resurrect a shipped card. `reopened` (DL-195) allows the backward move ONLY
        // from the abandon stage (the revival), else is forward-only like `opened`.
        // Fail-open: when the order can't be read (preload down, or a stage not on the
        // board) the move proceeds as it did pre-guard.
        if (in_array($outcome, ['opened', 'merged', 'merged_to_main', 'closed_unmerged', 'reopened'], true)) {
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
            if (RefusalContext::isPermanent($e)) {
                // A 4xx is a PERMANENT refusal (authz, a stage not on the board, a
                // deleted card, …): log + no-op rather than 5xx-storm. Hand over what
                // the server actually said (`body`) instead of guessing the cause —
                // status alone can't tell a 403 authz refusal from a config typo.
                Log::warning('kanban_move_card: kanban refused the move (4xx) — see `body` for the reason kanban gave', [
                    'card_id' => $cardId, 'board' => $mapping->boardId, 'stage' => $stageId,
                ] + RefusalContext::from($e));

                return;
            }
            throw $e;   // transient → 5xx → retry
        }
        // Auto-unpark alert (DL-194): after a CONFIRMED move from an unpark stage, and
        // BEFORE the stamp (which may 5xx-throw), emit the compensating "we overrode a
        // human hold" signal — durable Log::warning first (the record; mirrors every
        // other alert caller), then the additive live wake (notifyUnpark can't throw).
        // Placed here so a first delivery that later 5xx-throws on the stamp still
        // alerted once, and a partial-failure redelivery short-circuits at the
        // already-in-stage guard above (before this line) rather than re-alerting.
        if ($outcome === 'started' && $isUnpark) {
            $signal = $this->shouldAlertUnpark($card, $mapping);
            if ($signal !== null) {
                $fromStage = $card['workflow_stage_id'] ?? null;
                Log::warning('kanban_move_card: auto-unparked a card from a parked stage', [
                    'card_id' => $cardId, 'repo' => $repo, 'from_stage' => $fromStage, 'hold_signal' => $signal,
                ]);
                $this->alerts->notifyUnpark($repo, $cardId, is_int($fromStage) ? $fromStage : null);
            }
        }
        // Won't-Do-revival alert (DL-195): after a CONFIRMED revival move from the abandon
        // stage, emit the compensating "we revived a parked card on reopen" signal — same
        // shouldAlertUnpark override-gate (a genuinely-held card alerts; a bare-parked card
        // alerts via the fail-safe unless hold_marker_tags quiets it), same placement between
        // move and stamp, same no-dedup redelivery bound (a redelivered reopen short-circuits
        // at the already-in-stage guard above before reaching here, so no double-alert).
        if ($outcome === 'reopened' && $isRevive) {
            $signal = $this->shouldAlertUnpark($card, $mapping);
            if ($signal !== null) {
                $fromStage = $card['workflow_stage_id'] ?? null;
                Log::warning('kanban_move_card: revived a card from the abandon stage on PR reopen', [
                    'card_id' => $cardId, 'repo' => $repo, 'from_stage' => $fromStage, 'hold_signal' => $signal,
                ]);
                $this->alerts->notifyRevive($repo, $cardId, is_int($fromStage) ? $fromStage : null);
            }
        }

        // The card is now legitimately at its target stage (it passed every reject-guard
        // above) — stamp its correlation refs add-if-missing (#3866). Done AFTER the move
        // so a stale/redelivered/regressive event, which the guards no-op, never stamps.
        $this->stampCorrelationRefs($card, $payload, $cardId, $client);
        Log::info('kanban_move_card: moved', ['card_id' => $cardId, 'board' => $mapping->boardId, 'stage' => $stageId, 'outcome' => $outcome]);
    }

    /**
     * Stamp the card's correlation refs (`dl_number` / `pr_number` / `pr_url`) add-if-missing
     * (#3866 / card#4852), so a card the writeback moved becomes visible to release-promote's
     * `dl_number`/`pr_number` correlation and kanban's by-ref `source` derivation (`pr_url`)
     * instead of stranding in Shipped-to-Dev. The `card#` fallback path supplies `stamp_dl`
     * plus the PR refs; the DL-win path supplies the PR refs only (`stamp_pr`/`stamp_pr_url`) —
     * a DL-resolved card already carries its `dl_number` (that is HOW it resolved), so it is
     * never re-stamped. NEVER overwrites an existing value: the card's already-read payload
     * is the authority for "missing" (no extra read). Called only once the card is
     * legitimately at/entering its target stage (a self-heal no-op or a guard-passed move),
     * never from a reject-guarded event.
     *
     * Best-effort with the move's transient/permanent split: a 4xx (e.g. the board has no
     * `dl_number`/`pr_number` custom field) is PERMANENT → log + no-op (never 5xx-storm an
     * unfixable stamp). A 5xx/timeout PROPAGATES → redelivery re-stamps — safe because the
     * stamp is add-if-missing-idempotent and the move is idempotent, and it closes the
     * window where a swallowed transient failure would strand the card unstamped forever.
     *
     * @param  array<string, mixed>  $card  the card as already read by getCard()
     * @param  array<string, mixed>  $payload  the target payload (may carry stamp_dl/stamp_pr/stamp_pr_url)
     */
    private function stampCorrelationRefs(array $card, array $payload, int $cardId, KanbanClient $client): void
    {
        $current = is_array($card['payload'] ?? null) ? $card['payload'] : [];
        $refs = [];

        $stampDl = $payload['stamp_dl'] ?? null;
        if (is_string($stampDl) && $stampDl !== '' && ($current['dl_number'] ?? '') === '') {
            // Canonical zero-padded form every kbcard-written card uses (DL-%04d), so the
            // card is cosmetically consistent; correlation readers (release-promote, the
            // by-ref index) strip non-digits, so the width itself carries no meaning.
            $refs['dl_number'] = sprintf('DL-%04d', (int) preg_replace('/\D/', '', $stampDl));
        }

        // A JSON round-trip through the durable inbox can stringify the number, so accept
        // a numeric string too (mirrors the card_id coercion above).
        $stampPr = $payload['stamp_pr'] ?? null;
        if (is_numeric($stampPr) && ($current['pr_number'] ?? '') === '') {
            $refs['pr_number'] = (int) $stampPr;
        }

        // pr_url (card#4852) — a registered payload key that drives kanban's multi-repo
        // by-ref `source` derivation. Add-if-missing, exactly like pr_number above.
        $stampPrUrl = $payload['stamp_pr_url'] ?? null;
        if (is_string($stampPrUrl) && $stampPrUrl !== '' && ($current['pr_url'] ?? '') === '') {
            $refs['pr_url'] = $stampPrUrl;
        }

        if ($refs === []) {
            return;
        }

        try {
            $client->stampCorrelationRefs($cardId, $refs);
            Log::info('kanban_move_card: stamped correlation refs', ['card_id' => $cardId, 'refs' => array_keys($refs)]);
        } catch (RequestException $e) {
            if (RefusalContext::isPermanent($e)) {
                Log::warning('kanban_move_card: stamp refused by kanban (4xx) — skipping (see `body` for the reason kanban gave)', ['card_id' => $cardId] + RefusalContext::from($e));

                return;
            }
            throw $e;   // transient → 5xx → redelivery re-stamps (add-if-missing idempotent)
        }
    }

    /**
     * The hold-signal discriminator for an auto-unpark (DL-194), or null when the
     * unpark should stay quiet. The alert FLOOR is "we are actually overriding a
     * deliberate hold": a `no-automove` tag, a human `block_reason` (≠ the benign
     * draft sentinel), or an install's configured hold tag ALWAYS alert regardless of
     * `hold_marker_tags`; a benign automated draft-park (block_reason == the sentinel,
     * no other signal) never alerts. With NO `hold_marker_tags` declared the fail-safe
     * alerts on every non-benign unpark (a spurious dismissable alert beats a missed
     * one on a real gate); declaring `hold_marker_tags` only quiets bare-park noise,
     * never the pinned/held cases. Field reads go through PinGuard's boundary-safe
     * accessors (the card is a system boundary; block_reason may be non-string, tags
     * non-array). The returned string BOTH gates the alert AND labels the durable log
     * — one computation, no second derivation.
     *
     * @param  array<string, mixed>  $card
     */
    private function shouldAlertUnpark(array $card, WritebackMapping $mapping): ?string
    {
        $draftMarker = $mapping->draftBlockReason ?? KanbanBlockReasonHandler::MARKER;
        $blockReason = trim(PinGuard::blockReason($card) ?? '');
        $tags = PinGuard::tags($card);

        $hasNoAutomove = in_array('no-automove', $tags, true);
        $hasHumanBR = $blockReason !== '' && $blockReason !== $draftMarker;
        $hasHoldTag = false;
        foreach ($mapping->holdMarkerTags as $tag) {
            if (in_array($tag, $tags, true)) {
                $hasHoldTag = true;
                break;
            }
        }
        $isBenignDraft = $blockReason === $draftMarker && ! $hasNoAutomove && ! $hasHoldTag;

        if ($hasNoAutomove) {
            return 'no_automove';
        }
        if ($hasHumanBR) {
            return 'block_reason';
        }
        if ($hasHoldTag) {
            return 'hold_tag';
        }
        if ($mapping->holdMarkerTags === [] && ! $isBenignDraft) {
            return 'failsafe';
        }

        return null;
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
            // ??= caches only a successful read: a throw leaves the key unset so a
            // later card retries (preserving the per-card fail-open below).
            $order = $this->stageOrderMemo[$mapping->boardId] ??= $client->boardStageOrder($mapping->boardId);
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

        if ($outcome === 'reopened') {
            // Won't-Do-revival (DL-195): ALLOW the otherwise-refused backward move ONLY
            // from the mapped `closed_unmerged` (abandon) stage — the revival. Anywhere
            // else a `reopened` is forward-only, identical to `opened` (so a reopen of a
            // still-in-progress card, or a stale reopen on a terminal card, can't drag it
            // back). `closed_unmerged` unmapped ⇒ no abandon stage ⇒ falls through to
            // forward-only (revival can't apply without a parked-from stage).
            $abandon = $mapping->stageFor('closed_unmerged');
            if ($abandon !== null && $currentStage === $abandon) {
                return false;   // revival: the backward Won't-Do → In-Review move is allowed
            }

            return $targetPos < $currentPos;
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
}
