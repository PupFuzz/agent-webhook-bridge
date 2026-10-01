<?php

namespace App\Bridge\Scheduling\Handlers;

use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Scheduling\JobsConfig;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\OwedWriteQueue;
use App\Models\ScheduledJob;
use Throwable;

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
 * which is exactly the surface DL-325's arming governs. It is ARMED BY DEFAULT — the first
 * mutator to be, by operator ruling ahead of card#10918 / DL-441 making that the rule for
 * every mutator — and, unlike a custom mutator, its instance is declared by
 * {@see OwedWriteQueue::declareJobs()} at every durable write, before its row is inserted — the
 * same trigger as {@see OwedWriteWatchdogJob}'s. Whether it can actually retry an owed write on
 * THIS install — and, when it cannot, the key or command that stopped it — is owned by
 * {@see self::clockRetryGap()}, not restated here; a declare that failed is additionally logged
 * (`owed_write.retry_undeclared`). An install that never makes a durable write grows neither.
 * Operator
 * ruling, 2026-09-29 (card#10849 / DL-440): new functionality defaults on and needs no setup.
 * `BRIDGE_OWED_WRITE_RETRY_DISABLED=true` (or naming it in `BRIDGE_JOBS_DISARMED_MUTATORS`) is
 * the kill switch — {@see JobHandlerRegistry::disarmedBy()} — and `bridge:jobs disable` (an
 * OPERATOR act, independent of arming) stops a declared instance from running without
 * disarming the handler at all.
 */
final class OwedWriteRetryJob implements JobHandler
{
    public const NAME = 'owed_write_retry';

    /** The instance {@see OwedWriteQueue::declareJobs} declares unless this job is disarmed. */
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

    /**
     * Why an owed write is NOT being retried on a clock on this install, naming the key or the
     * command that fixes it — or null when nothing stands in the way (the instance exists, is
     * enabled, was not refused at its last run, and the registry runs jobs at all). The one
     * answer both loud surfaces print: the give-up alert (`retry_sweep_gap`) and `bridge:check`'s
     * `writeback.owed_writes_table` leg, so a sweep that silently never runs is named by the
     * config that stopped it (operator ruling, card#10849 / DL-440). Never throws — it is read on
     * the alert path; an unreadable answer is itself named.
     */
    public static function clockRetryGap(): ?string
    {
        $disarmedBy = JobHandlerRegistry::disarmedBy(self::NAME);
        if ($disarmedBy !== null) {
            return $disarmedBy.' switches the owed-write retry sweep off — unset it to turn it back on';
        }
        try {
            $posture = JobsConfig::fromConfig();
            if (! $posture->enabled) {
                return 'BRIDGE_JOBS_ENABLED=false — no periodic job runs on this install, so neither the owed-write retry sweep nor its watchdog does';
            }
            if ($posture->problem !== null) {
                return 'the job registry can run no pass on this install, so neither the owed-write retry sweep nor its watchdog does: '.$posture->problem;
            }
            $job = ScheduledJob::query()->where('name', self::INSTANCE)->first();
        } catch (Throwable $e) {
            return 'whether the owed-write retry sweep can run could not be determined ('.RedactedErrorText::of($e).')';
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
            docsRef: 'docs/writeback.md#failure-behaviour-what-retries-vs-not',
            justification: 'armed by default (card#10849 / DL-440, operator ruling): retries an owed write whose subject sees no further event before it ages out',
        );
    }
}
