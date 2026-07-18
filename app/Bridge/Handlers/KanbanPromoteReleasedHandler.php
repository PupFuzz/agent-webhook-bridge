<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\GitHubReadClient;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\PrOutcome;
use App\Bridge\Writeback\TrackedCardRef;
use App\Bridge\Writeback\TrackedRefKind;
use App\Bridge\Writeback\WritebackAlertNotifier;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Promote-on-release (DL-207 / card-4483) — the steady-state Shipped→Released transition
 * a card-first board otherwise never gets. Base=dev feature PRs move a card to Shipped
 * (`stages.merged`) but never fire a `merged_to_main` event of their own; their commits
 * reach `main` only when a later release PR (`release/vX → main`) merges. On THAT merge
 * (the `merged_to_main` outcome the classifier detects), this handler scans the mapped
 * board and promotes every Shipped card whose merged PR's commit is now reachable from
 * `main` to Released (`stages.merged_to_main`). It IS "the promote workflow" bridge:reconcile
 * defers this transition to (ReconcileCommand excludes merged_to_main from move-in).
 *
 * DURABLE (survives the DL-203 echo/signal strip, so an agent-merged release still promotes)
 * and IDEMPOTENT (a promoted card leaves the Shipped filter, so a redelivery — e.g. after a
 * mid-scan transient throw — re-scans and moves nothing already done; no double-move).
 *
 * Reachability, not a dev-only proxy: promote iff
 * `compareStatus(merge_commit_sha, RELEASE_BASE)` ∈ {ahead, identical} — a POSITIVE "is the
 * sha on main" test, gated on `merged === true` (an OPEN PR carries a non-null test-merge sha
 * on no branch). Correct for this fleet's merge-commit releases (a squash-to-dev sha becomes
 * reachable from main via the release merge-commit); a squash/rebase RELEASE merge rewrites
 * shas so no sha ever joins main and nothing promotes — a documented, unguardable precondition.
 *
 * Failure posture mirrors KanbanMoveCardHandler's transient/permanent split: a permanent gap
 * (no writeback config, no GitHub token, a 4xx per card) is a durable-alert + loud-log + no-op
 * (never a 5xx-storm of an unfixable event); a transient 5xx/timeout THROWS → redelivery
 * retries. Recovery from a permanent gap: fix it → the NEXT release event re-scans (a stranded
 * card is still at Shipped). There is no reconcile backstop for this transition, so the gaps
 * are made LOUD (durable alert + bridge:check warn), not a log grep.
 *
 * SECURITY (unchanged from KanbanMoveCardHandler): board + stages come exclusively from
 * operator `writeback.json`; the webhook only triggers a scan for a configured repo; moves are
 * forward-only Shipped→Released. Released is the operator's configured terminal stage.
 */
final class KanbanPromoteReleasedHandler implements DurableReaction, Handler
{
    /**
     * Per-request GitHub timeout — TIGHTER than reconcile's human-interactive 15s because
     * this runs inside a synchronous webhook, up to MAX_CANDIDATES × 2 reads deep; a slow
     * GitHub must not stack past the FPM request ceiling.
     */
    public const RUNTIME_GITHUB_TIMEOUT_SECONDS = 8;

    /**
     * Max candidates scanned per event (each costs getPull + compareStatus). Steady-state N
     * is tiny (a handful shipped between releases); the cap bounds a first-adoption backlog to
     * a safe synchronous cost. Overflow is not stranded: promoted cards leave the Shipped
     * filter, so successive release events drain a >cap backlog — the cap only defers, and
     * the overflow is alerted.
     */
    public const MAX_CANDIDATES = 40;

    private WritebackAlertNotifier $alerts;

    public function __construct(?WritebackAlertNotifier $alerts = null)
    {
        $this->alerts = $alerts ?? new WritebackAlertNotifier;
    }

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $repo = $target->payload['repo'] ?? null;
        if (! is_string($repo) || $repo === '') {
            Log::warning('kanban_promote_released: payload.repo is missing or not a string; ignoring', ['payload' => $target->payload]);

            return;
        }

        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        if ($writeback === null) {
            Log::warning('kanban_promote_released: writeback is not configured (no writeback.json); ignoring', ['repo' => $repo]);

            return;
        }
        $mapping = $writeback->mappingFor($repo);
        if ($mapping === null || ! $mapping->promoteOnRelease) {
            Log::info('kanban_promote_released: repo not configured for promote_on_release; ignoring', ['repo' => $repo]);

            return;
        }

