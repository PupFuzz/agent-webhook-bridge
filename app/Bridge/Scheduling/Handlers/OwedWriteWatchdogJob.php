<?php

namespace App\Bridge\Scheduling\Handlers;

use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Writeback\OwedWriteQueue;

/**
 * Gives up, with a named alert, any durable write the bridge has owed longer than
 * {@see OwedWriteQueue::MAX_AGE_S} (card#10849 / DL-440).
 *
 * ⭐ WHY IT IS A JOB (docs/periodic-jobs.md's decision order, step 4). A write is left owed when
 * its apply was rate-limited, failed any other way, lost the subject's lock, or died with its
 * process, and it is retried by that subject's NEXT drain — the next event about the same card,
 * or the retry sweep. A subject that never sees another event has no arrival to gate on, so
 * only a clock can notice the row has sat too long and say so. Without this an install with the
 * retry sweep switched off would hold such a write forever, silently — and so would one where
 * the sweep keeps failing it.
 *
 * ⚑ {@see JobCapability::ReadAndAlert}, and within that class's own docblock: it reads and
 * deletes rows of the bridge's OWN bookkeeping table and alerts. It never calls a durable
 * handler and never touches kanban or GitHub, so it needs no arming — it only ever gives up on
 * something already stuck, converting silence into a named alert. The mutating half is
 * {@see OwedWriteRetryJob}.
 *
 * ⚑ ITS INSTANCE IS DECLARED BY THE QUEUE, not shipped: {@see OwedWriteQueue} inserts
 * {@see self::spec()} at every durable write, before the row exists — so an owed write has it
 * behind it EXCEPT where that declare failed (logged `owed_write.watchdog_undeclared`), or after
 * an operator removed the instance and before the next durable write re-declares it. An install
 * that never makes a durable write never grows this job.
 */
final class OwedWriteWatchdogJob implements JobHandler
{
    public const NAME = 'owed_write_watchdog';

    /** The instance the queue declares. */
    public const INSTANCE = 'writeback-owed-writes-watchdog';

    /** Rows given up per pass — a job must be bounded (JobHandler); a backlog drains across passes. */
    public const MAX_PER_PASS = 50;

    public function __construct(private readonly HandlerRegistry $handlers) {}

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
        $expired = OwedWriteRetryJob::queueFor($this->handlers)->expireAged(self::MAX_PER_PASS);

        return JobOutcome::ok($expired === 0
            ? 'no owed write older than '.OwedWriteQueue::MAX_AGE_S.'s'
            : "gave up {$expired} owed write(s) older than ".OwedWriteQueue::MAX_AGE_S.'s — each alerted with its bridge:replay remedy');
    }

    public static function spec(): JobSpec
    {
        return new JobSpec(
            name: self::INSTANCE,
            handler: self::NAME,
            intervalS: 900,
            owner: 'bridge',
            docsRef: 'docs/writeback.md#failure-behaviour-what-retries-vs-not',
            justification: 'an owed write on a subject that receives no further event has no arrival to gate on, so only a clock can notice it has sat past its age bound and alert on it',
        );
    }
}
