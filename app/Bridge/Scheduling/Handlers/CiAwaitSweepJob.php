<?php

namespace App\Bridge\Scheduling\Handlers;

use App\Bridge\CiAwait\CiAwaitConfig;
use App\Bridge\CiAwait\CiAwaitService;
use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Scheduling\JobsConfig;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Support\RedactedErrorText;
use App\Models\ScheduledJob;
use RuntimeException;
use Throwable;

/**
 * The clock half of `ci_await` (card#11200 / DL-452), and the half that carries its correctness:
 * each pass reads unsettled heads whose last read is stale ({@see CiAwaitService::sweepUnsettled()})
 * and emits `ci_await_expired` once per await past its expiry ({@see CiAwaitService::expireDue()}).
 *
 * ⭐ WHY IT IS A JOB (docs/periodic-jobs.md's decision order, step 4). A delivery settles only the
 * awaits its read loaded, a registration's read can see a lagging list, a final delivery can be
 * lost, and CI may never finish — in each case no further arrival will touch the await. Only a clock
 * can. So the sweep is LEVEL-TRIGGERED: it reads every unexpired head, never-read first, whose oldest read is one
 * interval old, never on the strength of an event having happened.
 *
 * ⭐ ITS COST IS BOUNDED BY CONSTRUCTION: one read per HEAD (shared by every seat awaiting it), only
 * for a head not read within the interval, at most `BRIDGE_CI_AWAIT_SWEEP_READS` per pass — so at
 * most that × 3600 / the interval head reads per hour, install-wide.
 *
 * ⚑ THE TWO HALVES ARE ISOLATED: one throwing does not skip the other, and the pass still throws
 * afterwards so the registry records the failure ({@see JobOutcome}'s one failure channel). The read
 * half runs first, so an await whose CI finished just before its expiry is settled, not expired.
 *
 * ⚑ {@see JobCapability::ReadAndAlert}: it deletes rows of the bridge's own `ci_awaits` bookkeeping,
 * reads GitHub and tells a seat. It writes nothing on kanban or GitHub.
 *
 * ⚑ ITS INSTANCE IS DECLARED BY `ci_await`, not shipped: {@see CiAwaitService::store()} declares
 * {@see self::spec()} at every registration, so an install whose seats never register an await
 * never grows this job. Whether it can run on this install is {@see self::clockGap()}'s answer.
 */
final class CiAwaitSweepJob implements JobHandler
{
    public const NAME = 'ci_await_sweep';

    public const INSTANCE = 'ci-await-sweep';

    /** Expired awaits emitted per pass; a backlog drains across passes. */
    public const MAX_EXPIRED_PER_PASS = 50;

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
        $done = [];
        $failed = [];
        try {
            $swept = $this->awaits->sweepUnsettled(CiAwaitConfig::sweepReads(), $ctx->intervalS);
            $done[] = "read {$swept['read']} head(s) not read within {$ctx->intervalS} s";
            if ($swept['failed'] > 0) {
                $failed[] = "the read of {$swept['failed']} head(s) threw (logged; each is stamped and the other heads were still read)";
            }
        } catch (Throwable $e) {
            $failed[] = 'reading unsettled heads failed: '.RedactedErrorText::of($e);
        }
        try {
            $expiry = $this->awaits->expireDue(self::MAX_EXPIRED_PER_PASS, $ctx->intervalS);
            $done[] = "expired {$expiry['emitted']} ci_await(s)"
                .($expiry['failed'] === 0 ? '' : ", {$expiry['failed']} could not be written to their seat's inbox")
                .($expiry['dropped'] === 0 ? '' : ", dropped {$expiry['dropped']} undelivered past the give-up ceiling");
        } catch (Throwable $e) {
            $failed[] = 'expiring awaits failed: '.RedactedErrorText::of($e);
        }
        if ($failed !== []) {
            throw new RuntimeException('ci_await sweep: '.implode('; ', [...$failed, ...$done]));
        }

        return JobOutcome::ok(implode('; ', $done));
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
                return 'BRIDGE_JOBS_ENABLED=false — no periodic job runs on this install, so no await expires and no head is read on a clock';
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
            justification: 'an await no arrival will touch again (a lost or lagging delivery, CI that never finishes) can only be read again or expired on a clock',
        );
    }
}
