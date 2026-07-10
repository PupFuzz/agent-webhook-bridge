<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Writeback\GitHubReadClient;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\PrOutcome;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Reconcile each tracked card's stage against GitHub PR ground truth — the
 * rerunnable backstop for the event-driven writeback (DL-183, closes RC-B from the
 * 2026-06-05 writeback-drift RCA). GitHub delivers each webhook ONCE with no
 * auto-retry, so a bridge outage during a PR event leaves the card silently
 * un-moved; nothing but the manual board-session-close catches it. This command
 * recomputes every tracked card's EXPECTED stage from the live PR state and, with
 * --fix, applies the forward moves.
 *
 * Default is REPORT-ONLY (exit 0 + a line per drifted card + summary counts).
 * Safety posture, all reusing the event-path guards rather than reinventing them:
 *  - never moves a card BACKWARD (DL-163 stage order) — backward drift is reported,
 *    not applied (it is almost always a deliberate human move);
 *  - never moves a PINNED card (DL-178 block_reason/no-automove);
 *  - treats the released_to_main / promote-owned stage as TERMINAL — never moves a
 *    card out of it, and never moves one INTO it (the promote workflow owns that
 *    transition, so release-promotion is excluded from scope);
 *  - a truncated board read ABORTS that board (never reconciles a partial view);
 *  - --max-moves caps a run: MORE planned moves than the cap aborts before applying
 *    ANY (mass movement means a bug, not drift);
 *  - a per-card GitHub 4xx/5xx warns + skips that card, never aborts the run.
 *
 * Only cards carrying a resolvable (repo, PR) are reconciled: a `pr_url` (yields
 * both) or a `pr_number` on a 1:1 board (the mapping supplies the repo). A
 * dl_number-only card is skipped with an info line — DL→PR resolution needs a
 * GitHub search, out of v1 scope.
 */
class ReconcileCommand extends BridgeCommand
{
    protected $signature = 'bridge:reconcile '
        .'{--fix : apply the forward moves (default is report-only)} '
        .'{--repo= : reconcile only this writeback.json mapping (owner/repo)} '
        .'{--max-moves=20 : abort before applying ANY move when more than this many are planned}';

    protected $description = 'Reconcile tracked-card stages against GitHub PR ground truth (report-only unless --fix)';

    /** Forward-drift moves to apply, collected across all boards before the cap check. */
    private array $planned = [];

    /** Backward / unorderable drift — reported, never applied. */
    private array $backward = [];

    private int $inSync = 0;

    private int $skipped = 0;

    /** Cards sitting in the promote-owned released stage (silently skipped — not noise). */
    private int $terminal = 0;

    private bool $hadError = false;

    /** Canonical owner/repo → whether the startup auth probe confirmed the token can read it. */
    private array $repoUsable = [];

    /** Canonical owner/repo → the per-repo read client (token resolved per repo, DL-185). */
    private array $clients = [];

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $maxMoves = $this->parseMaxMoves();
        if ($maxMoves === null) {
            return self::FAILURE;
        }

