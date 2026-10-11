<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\CiAwait\CiAwaitConfig;
use App\Bridge\CiAwait\CiAwaitService;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Scheduling\Handlers\CiAwaitSweepJob;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Tools\ServedToolsRule;
use App\Models\CiAwait;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Can a seat's `ci_await` be settled or expired on this install (card#11200 / DL-452)?
 *
 * While any agent is served the CI tools, reads each repo this install receives GitHub events for
 * once (card#11600, {@see repoReads()}): `ok` when GitHub answers, FAIL on a 404 or no token
 * (every `ci_await` there is refused `repo_unreadable`), UNVALIDATED on a single 401 or 403 and
 * when the read did not measure it. A repo declared in `BRIDGE_CI_AWAIT_NO_CI_REPOS` is not read
 * (card#11696, {@see declaredNoCi()}): `ok`, or a WARN when this install holds a `workflow_run`
 * delivery from it.
 *
 * FAILs on a `BRIDGE_CI_AWAIT_*` value the bridge refuses — every `ci_await` call refuses with it.
 * WARNs when the per-agent read limiter's cache store does not answer (card#11283).
 * WARNs when the `ci_awaits` table is missing (every `ci_await` refuses until `php artisan
 * migrate`). With awaits stored, WARNs for each thing that would leave one waiting until it
 * expires or forever: no clock to read or expire them ({@see CiAwaitSweepJob::clockGap()}); a runs
 * read that last failed; a seat whose inbox the bridge could not write an await's event to, naming
 * the seat; and an awaited repo this install holds no stored `workflow_run` delivery from — the
 * evidence that the repo's webhook sends Workflow runs here at all. ⚠ That last one is the
 * bridge's own record, not the webhook's configuration: none stored means none RETAINED, so a
 * correctly configured repo whose deliveries were pruned, or whose runs have not completed since
 * the hook was added, warns too. Silent with nothing stored, nothing misconfigured, and no agent
 * served the CI tools.
 */
final class CiAwaitsCheck implements Check
{
    public function id(): string
    {
        return 'ci_await.awaits';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        foreach ([CiAwaitConfig::ttlSeconds(...), CiAwaitConfig::maxPerSeat(...), CiAwaitConfig::readCooldownSeconds(...), CiAwaitConfig::sweepReads(...), CiAwaitConfig::seatReadsPerHour(...), CiAwaitConfig::overdueDefaultSeconds(...), CiAwaitConfig::noCiRepos(...)] as $read) {
            try {
                $read();
            } catch (ConfigException $e) {
                yield Finding::fail('ci_await: '.$e->getMessage().' — every ci_await call refuses (install_fault.ci_await_config_invalid) until it is fixed.');
            }
        }

        // card#11283: the per-seat registration read budget lives in the cache store. A store that
        // does not answer makes EVERY registration skip its own read (logged at the call, which
        // this run cannot see), so it is measured here, against the same key prefix.
        try {
            RateLimiter::attempts(CiAwaitService::SEAT_READ_LIMITER_PREFIX.'bridge-check-probe');
        } catch (Throwable $e) {
            yield Finding::warn('ci_await: the per-agent read limiter\'s cache store did not answer ('.RedactedErrorText::of($e).') — every ci_await registration skips its own runs read until it does (awaits are still stored, and the sweep and workflow_run deliveries settle them). Check CACHE_STORE.');
        }

        yield from $this->repoReads();

        try {
            $present = Schema::hasTable('ci_awaits');
            $awaits = $present ? CiAwait::query()->count() : 0;
        } catch (Throwable $e) {
            yield Finding::unvalidated('ci_await: the await store could NOT be checked — the database did not answer ('.RedactedErrorText::of($e).')');

            return;
        }

        if (! $present) {
            yield Finding::warn('ci_await: the `ci_awaits` table is MISSING — every ci_await call refuses (install_fault.ci_await_store_unavailable), so seats keep polling GitHub for CI. Run `php artisan migrate`.');

            return;
        }
        if ($awaits === 0) {
            yield Silence::because('no seat is awaiting CI on this install, so nothing can be left waiting');

            return;
        }

        $gap = CiAwaitSweepJob::clockGap();
        if ($gap !== null) {
            yield Finding::warn("ci_await: {$awaits} await(s) stored and nothing reads or expires them on a clock — {$gap}. Only a completed-run delivery on its head can then settle an await: one whose final delivery is lost, or whose CI never finishes, waits silently.");
        }

        try {
            $failing = CiAwait::query()->whereNotNull('last_error')->orderByDesc('last_read_at')->get(['repo_name', 'head_sha', 'last_error']);
            $undeliverable = CiAwait::query()->toBase()->whereNotNull('emit_failed_at')->selectRaw('agent, count(*) as awaits, max(emit_failed_at) as latest')->groupBy('agent')->orderBy('agent')->get();
            $repos = CiAwait::query()->distinct()->pluck('repo_name')->all();
            $silentRepos = array_values(array_filter($repos, static fn (string $repo): bool => ! CiAwaitService::hasRecordedWorkflowRun($repo)));
        } catch (Throwable $e) {
            yield Finding::unvalidated('ci_await: the stored awaits could NOT be read ('.RedactedErrorText::of($e).')');

            return;
        }

        if ($failing->isNotEmpty()) {
            $latest = $failing->first();
            yield Finding::warn("ci_await: the last workflow-run read FAILED for {$failing->count()} await(s), so none of them can settle until a read answers — most recent: {$latest->repo_name}@{$latest->head_sha}: {$latest->last_error}. The ci_await sweep reads each head again, and an await expires with the error if no read answers.");
        }
        foreach ($undeliverable as $row) {
            yield Finding::warn("ci_await: the bridge could not write ci_settled / ci_await_unreadable / ci_await_expired / ci_await_overdue to the inbox of agent `{$row->agent}` for {$row->awaits} await(s) (last attempt {$row->latest}) — each is kept and retried every sweep pass, and dropped undelivered ".CiAwaitService::EMIT_GIVE_UP_AFTER_SECONDS.' s past its expiry. Look for `bridge ci_await:` warnings naming that agent: its inbox file or state directory is not writable by the bridge.');
        }
        foreach ($silentRepos as $repo) {
            yield Finding::warn("ci_await: an await is stored on {$repo}, and this install holds no stored workflow_run delivery from it — if that repo's webhook does not send \"Workflow runs\" to this bridge, only the ci-await-sweep's own reads settle the await, at least one sweep interval after CI finishes and only while the sweep runs. Add the event on the repo webhook. (None stored is not proof of a missing subscription: retention prunes deliveries, and a new hook has sent none yet.)");
        }

        if ($gap === null && $failing->isEmpty() && $undeliverable->isEmpty() && $silentRepos === []) {
            yield Silence::because('every stored await has a clock to read and expire it, its last read answered, its seat\'s inbox took every event written to it, and its repo has delivered workflow runs here');
        }
    }

    /**
     * Can each repo `ci_await` accepts be read for workflow runs (card#11600)? One read per repo
     * this install receives GitHub events for, made by {@see CiAwaitService::probeRunsRead()} —
     * the token resolution and failure classes an await's own read uses — and only while some agent
     * is served the CI tools: with none, no seat can call `ci_await`, and the leg asks GitHub nothing.
     * A repo declared to have no CI is not read ({@see declaredNoCi()}).
     *
     * A read whose failure an await would end on at once (a 404, no token for any reader) FAILS,
     * naming the repo, the token source and file, and the remedy. A 401 or a non-rate-limited 403 is
     * UNVALIDATED naming its status: one read cannot confirm it, and this leg makes one. One an
     * await would retry — a rate limit, a 5xx, no answer, a token this process could not read — is
     * UNVALIDATED too, never a pass; a 2xx is `ok`.
     * Cost: one request per received repo per run (no GitHub read budget governs `bridge:check`).
     *
     * @return iterable<Finding>
     */
    private function repoReads(): iterable
    {
        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (ConfigException $e) {
            yield Finding::unvalidated('ci_await: the agent configs could not be read ('.RedactedErrorText::of($e).'), so which repos ci_await accepts, and whether GitHub lets this install read their workflow runs, was NOT checked.');

            return;
        }
        $served = array_filter($configs, static fn (AgentConfig $cfg): bool => ServedToolsRule::servesCiTools($cfg->boardTools));
        if ($served === []) {
            return;
        }

        try {
            $noCi = CiAwaitConfig::noCiRepos();
        } catch (ConfigException) {
            // Already a FAIL from run()'s config pass, and every ci_await refuses until it is fixed.
            $noCi = [];
        }

        foreach (CiAwaitService::receivedRepos($configs) as $repo) {
            if (in_array(CiAwaitService::key($repo), $noCi, true)) {
                yield from $this->declaredNoCi($repo);

                continue;
            }
            $failure = CiAwaitService::probeRunsRead($repo);
            if ($failure === null) {
                yield Finding::ok("ci_await: GitHub lets this install read {$repo}'s workflow runs, so a ci_await there can be answered.");
            } elseif ($failure->needsConfirmation) {
                yield Finding::unvalidated("ci_await: GitHub answered HTTP {$failure->status} to one read of {$repo}'s workflow runs — ".RedactedErrorText::of($failure).'. One such answer is not final: a secondary rate limit can answer 403 naming no reset, and a token being rotated can answer 401 briefly, so a confirming read at least '.CiAwaitService::CONFIRM_AFTER_SECONDS.' s later is needed before ci_await treats the repo as unreadable. Re-run bridge:check after that; if it answers the same, '.CiAwaitService::unreadableRemedy($repo).'.');
            } elseif ($failure->repoUnreadable) {
                yield Finding::fail("ci_await: this install cannot read {$repo}'s workflow runs — ".RedactedErrorText::of($failure).". Every ci_await on {$repo} is refused as repo_unreadable, and an await stored before this ends with ci_await_unreadable. To fix: ".CiAwaitService::unreadableRemedy($repo).'; then re-run bridge:check.');
            } else {
                yield Finding::unvalidated("ci_await: whether this install can read {$repo}'s workflow runs was NOT measured — ".RedactedErrorText::of($failure).'. This is not a pass and not evidence the token is bad; ci_await keeps such an await and reads it again. Re-run bridge:check.');
            }
        }
    }

    /**
     * A received repo the operator declared to have no CI (card#11696): its runs are not read, so
     * whether its token could read them is not reported either way. The declaration is held against
     * the one evidence of CI this install keeps — a stored `workflow_run` delivery from the repo —
     * and a repo that has delivered one is a WARN: the declaration is then turning ci_await off on a
     * repo that does run CI, which is what this leg's FAIL exists to surface. No delivery stored is
     * not proof of no CI (a hook that does not send Workflow runs, retention), which is why this is
     * a declaration and not derived from it.
     *
     * @return iterable<Finding>
     */
    private function declaredNoCi(string $repo): iterable
    {
        try {
            $hasRuns = CiAwaitService::hasRecordedWorkflowRun($repo);
        } catch (Throwable $e) {
            yield Finding::unvalidated("ci_await: {$repo} is declared to have no CI (BRIDGE_CI_AWAIT_NO_CI_REPOS), so its workflow runs were not read and every ci_await there is refused as repo_not_ci — and whether this install holds a workflow_run delivery from it, which would contradict that, could NOT be read (".RedactedErrorText::of($e).').');

            return;
        }
        if ($hasRuns) {
            yield Finding::warn("ci_await: {$repo} is declared to have no CI (BRIDGE_CI_AWAIT_NO_CI_REPOS), but this install holds a workflow_run delivery from it — it does run CI, and every ci_await there is refused as repo_not_ci. If seats should wait on its CI, remove it from BRIDGE_CI_AWAIT_NO_CI_REPOS and re-run bridge:check, which then reads its workflow runs.");

            return;
        }
        yield Finding::ok("ci_await: {$repo} is declared to have no CI (BRIDGE_CI_AWAIT_NO_CI_REPOS), so its workflow runs are not read and every ci_await there is refused as repo_not_ci.");
    }
}
