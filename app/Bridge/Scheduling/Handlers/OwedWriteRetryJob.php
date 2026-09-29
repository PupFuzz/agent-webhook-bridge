<?php

namespace App\Bridge\Scheduling\Handlers;

use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\OwedWriteQueue;

/**
 * The periodic drain of owed durable writes (card#10849 / DL-440): every subject whose head
 * row is due gets {@see OwedWriteQueue::drain()} — the SAME call a live delivery makes, under
 * the same per-subject lock, so a sweep and a delivery can never apply one subject twice or out
 * of order.
 *
 * ⭐ WHY IT IS A JOB, AND WHY IT IS OPTIONAL. A subject is already retried inline by its NEXT
 * live event, with no arming (that is ordinary request processing), and the always-on
 * {@see OwedWriteWatchdogJob} makes sure a write nothing revisits is given up LOUDLY rather
 * than held forever. What only this job adds is a retry for a subject that gets no further
 * event before it ages out — no arrival to gate on, so only a clock can.
 *
 * ⛔ {@see JobCapability::MutatesState}, AND THEREFORE INERT UNTIL ARMED. It applies board
 * writes with no request behind it, which is exactly the surface DL-325's arming exists for:
 * add `owed_write_retry` to `BRIDGE_JOBS_ARMED_MUTATORS` and insert an instance
 * (`docs/writeback.md` § *Failure behaviour*). Shipping it unarmed is the recommended default
 * the design put to the operator (DL-440); the code is the same either way.
 */
final class OwedWriteRetryJob implements JobHandler
{
    public const NAME = 'owed_write_retry';

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
}