        $configDir = (string) config('bridge.config_dir');
        try {
            $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        } catch (ConfigException $e) {
            $this->error('writeback.json: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($writeback === null || $writeback->mappings === []) {
            $this->error('writeback is not configured (no writeback.json, or no mappings) — nothing to reconcile');

            return self::FAILURE;
        }

        $mappings = $writeback->mappings;
        $repoFilter = $this->strOption('repo');
        if ($repoFilter !== null) {
            if (! isset($mappings[$repoFilter])) {
                $this->error("--repo {$repoFilter} is not a writeback.json mapping (have: ".implode(', ', array_keys($mappings)).')');

                return self::FAILURE;
            }
            $mappings = [$repoFilter => $mappings[$repoFilter]];
        }

        try {
            $kanban = WritebackClientFactory::make();
        } catch (Throwable $e) {
            $this->error('kanban writeback client: '.$e->getMessage());

            return self::FAILURE;
        }
        $resolver = new GitHubTokenResolver;

        $this->info($fix ? 'bridge:reconcile --fix (applying forward moves)' : 'bridge:reconcile (report-only; pass --fix to apply)');

        $refs = new ExternalReferenceNormalizer;

        // Per-repo token resolution + startup auth/scope probe. The token is
        // resolved per repo (DL-185: the store map routes each repo to its own
        // least-privilege PAT), then the probe fails LOUDLY (non-zero exit) when a
        // token can't read its repo, rather than every per-card getPull silently
        // 404-ing while the run exits 0 (the wholesale-degradation trap). A per-repo
        // failure — an unresolvable token OR an unreadable repo — skips only that
        // repo's cards; other repos still run. The RAW mapping key is passed to the
        // resolver ([git-credential-map] is case-sensitive); repoUsable/clients are
        // keyed by the canonical form (how cards resolve their repo).
        foreach ($mappings as $repo => $mapping) {
            $canon = $refs->canonicalizeSource((string) $repo) ?? (string) $repo;

            $resolution = $resolver->resolveFor((string) $repo);
            if (! $resolution->ok()) {
                $this->error("github token for {$repo}: {$resolution->problem} — bridge:reconcile reads PR state from GitHub (the repo is private, so a read-only token is required); its cards will be SKIPPED. Place a token file (chmod 600), map the repo in the coordination store's [git-credential-map], or export GH_TOKEN.");
                $this->repoUsable[$canon] = false;
                $this->hadError = true;

                continue;
            }
            $client = new GitHubReadClient((string) $resolution->token);
            $this->clients[$canon] = $client;
            // Name the resolved leg (DL-186) so an auth failure points at WHICH
            // credential source won — the #1 diagnosability gap on a multi-leg
            // resolver (a stale <secret_dir>/github/token shadowing the store map is
            // the common upgrade footgun). Never prints the token, only the source.
            $from = " (token from {$resolution->source})";   // source is non-null after ok()

            try {
                $client->probeRepo($repo);
                $this->repoUsable[$canon] = true;
                if ($this->output->isVerbose()) {
                    $this->line("github: {$repo} — readable{$from}");
                }
            } catch (RequestException $e) {
                $status = $e->response->status();
                $hint = $status === 401 ? ' (token expired/revoked)' : ($status === 404 || $status === 403 ? ' (token lacks access to this private repo — needs `repo` scope)' : '');
                $this->error("github: cannot read repo {$repo} — HTTP {$status}{$hint}{$from}; its cards will be SKIPPED");
                $this->repoUsable[$canon] = false;
                $this->hadError = true;
            } catch (Throwable $e) {   // timeout / connection
                $this->error("github: cannot reach repo {$repo} — {$e->getMessage()}{$from}; its cards will be SKIPPED");
                $this->repoUsable[$canon] = false;
                $this->hadError = true;
            }
        }

        // Group the (filtered) mappings by board so each board is read + iterated
        // ONCE — a shared board would otherwise be walked per repo, double-counting
        // its bare-pr_number / dl-only cards.
        $byBoard = [];
        foreach ($mappings as $repo => $mapping) {
            $byBoard[$mapping->boardId][$repo] = $mapping;
        }

        foreach ($byBoard as $boardId => $boardMappings) {
            $this->reconcileBoard((int) $boardId, $boardMappings, $writeback, $kanban, $refs);
        }

        return $this->finish($fix, $maxMoves, $kanban);
    }

