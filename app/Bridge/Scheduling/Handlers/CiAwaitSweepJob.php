<?php

namespace App\Bridge\Scheduling\Handlers;

use App\Bridge\CiAwait\CiAwaitService;
use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Scheduling\JobsConfig;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Support\RedactedErrorText;
use App\Models\ScheduledJob;
use Throwable;

/**
 * The clock half of `ci_await` (card#11200 / DL-452): emits `ci_await_expired` once per await
 * past its expiry, and re-reads heads whose last workflow-run read FAILED.
 *
 * ⭐ WHY IT IS A JOB (docs/periodic-jobs.md's decision order, step 4). An await is settled by the
 * arrival of a `workflow_run.completed` on its head, so the common path needs no clock. What has
 * no arrival to gate on is the await that never settles — CI that never finishes, a webhook that
 * does not send workflow runs, a read that keeps failing — and a seat that registered a wait must
 * not wait silently forever. Only a clock can notice that.
 *
 * ⚑ NO POLLING. The retry half re-reads only heads whose previous read FAILED; a head whose read
 * answered is read again when its next run completes, never on this clock.
 *
 * ⚑ {@see JobCapability::ReadAndAlert}: it deletes rows of the bridge's own `ci_awaits` bookkeeping,
 * reads GitHub and tells a seat. It writes nothing on kanban or GitHub.
 *
 * ⚑ ITS INSTANCE IS DECLARED BY `ci_await`, not shipped: {@see CiAwaitService::register()} declares
 * {@see self::spec()} at every registration, so an install whose seats never register an await
 * never grows this job. Whether it can run on this install is {@see self::clockGap()}'s answer.
 */
final class CiAwaitSweepJob implements JobHandler
{
    public const NAME = 'ci_await_sweep';

    public const INSTANCE = 'ci-await-sweep';

    /** Expired awaits emitted per pass; a backlog drains across passes. */
    public const MAX_EXPIRED_PER_PASS = 50;

    /** Heads re-read per pass — each is one paginated GitHub read. */
    public const MAX_RETRIES_PER_PASS = 5;

    public function __construct(private readonly CiAwaitService $awaits) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): JobCapability
    {
        return JobCapability::ReadAndAlert;
    }

    public function run(JobContext $ctx): JobOutcome
    {
        $expired = $this->awaits->expireDue(self::MAX_EXPIRED_PER_PASS);
        $retried = $this->awaits->retryUnmeasured(self::MAX_RETRIES_PER_PASS);

        return JobOutcome::ok("expired {$expired} ci_await(s); re-read {$retried} head(s) whose last read failed");
    }

    /**
     * Why `ci_await_expired` is NOT being emitted on a clock on this install, naming the key or the
     * command that fixes it — or null when nothing stands in the way. Read by `bridge:check`'s
     * `ci_await.awaits` leg. Never throws; an unreadable answer is itself named.
     *
     * ⚠ The same shape as {@see OwedWriteRetryJob::clockRetryGap()} with this job's words; the two
     * are not yet one primitive (DL-452 names the consolidation).
     */
    public static function clockGap(): ?string
    {
        try {
            $posture = JobsConfig::fromConfig();
            if (! $posture->enabled) {
                return 'BRIDGE_JOBS_ENABLED=false — no periodic job runs on this install, so no await expires and no failed read is retried on a clock';
            }
            if ($posture->problem !== null) {
                return 'the job registry can run no pass on this install, so no await expires: '.$posture->problem;
            }
            $job = ScheduledJob::query()->where('name', self::INSTANCE)->first();
        } catch (Throwable $e) {
            return 'whether the ci_await sweep can run could not be determined ('.RedactedErrorText::of($e).')';
        }
        if ($job === null) {
            return 'the periodic job `'.self::INSTANCE.'` is not declared — php artisan bridge:jobs add '.self::INSTANCE.' --handler='.self::NAME.' (docs/periodic-jobs.md)';
        }
        if (! (bool) $job->enabled) {
            return 'the periodic job `'.self::INSTANCE.'` is disabled — php artisan bridge:jobs enable '.self::INSTANCE;
        }
        if ($job->last_status === ScheduledJob::STATUS_REFUSED) {
            return 'the periodic job `'.self::INSTANCE.'` was REFUSED at its last run: '.(string) $job->last_error;
        }

        return null;
    }

    public static function spec(): JobSpec
    {
        return new JobSpec(
            name: self::INSTANCE,
            handler: self::NAME,
            intervalS: 300,
            owner: 'bridge',
            docsRef: 'docs/board-tools.md#ci_await-and-ci_await_cancel',
            justification: 'an await whose CI never settles has no arrival to gate on, so only a clock can expire it and tell the waiting seat',
        );
    }
}
