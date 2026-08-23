<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\ClosureGrammar;
use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Writeback\GitHubRepoProbe;
use App\Bridge\Writeback\GitHubRepoProbeKind;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\MappedBoardGuard;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\PrOutcome;
use App\Bridge\Writeback\TrackedCardRef;
use App\Bridge\Writeback\TrackedRefKind;
use App\Bridge\Writeback\WritebackAlertNotifier;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
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
 *  - never moves a card that is NOT on the mapped board (DL-009 belongs-to-mapped-board,
 *    reached from here at DL-301): the board read is a server-side search, so every row
 *    is re-checked client-side through `MappedBoardGuard::refuses()` before anything
 *    downstream touches it — see the gate in reconcileCard() for why it sits there;
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

    /**
     * The synthetic `outcome` this leg's refusals are deduped by, third element of the
     * `(repo, outcome, reason)` tuple. A CLI run has no PR outcome of its own to key on,
     * and it is what keeps this arm's `card_not_on_mapped_board` marker from sharing one
     * with the event-path arms (DL-274(3)).
     */
    private const ALERT_OUTCOME = 'reconcile';

    private WritebackAlertNotifier $alerts;

    public function __construct(?WritebackAlertNotifier $alerts = null)
    {
        parent::__construct();
        $this->alerts = $alerts ?? new WritebackAlertNotifier;
    }

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $maxMoves = $this->parseMaxMoves();
        if ($maxMoves === null) {
            return self::FAILURE;
        }

        try {
            $writeback = WritebackConfig::loadDefault();
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
            // Matched the way the writeback matches a payload repo — case-insensitively
            // (DL-293). Then re-keyed to the CONFIGURED spelling, because that is what the
            // per-repo token probe below needs.
            $configuredRepo = $writeback->configuredRepoFor($repoFilter);
            if ($configuredRepo === null) {
                $this->error("--repo {$repoFilter} is not a writeback.json mapping (have: ".implode(', ', array_keys($mappings)).')');

                return self::FAILURE;
            }
            $mappings = [$configuredRepo => $mappings[$configuredRepo]];
        }

        try {
            $kanban = WritebackClientFactory::make();
        } catch (Throwable $e) {
            $this->error('kanban writeback client: '.$e->getMessage());

            return self::FAILURE;
        }
        $probe = new GitHubRepoProbe;

        $this->info($fix ? 'bridge:reconcile --fix (applying forward moves)' : 'bridge:reconcile (report-only; pass --fix to apply)');

        $refs = new ExternalReferenceNormalizer;

        // Per-repo token resolution + startup auth/scope probe (GitHubRepoProbe — the
        // shared home so bridge:check classifies a token problem IDENTICALLY, DL-185/186).
        // The probe fails LOUDLY here (non-zero exit) when a token can't read its repo,
        // rather than every per-card getPull silently 404-ing while the run exits 0 (the
        // wholesale-degradation trap). A per-repo failure — unresolvable OR unreadable —
        // skips only that repo's cards; other repos still run. The RAW mapping key is
        // probed ([git-credential-map] is case-sensitive); repoUsable/clients are keyed
        // by the canonical form (how cards resolve their repo). The resolved leg is named
        // (DL-186) so an auth failure points at WHICH credential source won (a stale
        // <secret_dir>/github/token shadowing the store map is the common upgrade footgun);
        // never the token, only the source.
        foreach ($mappings as $repo => $mapping) {
            $canon = $refs->canonicalizeSource((string) $repo) ?? (string) $repo;
            $result = $probe->probe((string) $repo);
            $from = $result->source !== null ? " (token from {$result->source})" : '';

            switch ($result->kind) {
                case GitHubRepoProbeKind::Ok:
                    $this->clients[$canon] = $result->client;
                    $this->repoUsable[$canon] = true;
                    if ($this->output->isVerbose()) {
                        $this->line("github: {$repo} — readable{$from}");
                    }
                    break;
                case GitHubRepoProbeKind::Unresolvable:
                    $this->error("github token for {$repo}: {$result->problem} — bridge:reconcile reads PR state from GitHub (the repo is private, so a read-only token is required); its cards will be SKIPPED. Place a token file (chmod 600), map the repo in the coordination store's [git-credential-map], or export GH_TOKEN.");
                    $this->repoUsable[$canon] = false;
                    $this->hadError = true;
                    break;
                case GitHubRepoProbeKind::Http:
                    $this->error("github: cannot read repo {$repo} — HTTP {$result->status}{$result->hint}{$from}; its cards will be SKIPPED");
                    $this->repoUsable[$canon] = false;
                    $this->hadError = true;
                    break;
                case GitHubRepoProbeKind::Network:
                    $this->error("github: cannot reach repo {$repo} — {$result->networkMessage}{$from}; its cards will be SKIPPED");
                    $this->repoUsable[$canon] = false;
                    $this->hadError = true;
                    break;
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
            $this->reconcileCard(is_array($card) ? $card : [], $boardMappings, $byCanonRepo, $isShared, $order, $refs);
        }
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, WritebackMapping>  $boardMappings
     * @param  array<string, array{repo: string, mapping: WritebackMapping}>  $byCanonRepo
     * @param  array<int, float>  $order
     */
    private function reconcileCard(array $card, array $boardMappings, array $byCanonRepo, bool $isShared, array $order, ExternalReferenceNormalizer $refs): void
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

        // DL-301 / card#7211 (the fourth site of that card): this row came out of
        // `readBoardCards`' `q=board_id=<b>` search, and that scoping IS honoured by the
        // server — so this re-check refuses nothing today. That is the point: it makes the
        // scope a property of the RESULT rather than of the call, so a `q=`→top-level hoist
        // (which filters in a manual test, because `board_id` happens to be recognised there
        // too, and takes the next filter hoisted beside it silently out of the query) cannot
        // move a card on another tenant's board. Applied HERE — the moment a row is a card
        // this run will decide a move for — rather than at the `--fix` write, because which
        // rows get written is not known until the PR read, and a foreign row must not
        // consume this repo's GitHub token or land in the in-sync/backward counts either.
        // Fail-closed on an absent/unreadable board_id, like the six event-path arms.
        if (MappedBoardGuard::refuses($this->alerts, $card, $mapping, 'bridge_reconcile', $cardId, (string) $repo, self::ALERT_OUTCOME)) {
            $this->error("card {$cardId} ({$repo}): REFUSED — the board read returned a card that is not on the mapped board; not reconciled");
            $this->skipped++;
            $this->hadError = true;

            return;
        }
        // What a landed move RECORDS (card#7212): the row's own board beside the mapped one,
        // read off the row rather than from the mapping the loop was iterating. It is carried
        // to `finish()` because the row itself is long out of scope by the time the move is
        // applied. It feeds the durable log only — the console report names the mapped board,
        // which the guard above has just made equal to it on every surviving row.
        $record = MappedBoardGuard::boardContext($card, $mapping);

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
        // card#7348 / DL-305 (widened DL-308) — the SAME closure gate the event path
        // applies, over the SAME TWO FIELDS (the head branch ref and the title), because
        // this leg re-derives the same proposition from the same evidence. Without it the
        // backstop would keep re-planning the merge move the classifier had just declined:
        // `--fix` runs on a schedule, so the defect would simply arrive an hour later with
        // a CLI's name on it. Since DL-308 the lockstep cuts the other way too — a term
        // added here and not there (or there and not here) makes the two paths disagree
        // about which merges close a card, which is why the term itself lives on
        // `PrOutcome` and neither path spells it. A card carrying a `dl_number` may also be
        // closed by a closing form naming that DL, mirroring the classifier's DL arm.
        //
        // PLACED AFTER THE TERMINAL RETURN, which is what keeps it from adding a line about
        // a decision this command does not make: `merged_to_main` is out of scope here by
        // construction (the promote workflow owns that transition), so gating it would
        // report a withheld move on every release PR and withhold nothing.
        // `PrOutcome::requiresClosure()` still owns WHICH outcomes are gated — this
        // placement narrows where the answer can matter, never what the answer is.
        if (PrOutcome::requiresClosure($outcome) && ! $this->closes($pr['title'], $pr['head_ref'], $outcome, $cardId, $payload, $refs)) {
            $this->line("card {$cardId} ({$cardRepo}#{$prNumber}): PR is merged but neither its head branch ref ('{$pr['head_ref']}') nor a closing form in its title names this card — a MENTION, not a closure claim; no expected stage (mention-vs-closure, DL-305/DL-308) — skipped");
            $this->skipped++;

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
            $this->backward[] = $this->driftRow($cardId, $mapping->boardId, $record, $current, $expected, $outcome, $evidence, 'unorderable');
            $this->hadError = true;

            return;
        }
        if ($expPos < $curPos) {
            // Backward drift — report only. Usually a deliberate human move; the
            // reconciler never regresses a card (DL-163 posture).
            $this->backward[] = $this->driftRow($cardId, $mapping->boardId, $record, $current, $expected, $outcome, $evidence, 'backward');

            return;
        }

        $this->planned[] = $this->driftRow($cardId, $mapping->boardId, $record, $current, $expected, $outcome, $evidence, 'forward');
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

        // The PR-reference precedence (pr_url → pr_number → dl-only) is the shared
        // TrackedCardRef authority (canon #5) — kept single-sourced with the DL-207
        // promote-on-release scan so the two can't derive a card's PR differently. This
        // method maps each kind onto reconcile's own skip line + counter.
        $ref = TrackedCardRef::fromPayload($payload, $isShared, $refs);
        switch ($ref->kind) {
            case TrackedRefKind::PrUrl:
                $owner = $byCanonRepo[$ref->canonRepo] ?? null;
                if ($owner === null) {
                    $this->line("card {$cardId}: pr_url repo {$ref->canonRepo} is not in scope for this board (unmapped, or excluded by --repo) — skipped");
                    $this->skipped++;

                    return $none;
                }

                return [$owner['repo'], $owner['mapping'], $ref->canonRepo, $ref->prNumber, $ref->prUrl];

            case TrackedRefKind::PrNumber:
                // exactly one mapping on a 1:1 board
                $repo = array_key_first($boardMappings);
                $mapping = $boardMappings[$repo];
                $canon = $refs->canonicalizeSource((string) $repo) ?? (string) $repo;

                return [$repo, $mapping, $canon, $ref->prNumber, null];

            case TrackedRefKind::Ambiguous:
                $this->line("card {$cardId}: bare pr_number {$ref->prNumber} on shared board — ambiguous repo (needs a repo-qualified pr_url); skipped");
                $this->skipped++;

                return $none;

            case TrackedRefKind::DlOnly:
                $this->line("card {$cardId} (DL {$ref->dl}): no PR reference (pr_url/pr_number) — DL→PR resolution is out of v1 scope; skipped");
                $this->skipped++;

                return $none;

            case TrackedRefKind::None:
                // not a tracked card (no pr/dl) — silent.
                return $none;
        }

        return $none;   // unreachable (TrackedRefKind is exhaustive) — satisfies static analysis
    }

    /**
     * Does this PR CLAIM that merging it completes THIS card (card#7348 / DL-305, widened
     * by DL-308)?
     *
     * THE STRUCTURAL ROUTE IS ASKED FIRST and is not about the title at all: a merge into
     * the integration branch from a head branch whose ref names this card
     * ({@see PrOutcome::mergeClosesCard()}). The classifier applies the identical term to
     * the identical two fields, and it has to — this leg re-derives the same proposition
     * from the same evidence on a schedule, so a term present on one side and absent on
     * the other means the backstop and the event path disagree about which merges close a
     * card, which is the drift `PrOutcome` exists to prevent. In practice only the
     * integration outcome ever reaches here (the `merged_to_main` terminal return sits
     * above), but the outcome is passed rather than assumed so the two calls are the same
     * call.
     *
     * The two LEXICAL ways a title can name the card mirror the two correlation channels,
     * exactly as the classifier's own gate does: the native `card#<id>`, or a closing form
     * naming the `DL-NNN` the card carries in its payload — a card stamped `dl_number` IS
     * the card that DL resolves to here, which is the same relation the classifier's DL arm
     * uses to authorize its set.
     *
     * THE DL IS READ OFF THE CARD, NEVER OFF THE TITLE. A `DL-NNN` in the title that this
     * card does not carry is another card's work, and reading it would re-open through the
     * backstop precisely the foreign-mention door DL-218 closed on the event path.
     *
     * THE DL COMPARE GOES THROUGH {@see ExternalReferenceNormalizer::canonicalize()}, and
     * this is the one place in the closure path that needs it: the classifier compares two
     * tokens parsed out of ONE string by one grammar, so their spellings agree by
     * construction, while here a title's `DL-305` meets a STORED `dl_number` an operator
     * or a tool may have written as `DL-0305`. That normalizer is what the board itself
     * derives its `dl:` ref with, so this asks the same authority rather than minting a
     * second answer about DL identity — {@see DlTokenGrammar} deliberately preserves
     * leading zeros and is not it.
     *
     * @param  array<string, mixed>  $payload  the card payload (the `dl_number` stamp)
     */
    private function closes(string $title, string $headRef, string $outcome, int $cardId, array $payload, ExternalReferenceNormalizer $refs): bool
    {
        if (PrOutcome::mergeClosesCard($outcome, $headRef, $cardId)) {
            return true;
        }
        if (ClosureGrammar::closesCard($title, $cardId)) {
            return true;
        }
        $dl = $payload['dl_number'] ?? null;
        if (! is_string($dl) && ! is_int($dl)) {
            return false;
        }
        $mine = $refs->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, $dl);
        if ($mine === null) {
            return false;
        }
        foreach (ClosureGrammar::closedDls($title) as $closed) {
            if ($refs->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, $closed) === $mine) {
                return true;
            }
        }

        return false;
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
     * TWO board values, with distinct consumers, and neither is derivable from the other
     * at the point it is read. `$board` is the mapped board this run reconciled the card
     * under — what the console report names. `$record` is the pair the DURABLE move record
     * carries (card#7212): the row's OWN `board_id` beside the mapped one, captured while
     * the row was still in scope. They agree by construction on every row that gets here
     * (the guard refused the rest), which is exactly why the report needs no second
     * rendering of the card's value — but the record is written for the day that stops
     * being true, so it reads the row and never the mapping.
     *
     * @param  array{card_board: mixed, mapped_board: int}  $record  {@see MappedBoardGuard::boardContext}
     * @return array{card_id: int, board: int, record: array{card_board: mixed, mapped_board: int}, current: int, expected: int, outcome: string, evidence: string, kind: string}
     */
    private function driftRow(int $cardId, int $board, array $record, int $current, int $expected, string $outcome, string $evidence, string $kind): array
    {
        return [
            'card_id' => $cardId,
            'board' => $board,
            'record' => $record,
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
                    // The DURABLE half of the record, beside the console line (card#7212). This
                    // leg's refusal is a `Log::warning` through the alert primitive, so a
                    // console-only success would leave the same asymmetry that made "did a
                    // cross-board write ever LAND?" unanswerable for the six event-path arms:
                    // the operator would need an ad-hoc cron redirect to answer it, while the
                    // refusal was in the log all along. An absence of record is not a record of
                    // absence.
                    Log::info('bridge_reconcile: moved', ['card_id' => $p['card_id'], 'stage' => $p['expected'], 'outcome' => $p['outcome']] + $p['record']);
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
