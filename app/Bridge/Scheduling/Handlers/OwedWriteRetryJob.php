<?php

namespace App\Bridge\Scheduling\Handlers;

use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\OwedWriteQueue;

/**
 * The periodic drain of owed durable writes (card#10849 / DL-440): every subject whose head
 * row is due gets {@see OwedWriteQueue::drain()} — the SAME call a live delivery makes, under
 * the same per-subject lock, so a sweep and a delivery can never apply one subject twice or out
 * of order.
 *
 * ⭐ WHY IT IS A JOB. A subject is already retried inline by its NEXT live event, with no
 * arming (that is ordinary request processing), and the always-on {@see OwedWriteWatchdogJob}
 * makes sure a write nothing revisits is given up LOUDLY rather than held forever. What only
 * this job adds is a retry for a subject that gets no further event before it ages out — no
 * arrival to gate on, so only a clock can.
 *
 * ⛔ {@see JobCapability::MutatesState} — IT APPLIES BOARD WRITES WITH NO REQUEST BEHIND IT,
 * which is exactly the surface DL-325's arming exists for. UNLIKE every other mutator,
 * though, THIS ONE IS ARMED BY DEFAULT, and its instance is declared by
 * {@see OwedWriteQueue::declareJobs()} the first time a write is left owed — the same trigger
 * as {@see OwedWriteWatchdogJob}'s, so an install never rate-limited grows neither. Operator
 * ruling, 2026-09-29 (card#10849 / DL-440): new functionality defaults on and needs no setup;
 * DL-325's default-off is a bridge-wide question for a separate card, and this is the one
 * named exception ahead of it — see {@see JobHandlerRegistry::armedFromConfig}.
 * `BRIDGE_OWED_WRITE_RETRY_DISABLED=true` is the kill switch back to DL-325's ordinary
 * unarmed state, and `bridge:jobs disable` (an OPERATOR act, independent of arming) stops a
 * declared instance from running without touching the armed set at all.
 */
final class OwedWriteRetryJob implements JobHandler
{
    public const NAME = 'owed_write_retry';

    /** The instance {@see OwedWriteQueue::declareJobs} declares when this job is armed. */
    public const INSTANCE = 'writeback-owed-writes-retry';

    /**
     * Subjects drained per pass. Small on purpose: one drain can be a whole promote scan, and on
     * the event ingress the pass runs inside an FPM worker's after-response callback.
     */
    public const MAX_SUBJECTS_PER_PASS = 5;

    public function __construct(private readonly HandlerRegistry $handlers) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): JobCapability
    {
        return JobCapability::MutatesState;
    }

    public function run(JobContext $ctx): JobOutcome
    {
        $drained = self::queueFor($this->handlers)->sweep(self::MAX_SUBJECTS_PER_PASS);

        return JobOutcome::ok($drained === 0 ? 'no owed write due' : "drained {$drained} subject(s) with a due owed write");
    }

    /**
     * The queue a job pass works through: the process's handler registry, and the agent roster
     * read fresh from this install's config dir — a pass has no request whose roster it could
     * borrow, and the registry is lazy, so a pass that applies nothing reads no YAML.
     */
    public static function queueFor(HandlerRegistry $handlers): OwedWriteQueue
    {
        return new OwedWriteQueue($handlers, new SubscriptionRegistry((string) config('bridge.config_dir')));
    }

    public static function spec(): JobSpec
    {
        return new JobSpec(
            name: self::INSTANCE,
            handler: self::NAME,
            intervalS: 300,
            owner: 'bridge',
            docsRef: 'docs/writeback.md#failure-behaviour-what-retries-vs-not',
            justification: 'armed by default (card#10849 / DL-440, operator ruling): retries an owed write whose subject sees no further event before it ages out',
        );
    }
}
