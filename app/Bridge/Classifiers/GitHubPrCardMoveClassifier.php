<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Support\Facades\Log;

/**
 * Correlation classifier for the GitHub-PR → card-move writeback (FR #2016 /
 * DL-021). Deterministic up to one kanban read: it turns a `pull_request` event
 * into a `kanban_move_card` target by (a) deriving the move OUTCOME from
 * GitHub-CONTROLLED fields (the action + `pull_request.merged` + `base.ref`),
 * never the PR title; (b) correlating the card by the `DL-NNN` token in the PR
 * title or head branch against the mapped board's `dl_number`. Which board+stage
 * the move targets is decided by the durable handler from operator config — this
 * classifier only supplies which card + outcome.
 *
 * Emits NO intents (the writeback is machine-only, "no agent in the loop"). A PR
 * with no parseable card reference, a repo with no `writeback.json` mapping, or
 * no matching card → empty result (graceful no-op).
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
        if ($outcome === null) {
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
        if ($mapping->createDependabotCards && $this->isDependabot($payload)) {
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

        $dl = $this->dlToken($payload);
        $cardToken = $this->cardToken($payload);
        if ($dl === null && $cardToken === null) {
            return new ClassifyResult;   // no card-first token in the PR → un-linked, no-op
        }
        // FR-7 precedence (framework v0.2.229): DL-NNN wins — it is the ratified,
        // more-specific contract. The ignored card# is logged LOUDLY: a title
        // naming two cards is almost always an operator error, and "the DL card
        // moved but my card# didn't" must be diagnosable from the ledger.
        if ($dl !== null && $cardToken !== null) {
            Log::info("kanban_move_card: PR carries both {$dl} and card#{$cardToken} — DL wins (FR-7 precedence); the card# token is ignored");
        }
        if ($dl === null) {
            // card#<task-id> (FR-7): direct native-id selection — no source
            // qualifier, no classify-time kanban read. Board+stage stay operator
            // config; the durable handler rejects a card not on the mapped board
            // and applies the same no-regression guards as a DL move.
            return new ClassifyResult(targets: [
                ReactionTarget::make('kanban_move_card', (string) $cardToken, payload: [
                    'card_id' => $cardToken,
                    'repo' => $repo,
                    'outcome' => $outcome,
                ]),
            ]);
        }

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
        if ($cardIds === []) {
            return new ClassifyResult;   // no card tracks this PR → no-op
        }

        $targets = [];
        foreach ($cardIds as $cardId) {
            $targets[] = ReactionTarget::make('kanban_move_card', (string) $cardId, payload: [
                'card_id' => $cardId,
                'repo' => $repo,
                'outcome' => $outcome,
            ]);
        }

        return new ClassifyResult(targets: $targets);
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

        if (preg_match('/\bDL-(\d+)/i', $branch, $m) !== 1) {
            // card#<task-id> (FR-7): same surface + precedence as the PR path —
            // a DL in the ref wins; a card# alone selects by native id, handler-guarded.
            if (preg_match('/\bcard#(\d+)\b/i', $branch, $cm) === 1) {
                return new ClassifyResult(targets: [
                    ReactionTarget::make('kanban_move_card', $cm[1], payload: [
                        'card_id' => (int) $cm[1],
                        'repo' => $repo,
                        'outcome' => 'started',
                    ]),
                ]);
            }

            return new ClassifyResult;   // no card-first token in the branch ref → un-linked, no-op
        }
        $dl = 'DL-'.$m[1];

        // Repo-qualified (DL-167) only where ambiguity exists (DL-174) — same
        // shared-board conditional as the pull_request path above.
        $sourceRepo = $writeback->boardIsShared($mapping->boardId) ? $repo : null;
        $cardIds = WritebackClientFactory::make()->correlateDl($mapping->boardId, $dl, $sourceRepo);
        if ($cardIds === []) {
            return new ClassifyResult;   // no card tracks this DL → no-op
        }

        $targets = [];
        foreach ($cardIds as $cardId) {
            $targets[] = ReactionTarget::make('kanban_move_card', (string) $cardId, payload: [
                'card_id' => $cardId,
                'repo' => $repo,
                'outcome' => 'started',
            ]);
        }

        return new ClassifyResult(targets: $targets);
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

        return $base === 'main' ? 'merged_to_main' : 'merged';
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
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $title = is_string($pr['title'] ?? null) ? $pr['title'] : '';
        $head = is_array($pr['head'] ?? null) && is_string($pr['head']['ref'] ?? null) ? $pr['head']['ref'] : '';
        if (preg_match('/\bcard#(\d+)\b/i', $title.' '.$head, $m) === 1) {
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
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $title = is_string($pr['title'] ?? null) ? $pr['title'] : '';
        $head = is_array($pr['head'] ?? null) && is_string($pr['head']['ref'] ?? null) ? $pr['head']['ref'] : '';
        if (preg_match('/\bDL-(\d+)/i', $title.' '.$head, $m) === 1) {
            return 'DL-'.$m[1];
        }

        return null;
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