    /**
     * @param  array<string, WritebackMapping>  $boardMappings
     */
    private function reconcileBoard(int $boardId, array $boardMappings, WritebackConfig $writeback, KanbanClient $kanban, ExternalReferenceNormalizer $refs): void
    {
        $repoList = implode(', ', array_keys($boardMappings));
        try {
            $read = $kanban->readBoardCards($boardId);
        } catch (Throwable $e) {
            $this->error("board {$boardId} ({$repoList}): read failed — {$e->getMessage()}");
            $this->hadError = true;

            return;
        }
        if ($read['truncated']) {
            $this->error("board {$boardId} ({$repoList}): read hit the page ceiling — ABORTING this board (never reconcile a partial view). Cards beyond the ceiling were not read.");
            $this->hadError = true;

            return;
        }

        try {
            $order = $kanban->boardStageOrder($boardId);
        } catch (Throwable $e) {
            // Loud, not silent: a board-wide order outage (preload down) would else
            // masquerade as per-card stage drift. Cards then report as unorderable and
            // the run exits non-zero (set per-card in reconcileCard).
            $this->warn("board {$boardId}: could not read stage order ({$e->getMessage()}) — cards on it can't be direction-checked and won't be auto-moved");
            $order = [];
        }

        // A physically shared board (>1 repo mapped to it in the FULL config, even
        // if --repo filtered to one) can't attribute a bare pr_number to a repo.
        $isShared = $writeback->boardIsShared($boardId);
        // canonical owner/repo → mapping, for pr_url attribution on this board.
        $byCanonRepo = [];
        foreach ($boardMappings as $repo => $mapping) {
            $canon = $refs->canonicalizeSource((string) $repo);
            if ($canon !== null) {
                $byCanonRepo[$canon] = ['repo' => $repo, 'mapping' => $mapping];
            }
        }

        foreach ($read['cards'] as $card) {
            $this->reconcileCard(is_array($card) ? $card : [], $boardId, $boardMappings, $byCanonRepo, $isShared, $order, $refs);
        }
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, WritebackMapping>  $boardMappings
     * @param  array<string, array{repo: string, mapping: WritebackMapping}>  $byCanonRepo
     * @param  array<int, float>  $order
     */
    private function reconcileCard(array $card, int $boardId, array $boardMappings, array $byCanonRepo, bool $isShared, array $order, ExternalReferenceNormalizer $refs): void
    {
        $cardId = is_numeric($card['id'] ?? null) ? (int) $card['id'] : null;
        if ($cardId === null) {
            return;
        }
        $payload = is_array($card['payload'] ?? null) ? $card['payload'] : [];

        // Resolve the (repo, PR) this card tracks + which mapping owns it.
        [$repo, $mapping, $cardRepo, $prNumber, $prUrl] = $this->resolveTracked($card, $payload, $boardMappings, $byCanonRepo, $isShared, $refs);
        if ($mapping === null) {
            // resolveTracked already emitted the actionable info line (dl-only,
            // ambiguous, unmapped repo) or determined it is simply not a tracked card.
            return;
        }

        if (! ($this->repoUsable[$cardRepo] ?? false)) {
            // The startup probe already reported this repo as unreadable (loud +
            // non-zero exit); skip its cards silently rather than re-erroring per card.
            $this->skipped++;

            return;
        }

        $current = is_numeric($card['workflow_stage_id'] ?? null) ? (int) $card['workflow_stage_id'] : null;
        if ($current === null) {
            $this->line("card {$cardId}: no workflow_stage_id — skipped");
            $this->skipped++;

            return;
        }

        if (PinGuard::isPinned($card)) {
            $this->line("card {$cardId} ({$repo}): pinned (block_reason/no-automove) — skipped");
            $this->skipped++;

            return;
        }

        // The released_to_main / promote-owned stage is TERMINAL: never move a card
        // out of it (a released card stays released; the promote workflow owns it).
        $releasedStage = $mapping->stageFor('merged_to_main');
        if ($releasedStage !== null && $current === $releasedStage) {
            $this->terminal++;

            return;
        }

        try {
            // repoUsable[$cardRepo] === true above guarantees a probed client here.
            $pr = $this->clients[$cardRepo]->getPull($cardRepo, $prNumber);
        } catch (RequestException $e) {
            $status = $e->response->status();
            // The startup probe already confirmed the token can read this repo, so a
            // per-card 404 is a genuinely deleted PR (benign skip). A 401/403 here
            // means the token was revoked / scope-narrowed mid-run — systemic, so the
            // run must exit non-zero (never report a green reconcile over a dead token).
            if ($status === 401 || $status === 403) {
                $this->error("card {$cardId} ({$cardRepo}#{$prNumber}): GitHub {$status} — token revoked/insufficient mid-run; reconcile is degraded");
                $this->hadError = true;
            } else {
                $this->warn("card {$cardId} ({$cardRepo}#{$prNumber}): GitHub {$status} — skipped");
            }
            $this->skipped++;

            return;
        } catch (Throwable $e) {   // timeout / connection
            $this->warn("card {$cardId} ({$cardRepo}#{$prNumber}): GitHub read failed ({$e->getMessage()}) — skipped");
            $this->skipped++;

            return;
        }

        $outcome = $this->outcomeFor($pr);
        $expected = $mapping->stageFor($outcome);
        if ($expected === null) {
            $this->line("card {$cardId} ({$cardRepo}#{$prNumber}): PR outcome '{$outcome}' has no mapped stage — skipped");
            $this->skipped++;

            return;
        }
        // Excluded from scope: a merge-to-`main` PR's card belongs in the
        // promote-owned released stage — the promote workflow owns that transition,
        // so never reconcile INTO it. Keyed on the OUTCOME, not stage-id equality: an
        // operator may map both `merged` and `merged_to_main` to one column, and a
        // merged-to-dev card must still be allowed to advance to it.
        if ($outcome === 'merged_to_main') {
            $this->terminal++;

            return;
        }

        $evidence = $prUrl ?? ($pr['html_url'] !== '' ? $pr['html_url'] : "{$cardRepo}#{$prNumber}");

        if ($current === $expected) {
            $this->inSync++;

            return;
        }

        $curPos = $order[$current] ?? null;
        $expPos = $order[$expected] ?? null;
        if ($curPos === null || $expPos === null) {
            // Can't order (preload down, or a stage not on the board) → report the
            // drift but NEVER auto-move it (a batch mover must not guess direction).
            // A drifted card left unreconciled for lack of order data degrades the
            // run — exit non-zero so a cron notices rather than reading a false green.
            $this->backward[] = $this->driftRow($cardId, $boardId, $current, $expected, $outcome, $evidence, 'unorderable');
            $this->hadError = true;

            return;
        }
        if ($expPos < $curPos) {
            // Backward drift — report only. Usually a deliberate human move; the
            // reconciler never regresses a card (DL-163 posture).
            $this->backward[] = $this->driftRow($cardId, $boardId, $current, $expected, $outcome, $evidence, 'backward');

            return;
        }

        $this->planned[] = $this->driftRow($cardId, $boardId, $current, $expected, $outcome, $evidence, 'forward');
    }

    /**
     * Resolve the mapping + (repo, PR number, pr_url) a card tracks. Returns
     * [repo, mapping, cardRepo, prNumber, prUrl] with mapping null (and an info line
     * already emitted where actionable) when the card is not reconcilable.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $payload
     * @param  array<string, WritebackMapping>  $boardMappings
     * @param  array<string, array{repo: string, mapping: WritebackMapping}>  $byCanonRepo
     * @return array{0: ?string, 1: ?WritebackMapping, 2: string, 3: int, 4: ?string}
     */
    private function resolveTracked(array $card, array $payload, array $boardMappings, array $byCanonRepo, bool $isShared, ExternalReferenceNormalizer $refs): array
    {
        $none = [null, null, '', 0, null];
        $cardId = is_numeric($card['id'] ?? null) ? (int) $card['id'] : 0;

        // (1) pr_url — yields both repo + number. A placeholder ".../pull/0" (the
        // source-only qualifier stamped by `kbcard --pr-url`) is not a real PR:
        // fall through to pr_number / dl handling.
        $pu = $payload['pr_url'] ?? null;
        if (is_string($pu) && $pu !== '') {
            [$canon, $num] = $this->parsePrUrl($pu, $refs);
            if ($canon !== null && $num !== null && $num > 0) {
                $owner = $byCanonRepo[$canon] ?? null;
                if ($owner === null) {
                    $this->line("card {$cardId}: pr_url repo {$canon} is not in scope for this board (unmapped, or excluded by --repo) — skipped");
                    $this->skipped++;

                    return $none;
                }

                return [$owner['repo'], $owner['mapping'], $canon, $num, $pu];
            }
        }

        // (2) pr_number — needs the repo. Only unambiguous on a 1:1 board.
        $pn = $payload['pr_number'] ?? null;
        if (is_numeric($pn) && (int) $pn > 0) {
            if ($isShared) {
                $this->line("card {$cardId}: bare pr_number {$pn} on shared board — ambiguous repo (needs a repo-qualified pr_url); skipped");
                $this->skipped++;

                return $none;
            }
            // exactly one mapping on a 1:1 board
            $repo = array_key_first($boardMappings);
            $mapping = $boardMappings[$repo];
            $canon = $refs->canonicalizeSource((string) $repo) ?? (string) $repo;

            return [$repo, $mapping, $canon, (int) $pn, null];
        }

        // (3) dl_number only (no PR reference) — DL→PR resolution is out of v1 scope.
        $dl = $payload['dl_number'] ?? null;
        if (is_scalar($dl) && (string) $dl !== '') {
            $this->line("card {$cardId} (DL {$dl}): no PR reference (pr_url/pr_number) — DL→PR resolution is out of v1 scope; skipped");
            $this->skipped++;
        }

        // otherwise: not a tracked card (no pr/dl) — silent.
        return $none;
    }

    /**
     * The move outcome from a GitHub REST PR state — the SAME mapping the
     * event-driven classifier applies, sharing PrOutcome for the merged→stage
     * decision so the two paths can't diverge.
     *
     * @param  array{state: string, merged: bool, base_ref: string, html_url: string}  $pr
     */
    private function outcomeFor(array $pr): string
    {
        if ($pr['state'] !== 'closed') {
            return 'opened';   // an open PR (REST has no reopened) → the `opened` outcome
        }
        if (! $pr['merged']) {
            return 'closed_unmerged';
        }

        return PrOutcome::forMergedBase($pr['base_ref']);
    }

    /**
     * The canonical owner/repo + PR number from a github PR url, or [null, null].
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function parsePrUrl(string $url, ExternalReferenceNormalizer $refs): array
    {
        // Repo via the single vendored URL authority (kept in sync with kanban) so
        // this can't drift from the correlation path; the PR number is a trivial
        // /pull/<n> capture the normalizer doesn't expose.
        $repo = $refs->repoFromGitHubUrl($url);
        if ($repo === null || preg_match('#/pull/(\d+)#', $url, $m) !== 1) {
            return [null, null];
        }

        return [$repo, (int) $m[1]];
    }

    /**
     * @return array{card_id: int, board: int, current: int, expected: int, outcome: string, evidence: string, kind: string}
     */
    private function driftRow(int $cardId, int $board, int $current, int $expected, string $outcome, string $evidence, string $kind): array
    {
        return [
            'card_id' => $cardId,
            'board' => $board,
            'current' => $current,
            'expected' => $expected,
            'outcome' => $outcome,
            'evidence' => $evidence,
            'kind' => $kind,
        ];
    }

    /** Print the drift report, apply forward moves under --fix (cap-guarded), summarize. */
    private function finish(bool $fix, int $maxMoves, KanbanClient $kanban): int
    {
        foreach ($this->planned as $p) {
            $this->line(sprintf('DRIFT     card %d board %d: stage %d → %d (%s)  %s', $p['card_id'], $p['board'], $p['current'], $p['expected'], $p['outcome'], $p['evidence']));
        }
        foreach ($this->backward as $p) {
            if ($p['kind'] === 'unorderable') {
                $label = 'unorderable — not moved (board stage order unreadable)';
            } elseif ($p['outcome'] === 'closed_unmerged') {
                $label = 'backward — not moved (abandoned PR; v1 leaves the closed_unmerged regression to the event path / a human)';
            } else {
                $label = 'backward — not moved (card is ahead of its PR state; likely a deliberate human move)';
            }
            $this->line(sprintf('SKIP-DRIFT card %d board %d: stage %d ↛ %d (%s; %s)  %s', $p['card_id'], $p['board'], $p['current'], $p['expected'], $p['outcome'], $label, $p['evidence']));
        }

        $moved = 0;
        if ($fix && $this->planned !== []) {
            if (count($this->planned) > $maxMoves) {
                $this->error(sprintf('%d moves planned exceeds --max-moves=%d — ABORTING before applying ANY move (mass movement usually means a bug, not drift). Re-run with a higher --max-moves if this is genuinely expected.', count($this->planned), $maxMoves));

                return self::FAILURE;
            }
            foreach ($this->planned as $p) {
                try {
                    $kanban->moveCard($p['card_id'], $p['expected']);
                    $this->info(sprintf('MOVED     card %d → stage %d', $p['card_id'], $p['expected']));
                    $moved++;
                } catch (Throwable $e) {
                    $this->warn(sprintf('card %d: move failed (%s) — left as-is', $p['card_id'], $e->getMessage()));
                    $this->hadError = true;
                }
            }
        } elseif (! $fix && count($this->planned) > $maxMoves) {
            $this->warn(sprintf('%d forward moves would be applied — MORE than --max-moves=%d; a --fix run would ABORT until you raise the cap or the drift is explained.', count($this->planned), $maxMoves));
        }

        $this->newLine();
        $this->info(sprintf(
            'Summary: %d forward drift%s, %d backward/unorderable, %d in sync, %d skipped, %d terminal.',
            count($this->planned),
            $fix ? " ({$moved} moved)" : '',
            count($this->backward),
            $this->inSync,
            $this->skipped,
            $this->terminal,
        ));

        return $this->hadError ? self::FAILURE : self::SUCCESS;
    }

    private function parseMaxMoves(): ?int
    {
        $raw = (string) $this->option('max-moves');
        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
            $this->error("--max-moves must be a positive integer, got '{$raw}'");

            return null;
        }

        return (int) $raw;
    }
}
