<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\CiAwait\CiAwaitConfig;
use App\Bridge\CiAwait\CiAwaitService;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Scheduling\Handlers\CiAwaitSweepJob;
use App\Bridge\Support\Finding;
use App\Bridge\Support\RedactedErrorText;
use App\Models\CiAwait;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Can a seat's `ci_await` be settled or expired on this install (card#11200 / DL-452)?
 *
 * FAILs on a `BRIDGE_CI_AWAIT_*` value the bridge refuses — every `ci_await` call refuses with it.
 * WARNs when the per-seat read limiter's cache store does not answer (card#11283).
 * WARNs when the `ci_awaits` table is missing (every `ci_await` refuses until `php artisan
 * migrate`). With awaits stored, WARNs for each thing that would leave one waiting until it
 * expires or forever: no clock to read or expire them ({@see CiAwaitSweepJob::clockGap()}); a runs
 * read that last failed; a seat whose inbox the bridge could not write an await's event to, naming
 * the seat; and an awaited repo this install holds no stored `workflow_run` delivery from — the
 * evidence that the repo's webhook sends Workflow runs here at all. ⚠ That last one is the
 * bridge's own record, not the webhook's configuration: none stored means none RETAINED, so a
 * correctly configured repo whose deliveries were pruned, or whose runs have not completed since
 * the hook was added, warns too. Silent with nothing stored and nothing misconfigured.
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
        foreach ([CiAwaitConfig::ttlSeconds(...), CiAwaitConfig::maxPerSeat(...), CiAwaitConfig::readCooldownSeconds(...), CiAwaitConfig::sweepReads(...), CiAwaitConfig::seatReadsPerHour(...)] as $read) {
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
            yield Finding::warn('ci_await: the per-seat read limiter\'s cache store did not answer ('.RedactedErrorText::of($e).') — every ci_await registration skips its own runs read until it does (awaits are still stored, and the sweep and workflow_run deliveries settle them). Check CACHE_STORE.');
        }

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
            yield Finding::warn("ci_await: the bridge could not write ci_settled / ci_await_expired to the inbox of agent `{$row->agent}` for {$row->awaits} await(s) (last attempt {$row->latest}) — each is kept and retried every sweep pass, and dropped undelivered ".CiAwaitService::EMIT_GIVE_UP_AFTER_SECONDS.' s past its expiry. Look for `bridge ci_await:` warnings naming that agent: its inbox file or state directory is not writable by the bridge.');
        }
        foreach ($silentRepos as $repo) {
            yield Finding::warn("ci_await: an await is stored on {$repo}, and this install holds no stored workflow_run delivery from it — if that repo's webhook does not send \"Workflow runs\" to this bridge, only the ci-await-sweep's own reads settle the await, at least one sweep interval after CI finishes and only while the sweep runs. Add the event on the repo webhook. (None stored is not proof of a missing subscription: retention prunes deliveries, and a new hook has sent none yet.)");
        }

        if ($gap === null && $failing->isEmpty() && $undeliverable->isEmpty() && $silentRepos === []) {
            yield Silence::because('every stored await has a clock to read and expire it, its last read answered, its seat\'s inbox took every event written to it, and its repo has delivered workflow runs here');
        }
    }
}
