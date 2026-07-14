<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Writeback\PrOutcome;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Support\Facades\Log;

/**
 * Correlation classifier for the GitHub-PR → card-move writeback (FR #2016 /
 * DL-021). Deterministic up to one kanban read: it turns a `pull_request` event
 * into a `kanban_move_card` target by (a) deriving the move OUTCOME from
 * GitHub-CONTROLLED fields (the action + `pull_request.merged` + `base.ref`),
 * never the PR title; (b) correlating the card by the `DL-NNN` token in the PR
 * title or head branch against the mapped board's `dl_number`, OR the native-id
 * `card#<task-id>` token (FR-7). Which board+stage the move targets is decided by
 * the durable handler from operator config — this classifier only supplies which
 * card + outcome.
 *
 * Token resolution is try-in-order-with-fallback (framework #112), keyed on the
 * OUTCOME of a token not its presence: a `DL-NNN` that resolves wins (a co-present
 * `card#` is logged as ignored); a `DL-NNN` that resolves to no card falls through
 * to a present `card#`; a token present but resolving to nothing is warned loudly
 * (a decision-logged-but-unstamped card — never a silent no-op). The card# fallback
 * stays board-scoped via the durable handler's existing board-membership guard.
 *
 * Emits NO intents (the writeback is machine-only, "no agent in the loop"). A PR
 * with no parseable card reference, or a repo with no `writeback.json` mapping →
 * empty result (graceful no-op).
 */