        $shipped = $mapping->stageFor('merged');
        $released = $mapping->stageFor('merged_to_main');
        if ($shipped === null || $released === null) {
            // WritebackConfig::load fails closed on this; guard anyway (a payload reached us).
            Log::warning('kanban_promote_released: mapping is missing the Shipped and/or Released stage; ignoring', ['repo' => $repo]);

            return;
        }
        if ($shipped === $released) {
            Log::info('kanban_promote_released: Shipped and Released map to the same stage — nothing to promote', ['repo' => $repo]);

            return;
        }

        // The first RUNTIME GitHub-read dependency. Under FPM GH_TOKEN is absent and the
        // store helper is CLI-only (DL-184), so in practice a placed <secret_dir>/github/token
        // (or a providers.github.token_path) is required. Unresolved ⇒ permanent config gap:
        // durable alert + loud log + no-op (never 5xx-storm an unfixable event).
        $resolution = (new GitHubTokenResolver)->resolveFor($repo);
        if (! $resolution->ok()) {
            Log::warning('kanban_promote_released: no GitHub read token for repo — cannot verify commit reachability; skipping (place <secret_dir>/github/token, or set providers.github.token_path)', [
                'repo' => $repo, 'reason' => $resolution->problem,
            ]);
            $this->alerts->notify($repo, 'promote_on_release', null, 'promote_no_github_token');

            return;
        }
        $github = new GitHubReadClient((string) $resolution->token, self::RUNTIME_GITHUB_TIMEOUT_SECONDS);
        $kanban = WritebackClientFactory::make();   // throws (→ 5xx) on a missing/insecure writeback token

        $read = $kanban->readBoardCards($mapping->boardId);
        if ($read['truncated']) {
            // The board read hit the page ceiling (MAX_PAGES × SEARCH_LIMIT): cards beyond it
            // are invisible to this scan. Unlike the cap/token gaps, a truncation-stranded card
            // is NOT re-reached next release (its id-desc position only recedes). Proceed on the
            // partial view (recent Shipped candidates are on the early pages), but make the
            // incompleteness LOUD — this leg has no reconcile backstop.
            Log::warning('kanban_promote_released: board read hit the page ceiling — cards beyond it are invisible to this scan and will not be promoted (they do not self-heal on the next release)', [
                'repo' => $repo, 'board' => $mapping->boardId,
            ]);
            $this->alerts->notify($repo, 'promote_on_release', null, 'promote_board_truncated');
        }
        $isShared = $writeback->boardIsShared($mapping->boardId);
        $refs = new ExternalReferenceNormalizer;

        // Candidates: at the Shipped stage EXACTLY, tooling-managed (a PR reference), not
        // pinned (mirror the reconcile's human-hold skip), and — on a shared board —
        // attributable to THIS repo (a bare pr_number on a shared board is ambiguous, skipped
        // exactly as bridge:reconcile does). DL-only cards have no PR to read a merge_sha from
        // (the same PR-driven boundary the reconcile draws).
        $candidates = [];
        foreach ($read['cards'] as $card) {
            if (($card['workflow_stage_id'] ?? null) !== $shipped || PinGuard::isPinned($card)) {
                continue;
            }
            $cardId = is_numeric($card['id'] ?? null) ? (int) $card['id'] : null;
            $payload = is_array($card['payload'] ?? null) ? $card['payload'] : [];
            $prNumber = $this->prForRepo(TrackedCardRef::fromPayload($payload, $isShared, $refs), $repo, $refs);
            if ($cardId !== null && $prNumber !== null) {
                $candidates[$cardId] = $prNumber;   // keyed by card id (dedup; N:1 can't collide here)
            }
        }

        if (count($candidates) > self::MAX_CANDIDATES) {
            Log::warning('kanban_promote_released: Shipped candidate count exceeds the per-event cap — processing the cap; the remainder promote on the next release event', [
                'repo' => $repo, 'count' => count($candidates), 'cap' => self::MAX_CANDIDATES,
            ]);
            $this->alerts->notify($repo, 'promote_on_release', null, 'promote_candidate_cap');
            $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES, true);
        }

        $promoted = 0;
        foreach ($candidates as $cardId => $prNumber) {
            if ($this->promoteIfReleased($github, $kanban, $repo, $cardId, $prNumber, $released)) {
                $promoted++;
            }
        }
        Log::info('kanban_promote_released: scan complete', [
            'repo' => $repo, 'board' => $mapping->boardId, 'candidates' => count($candidates), 'promoted' => $promoted,
        ]);
    }

    /**
     * Read one candidate's PR, test its merge sha for reachability from main, and move the
     * card to Released when it is on main. Returns whether the card was promoted. A permanent
     * (4xx) GitHub/kanban error on this card is logged + skipped (return false); a transient
     * (5xx/timeout) error PROPAGATES so redelivery re-scans (idempotent — a promoted card
     * leaves the Shipped filter).
     */
    private function promoteIfReleased(GitHubReadClient $github, KanbanClient $kanban, string $repo, int $cardId, int $prNumber, int $released): bool
    {
        try {
            $pr = $github->getPull($repo, $prNumber);
        } catch (RequestException $e) {
            if (RefusalContext::isPermanent($e)) {
                Log::warning('kanban_promote_released: getPull refused (4xx) — skipping card (see `body`)', ['card_id' => $cardId, 'repo' => $repo, 'pr' => $prNumber] + RefusalContext::from($e));

                return false;
            }
            throw $e;   // transient → 5xx → redelivery re-scans
        }

        // An OPEN PR carries a non-null TEST-merge sha on no branch — gate on merged, not on
        // emptiness. A merged PR with no sha (rare) is likewise not verifiable → skip.
        if ($pr['merged'] !== true || $pr['merge_commit_sha'] === '') {
            return false;
        }

        try {
            $status = $github->compareStatus($repo, $pr['merge_commit_sha'], PrOutcome::RELEASE_BASE);
        } catch (RequestException $e) {
            if (RefusalContext::isPermanent($e)) {
                Log::warning('kanban_promote_released: compareStatus refused (4xx) — skipping card (see `body`)', ['card_id' => $cardId, 'repo' => $repo, 'pr' => $prNumber] + RefusalContext::from($e));

                return false;
            }
            throw $e;
        }

        // ahead/identical ⇒ main is ahead-of/equal-to the merge sha ⇒ the sha is an ancestor
        // of main ⇒ the card's work is on main ⇒ released. behind/diverged ⇒ not yet released.
        if ($status !== 'ahead' && $status !== 'identical') {
            return false;
        }

        try {
            $kanban->moveCard($cardId, $released);
        } catch (RequestException $e) {
            if (RefusalContext::isPermanent($e)) {
                Log::warning('kanban_promote_released: kanban refused the move (4xx) — skipping card (see `body`)', ['card_id' => $cardId, 'stage' => $released] + RefusalContext::from($e));

                return false;
            }
            throw $e;
        }
        Log::info('kanban_promote_released: promoted Shipped→Released', ['card_id' => $cardId, 'repo' => $repo, 'pr' => $prNumber, 'stage' => $released]);

        return true;
    }

    /**
     * The PR number to read for a candidate, or null when the card can't be attributed to
     * $repo. On a 1:1 board a bare pr_number is unambiguous (the board's sole repo IS $repo);
     * a pr_url is honored only when it canonicalizes to $repo (a shared-board other-repo card
     * is skipped — reading getPull($repo, itsNumber) would fetch the WRONG PR). Ambiguous /
     * dl-only / no-ref cards are not promotable here.
     */
    private function prForRepo(TrackedCardRef $ref, string $repo, ExternalReferenceNormalizer $refs): ?int
    {
        return match ($ref->kind) {
            TrackedRefKind::PrNumber => $ref->prNumber,
            TrackedRefKind::PrUrl => $ref->canonRepo === $refs->canonicalizeSource($repo) ? $ref->prNumber : null,
            default => null,
        };
    }
}