class GitHubPrCardMoveClassifier implements Classifier, EmitsWritebackReactions
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $eventType = $ctx->eventType;
        $payload = $ctx->payload;
        $provider = $ctx->provider;
        $scopeId = $ctx->scopeId;

        if ($provider !== 'github') {
            return new ClassifyResult;
        }

        // Branch-create push → `started` (DL-160). A `push` that CREATED a branch
        // whose ref carries a DL-NNN means work has begun on that card; promote it
        // to In Progress. Separate from the pull_request lifecycle below — the PR
        // path stays byte-identical.
        if ($eventType === 'push') {
            return $this->classifyPush($payload, $scopeId);
        }

        if (! str_starts_with($eventType, 'pull_request.')) {
            return new ClassifyResult;
        }

        $outcome = $this->outcome($eventType, $payload);
        // Draft → block_reason OVERLAY trigger (DL-193), orthogonal to the move
        // outcome: converted_to_draft / opened-as-draft → 'set'; ready_for_review →
        // 'clear'; anything else → null. The pure-overlay actions carry no move
        // outcome (converted_to_draft/ready_for_review), so both being null is the
        // "we act on this event neither way" no-op.
        $overlayAction = $this->draftOverlayAction($eventType, $payload);
        if ($outcome === null && $overlayAction === null) {
            return new ClassifyResult;   // an action we don't act on (edited, synchronize, …)
        }

        $repo = $scopeId;   // GitHubAdapter sets scope_id = repository.full_name
        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        $mapping = $writeback?->mappingFor($repo);
        if ($mapping === null) {
            return new ClassifyResult;   // repo not configured for writeback
        }

        // Dependabot PRs carry no DL and have no pre-existing card. When opted in
        // (per-mapping `create_dependabot_cards`), emit a create-or-move target
        // keyed by PR NUMBER — the durable handler creates the card on open and
        // moves it on close. Detected by dependabot's own branch namespace.
        // Gated on a real MOVE outcome so a null-outcome overlay action (which was
        // previously unreachable here — it early-returned above) can't fall in.
        if ($outcome !== null && $mapping->createDependabotCards && $this->isDependabot($payload)) {
            $prNumber = $this->prNumber($payload);
            if ($prNumber === null) {
                return new ClassifyResult;
            }
            $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];

            return new ClassifyResult(targets: [
                ReactionTarget::make('kanban_dependabot_card', "pr-{$prNumber}", payload: [
                    'repo' => $repo,
                    'outcome' => $outcome,
                    'pr_number' => $prNumber,
                    'pr_title' => is_string($pr['title'] ?? null) ? $pr['title'] : "Dependabot PR #{$prNumber}",
                    'pr_url' => is_string($pr['html_url'] ?? null) ? $pr['html_url'] : '',
                ]),
            ]);
        }

        // Draft-overlay targets (block_reason set/clear, DL-193) — opt-in per mapping,
        // reusing the SAME DL/card# correlation as the move path. Overlay ONLY: it adds
        // a `kanban_block_reason` target, never a stage move. When the mapping doesn't
        // opt in (or the action isn't a draft trigger) this is [] ⇒ every move return
        // below is byte-identical to today. On an opened-as-draft PR the overlay set
        // target is emitted IN ADDITION to the existing `opened` move target.
        $overlayTargets = ($overlayAction !== null && $mapping->draftOverlay)
            ? $this->blockReasonTargets($payload, $repo, $overlayAction, $mapping, $writeback)
            : [];

        // converted_to_draft / ready_for_review carry no move outcome → overlay only
        // (empty when not opted in, so byte-identical to the previous null-outcome no-op).
        if ($outcome === null) {
            return new ClassifyResult(targets: $overlayTargets);
        }

        // Won't-Do-revival (DL-195): a `reopened` action normally collapses to the `opened`
        // outcome (above). When the mapping opts in, emit a DISTINCT `reopened` MOVE outcome
        // so the durable handler applies the revival carve-out (revive a card parked in the
        // `closed_unmerged` abandon stage back to the `opened` stage — the backward move the
        // DL-163 guard otherwise refuses). Computed HERE, after the dependabot branch, so the
        // dependabot path — cards that ARCHIVE on close (DL-161), never park in
        // `closed_unmerged` — keeps `opened` and never enters revival; and after $mapping is
        // resolved (it isn't in scope in outcome()). Absent revive_on_reopen ⇒ $moveOutcome ===
        // $outcome ⇒ byte-identical. `reopened` is a handler-internal outcome with no config
        // stage of its own (it reuses `stages.opened`), so it is NOT in WritebackConfig::OUTCOMES.
        $moveOutcome = ($eventType === 'pull_request.reopened' && $mapping->reviveOnReopen)
            ? 'reopened'
            : $outcome;

        $dl = $this->dlToken($payload);
        $cardToken = $this->cardToken($payload);
        if ($dl === null && $cardToken === null) {
            return new ClassifyResult(targets: $overlayTargets);   // no card-first token in the PR → move no-op (overlay may also be empty)
        }

        // FR-7 try-in-order-with-fallback (framework #112): resolve on the OUTCOME
        // of a token, not its mere presence. (1) DL resolves → use it. (2) DL
        // unresolved → fall through to a present card#. (4) a token was present but
        // nothing resolved → a high-value miss, warn loudly. The board-scope guard
        // for the card# fallback lives in KanbanMoveCardHandler (it already gates
        // DL and card# moves identically), so the classifier stays classify-time-
        // read-free on that path.
        if ($dl !== null) {
            // Correlation read (deterministic key → card id(s)). A transient failure
            // here is a classify error (treatment A: recorded, ack 200, bridge:replay).
            // A DL/PR can track MULTIPLE cards (bundled PR — DL-148), so move them ALL:
            // one target per card, each with the card id as its distinct target_id so
            // they don't coalesce (DL-003).
            // Repo-qualified (DL-167) ONLY where ambiguity exists (DL-174): on a board
            // SHARED by several repo mappings, the event's repo is sent as kanban's
            // `source` dimension (DL-163) so a colliding DL number (DL-027) resolves to
            // THIS repo's card only. On a 1:1 board the strict `source` filter would
            // exclude cards whose derived refs carry no source (operator-stamped
            // dl_number cards) while protecting nothing — so it is omitted.
            $sourceRepo = $writeback->boardIsShared($mapping->boardId) ? $repo : null;
            $cardIds = WritebackClientFactory::make()->correlateDl($mapping->boardId, $dl, $sourceRepo);
            if ($cardIds !== []) {
                // (1)/(3) DL resolved → it wins. A present card# is ignored LOUDLY:
                // a PR naming two cards is almost always an operator error, and "the
                // DL card moved but my card# didn't" must be diagnosable from the ledger.
                if ($cardToken !== null) {
                    Log::info("kanban_move_card: PR carries both {$dl} and card#{$cardToken} — DL wins (FR-7 precedence); the card# token is ignored");
                }

                return new ClassifyResult(targets: array_merge($this->moveTargets($cardIds, $repo, $moveOutcome), $overlayTargets));
            }
            // (4) DL present but nothing resolved and no card# fallback → a
            // high-value miss (a decision-logged-but-unstamped card is the live
            // footgun this fallback exists for). Warn loudly; never silent no-op.
            if ($cardToken === null) {
                Log::warning("kanban_move_card: PR carries {$dl} but no card tracks it and no card# fallback token is present — no move (FR-7 high-value miss)");

                return new ClassifyResult(targets: $overlayTargets);
            }
            // (2) DL unresolved → fall through to the present card#.
            Log::info("kanban_move_card: {$dl} resolved to no card — falling through to card#{$cardToken} (FR-7 try-in-order)");
        }

        // card#<task-id> (FR-7): direct native-id selection — reached when DL is
        // absent, or present-but-unresolved with a card# fallback. Board+stage stay
        // operator config; the durable handler rejects a card not on the mapped
        // board and applies the same no-regression guards as a DL move. This is the
        // ONLY path that carries stamp refs (FR #3866): the card selected by native
        // id is the one that strands unstamped for release-promote correlation.
        return new ClassifyResult(targets: array_merge([
            ReactionTarget::make('kanban_move_card', (string) $cardToken, payload: array_merge([
                'card_id' => $cardToken,
                'repo' => $repo,
                'outcome' => $moveOutcome,
            ], $this->stampRefs($this->titleAndHead($payload), $this->prNumber($payload)))),
        ], $overlayTargets));
    }

    /**
     * Build one `kanban_move_card` target per resolved card id — each with the card
     * id as its distinct target_id so they don't coalesce (DL-003/DL-148).
     *
     * @param  list<int>  $cardIds
     * @return list<ReactionTarget>
     */
    private function moveTargets(array $cardIds, string $repo, string $outcome): array
    {
        $targets = [];
        foreach ($cardIds as $cardId) {
            $targets[] = ReactionTarget::make('kanban_move_card', (string) $cardId, payload: [
                'card_id' => $cardId,
                'repo' => $repo,
                'outcome' => $outcome,
            ]);
        }

        return $targets;
    }

    /**
     * The draft → block_reason overlay ACTION for a pull_request event (DL-193), or
     * null when the action is not a draft trigger. Overlay only — no stage move:
     *  - `converted_to_draft`                        → 'set'   (add-if-missing marker)
     *  - `opened` / `reopened` with `draft === true` → 'set'   (a PR born a draft;
     *      `converted_to_draft` never fires for it — GitHub sends `opened` with the
     *      draft flag). The existing `opened` move still fires; overlay only adds the set.
     *  - `ready_for_review`                          → 'clear' (clear-if-ours)
     *
     * @param  array<mixed>  $payload
     */
    private function draftOverlayAction(string $eventType, array $payload): ?string
    {
        $action = substr($eventType, strlen('pull_request.'));
        if ($action === 'converted_to_draft') {
            return 'set';
        }
        if ($action === 'ready_for_review') {
            return 'clear';
        }
        if ($action === 'opened' || $action === 'reopened') {
            $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];

            return ($pr['draft'] ?? null) === true ? 'set' : null;
        }

        return null;
    }

    /**
     * One `kanban_block_reason` overlay target per correlated card (DL-193) — the
     * card id is the distinct target_id so bundled-card targets don't coalesce
     * (DL-003/DL-148), and the payload carries just the repo (for the handler's
     * board resolution) and the set/clear action. No card correlates → no target
     * (empty list), exactly like the move path's un-linked no-op.
     *
     * @param  array<mixed>  $payload
     * @return list<ReactionTarget>
     */
    private function blockReasonTargets(array $payload, string $repo, string $action, WritebackMapping $mapping, WritebackConfig $writeback): array
    {
        $targets = [];
        foreach ($this->correlatedCardIds($payload, $repo, $mapping, $writeback) as $cardId) {
            $targets[] = ReactionTarget::make('kanban_block_reason', (string) $cardId, payload: [
                'repo' => $repo,
                'action' => $action,
            ]);
        }

        return $targets;
    }

    /**
     * The card ids a PR correlates to, for the draft overlay — the SAME DL→card /
     * card#-fallback resolution the move path uses (FR-7 try-in-order): a DL that
     * resolves wins (all matching cards, one-to-many DL-148); an unresolved DL falls
     * through to a present `card#`; neither → empty. Deliberately does NOT re-log the
     * move path's FR-7 diagnostics (precedence / high-value-miss / fallthrough) — on
     * an opened-as-draft PR the move path already emits them for the same tokens, so
     * repeating them here would double-log the identical event.
     *
     * @param  array<mixed>  $payload
     * @return list<int>
     */
    private function correlatedCardIds(array $payload, string $repo, WritebackMapping $mapping, WritebackConfig $writeback): array
    {
        $dl = $this->dlToken($payload);
        $cardToken = $this->cardToken($payload);
        if ($dl !== null) {
            $sourceRepo = $writeback->boardIsShared($mapping->boardId) ? $repo : null;
            $cardIds = WritebackClientFactory::make()->correlateDl($mapping->boardId, $dl, $sourceRepo);
            if ($cardIds !== []) {
                return $cardIds;
            }
        }

        return $cardToken !== null ? [$cardToken] : [];
    }

    /**
     * Branch-create push → `started` move target(s) (DL-160). Fires ONCE on the
     * creation of a branch (`payload.created === true`) whose ref carries a
     * `DL-NNN`, so it codifies "work has begun" from the artifact (the branch),
     * not from any agent. Uses `created === true` so a subsequent push to the same
     * branch is a no-op (the move would otherwise re-fire on every push). The
     * handler's promote-from guard (`started_from_stages`) makes a re-create /
     * force-push of an old branch a no-op too. Correlates DL→card exactly as the
     * PR path. No target when: not a created-branch push, a dependabot branch, no
     * DL in the ref, the repo is unmapped, or no card tracks the DL.
     *
     * @param  array<mixed>  $payload
     */
    private function classifyPush(array $payload, string $scopeId): ClassifyResult
    {
        if (($payload['created'] ?? null) !== true) {
            return new ClassifyResult;   // not a branch creation → no-op (don't re-fire on every push)
        }
        $ref = is_string($payload['ref'] ?? null) ? $payload['ref'] : '';
        if (! str_starts_with($ref, 'refs/heads/')) {
            return new ClassifyResult;   // a tag or other ref, not a branch
        }
        $branch = substr($ref, strlen('refs/heads/'));
        if (str_starts_with($branch, 'dependabot/')) {
            return new ClassifyResult;   // dependabot branches carry no DL and track no card
        }

        $repo = $scopeId;
        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        $mapping = $writeback?->mappingFor($repo);
        if ($mapping === null) {
            return new ClassifyResult;   // repo not configured for writeback
        }

        $hasDl = preg_match('/\bDL-(\d+)/i', $branch, $m) === 1;
        $cardToken = preg_match('/\bcard#(\d+)\b/i', $branch, $cm) === 1 ? (int) $cm[1] : null;
        if (! $hasDl && $cardToken === null) {
            return new ClassifyResult;   // no card-first token in the branch ref → un-linked, no-op
        }

        // FR-7 try-in-order-with-fallback (framework #112): same resolution order as
        // the pull_request path — resolve on the OUTCOME of the DL, not its presence.
        if ($hasDl) {
            $dl = 'DL-'.$m[1];
            // Repo-qualified (DL-167) only where ambiguity exists (DL-174) — same
            // shared-board conditional as the pull_request path above.
            $sourceRepo = $writeback->boardIsShared($mapping->boardId) ? $repo : null;
            $cardIds = WritebackClientFactory::make()->correlateDl($mapping->boardId, $dl, $sourceRepo);
            if ($cardIds !== []) {
                if ($cardToken !== null) {
                    Log::info("kanban_move_card: branch carries both {$dl} and card#{$cardToken} — DL wins (FR-7 precedence); the card# token is ignored");
                }

                return new ClassifyResult(targets: $this->moveTargets($cardIds, $repo, 'started'));
            }
            if ($cardToken === null) {
                Log::warning("kanban_move_card: branch carries {$dl} but no card tracks it and no card# fallback token is present — no move (FR-7 high-value miss)");

                return new ClassifyResult;
            }
            Log::info("kanban_move_card: {$dl} resolved to no card — falling through to card#{$cardToken} (FR-7 try-in-order)");
        }

        // card#<task-id> (FR-7): native-id selection — DL absent, or present-but-
        // unresolved with a card# fallback. Handler-guarded on board membership.
        // Carries stamp refs (FR #3866) off the branch ref (no PR on a push).
        return new ClassifyResult(targets: [
            ReactionTarget::make('kanban_move_card', (string) $cardToken, payload: array_merge([
                'card_id' => $cardToken,
                'repo' => $repo,
                'outcome' => 'started',
            ], $this->stampRefs($branch, null))),
        ]);
    }

    /**
     * Move outcome from GitHub-controlled fields only.
     *
     * @param  array<mixed>  $payload
     */
    private function outcome(string $eventType, array $payload): ?string
    {
        $action = substr($eventType, strlen('pull_request.'));
        if ($action === 'opened' || $action === 'reopened') {
            return 'opened';
        }
        if ($action !== 'closed') {
            return null;
        }
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        if (($pr['merged'] ?? false) !== true) {
            return 'closed_unmerged';
        }
        $base = is_array($pr['base'] ?? null) ? ($pr['base']['ref'] ?? '') : '';

        // The merged→stage decision lives in PrOutcome so the reconciler
        // (bridge:reconcile, DL-183) derives the identical outcome from REST state.
        return PrOutcome::forMergedBase(is_string($base) ? $base : '');
    }

    /**
     * The correlation surface — the PR title plus the head branch ref — where both
     * the `DL-NNN` and `card#<id>` tokens are matched (the shared primitive for
     * {@see dlToken}/{@see cardToken}/{@see stampRefs}).
     *
     * @param  array<mixed>  $payload
     */
    private function titleAndHead(array $payload): string
    {
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $title = is_string($pr['title'] ?? null) ? $pr['title'] : '';
        $head = is_array($pr['head'] ?? null) && is_string($pr['head']['ref'] ?? null) ? $pr['head']['ref'] : '';

        return $title.' '.$head;
    }

    /**
     * The `card#<task-id>` token (FR-7, framework v0.2.229) from the PR title or
     * head branch — the native-kanban-task-id correlation channel for cards that
     * carry no DL. Same surface + matching style as {@see dlToken}.
     *
     * @param  array<mixed>  $payload
     */
    private function cardToken(array $payload): ?int
    {
        if (preg_match('/\bcard#(\d+)\b/i', $this->titleAndHead($payload), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * The `DL-NNN` token from the PR title or head branch (the same convention
     * the board automation already uses), or null.
     *
     * @param  array<mixed>  $payload
     */
    private function dlToken(array $payload): ?string
    {
        if (preg_match('/\bDL-(\d+)/i', $this->titleAndHead($payload), $m) === 1) {
            return 'DL-'.$m[1];
        }

        return null;
    }

    /**
     * The correlation refs to STAMP onto a card selected by the `card#` fallback
     * (FR #3866) — the durable handler writes them add-if-missing. Only the card#
     * path stamps: a DL-resolved card already carries its `dl_number` (that is HOW
     * it resolved), so stamping it delivers nothing and — via a release PR whose
     * title carries a feature card's DL — could poison its `pr_number`. Includes
     * the DL token ONLY when EXACTLY ONE `DL-NNN` appears in $text: a bundled /
     * release-shaped PR carrying several DLs (or a single FOREIGN, unresolved DL
     * alongside a `card#` for a different card) must never stamp one. The PR number
     * is included when present (it is this card's PR — the card# selected it).
     *
     * @return array{stamp_dl?: string, stamp_pr?: int}
     */
    private function stampRefs(string $text, ?int $prNumber): array
    {
        $refs = [];
        if (preg_match_all('/\bDL-(\d+)/i', $text, $m) === 1) {
            $refs['stamp_dl'] = 'DL-'.$m[1][0];
        }
        if ($prNumber !== null) {
            $refs['stamp_pr'] = $prNumber;
        }

        return $refs;
    }

    /**
     * A dependabot PR, detected by its own branch namespace (`dependabot/*`).
     *
     * @param  array<mixed>  $payload
     */
    private function isDependabot(array $payload): bool
    {
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $head = is_array($pr['head'] ?? null) && is_string($pr['head']['ref'] ?? null) ? $pr['head']['ref'] : '';

        return str_starts_with($head, 'dependabot/');
    }

    /**
     * The PR number (the dependabot-card correlation key), or null.
     *
     * @param  array<mixed>  $payload
     */
    private function prNumber(array $payload): ?int
    {
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $n = $pr['number'] ?? null;

        return is_numeric($n) ? (int) $n : null;
    }
}
