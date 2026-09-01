<?php

namespace App\Bridge\Scheduling;

use App\Models\ScheduledJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One bounded pass over the due entries in the periodic-job registry (card#8425 / DL-325).
 *
 * ⭐ ONE PASS, TWO INGRESSES. This is the whole point of the design and the reason the
 * class takes a {@see JobPassSource} rather than living inside either caller:
 *  - {@see JobSchedulerGate} drives it from the inbound webhook's after-response callback,
 *    which is DL-199's shape. An install that never adds a crontab line behaves exactly as
 *    it does today, and the registry still runs.
 *  - `bridge:tick` drives it from ONE crontab line. That is what closes DL-306's documented
 *    dead end — *"the pass fires on the first inbound webhook AFTER the interval lapses, so
 *    an install receiving nothing pushes nothing"* — because a silent install has no
 *    arrival to gate on and no event gate can invent one.
 * Adopting the tick is per-install and opt-in; the two ingresses cover each other's blind
 * spot rather than duplicating each other (busy install / silent install).
 *
 * ⛔ THE FOUR DL-199 PROPERTIES ARE INHERITED VERBATIM, and each is a way to re-create the
 * DL-001 latency regression if dropped:
 *  - **AFTER-RESPONSE** on the event ingress — a terminating callback, so the client's 200
 *    is on the wire before any handler runs. Job execution is never on a client-visible path.
 *  - **BOUNDED** — at most `bridge.jobs.max_per_pass` instances per pass, oldest-due first.
 *    A registry with 50 due jobs drains across passes; it never becomes one long request.
 *  - **NON-BLOCKING `Cache::lock`** — a concurrent receive that loses skips INSTANTLY. ⛔ A
 *    BLOCKING LOCK HERE IS FORBIDDEN: it would queue every concurrent receive behind
 *    whatever job is running, which is precisely the DL-001 regression.
 *  - **NEVER THROWS PAST THE PASS** — {@see self::passSafely()}. A job failure must not fail
 *    a webhook (a 5xx makes the provider redeliver, compounding whatever broke) and must not
 *    fail the tick (a crontab line that exits non-zero on a handler bug mails the operator
 *    about the wrong thing while the registry keeps the actual record). ⚑ Swallowing the
 *    THROW is not swallowing the FACT: the result says whether the pass ran, and
 *    {@see JobPassResult::passFailed()} says whether it did not run because something is
 *    broken, which is what `bridge:tick`'s exit code is decided by.
 *
 * ⚑ THE MINIMUM PASS INTERVAL IS SHARED BY BOTH INGRESSES, on purpose. The event gate is
 * evaluated on EVERY delivery, so without it a busy install would query the registry once
 * per webhook. One marker, one rule: at most one pass per `bridge.jobs.min_pass_interval`
 * however it was driven. With the default 60s and a 5/10/15-minute tick this never costs a
 * tick a pass; an operator running a per-minute crontab line gets one pass per minute,
 * which is what they asked for.
 *
 * ⚑ EACH JOB IS CAUGHT INDIVIDUALLY, inside the loop. One handler throwing must not cost
 * the other due jobs their pass — the same per-agent isolation DL-001 treatment (C) applies
 * to handlers.
 *
 * ⚑ `next_due_at` IS ADVANCED BEFORE THE HANDLER RUNS, not after it succeeds. A handler
 * that throws every time therefore retries once per its own interval instead of on every
 * delivery — DL-199's marker-before-work rule. The cost is stated rather than discovered: a
 * pass killed mid-handler (worker OOM) has already given up its slot for one interval.
 */
final class JobScheduler
{
    private const LOCK_KEY = 'bridge:jobs:lock';

    private const MARKER_KEY = 'bridge:jobs:last-pass';

    /**
     * A pass that threw OUTSIDE any single job records itself here so `bridge:check` can
     * surface it. Public because the check reads it and the key must have exactly one home
     * (canon #5). Presence ⇒ the last attempted pass failed as a whole — an unreachable DB,
     * an unwritable cache — and nothing in the registry has run since. A clean pass forgets
     * it. Without this a persistently-throwing pass leaves only a log line nobody tails
     * while the registry still enumerates as healthy: DL-012's silent inertness, rebuilt.
     */
    public const ERROR_KEY = 'bridge:jobs:last-error';

    /** Floor on the last-error marker's lifetime; a longer pass interval widens it. */
    private const ERROR_TTL = 2592000; // 30 days

    /**
     * Ceiling on how long one bounded pass may hold the lock before it is presumed dead and
     * released. Only relevant if a worker is killed mid-pass; a pass approaching this is
     * already pathological (its handlers are not bounded).
     */
    private const LOCK_TTL = 300;

    public function __construct(private readonly JobHandlerRegistry $handlers) {}

    /**
     * Run a pass, swallowing anything that escapes the per-job catches. This is what BOTH
     * ingresses call: neither a webhook response nor a crontab exit code is an appropriate
     * place to learn that the registry had a bad day.
     */
    public function passSafely(JobPassSource $source): JobPassResult
    {
        try {
            $result = $this->pass($source);

            // ⛔ ONLY A PASS THAT ACTUALLY RAN MAY CLEAR THE MARKER. Forgetting it on any
            // non-throwing return erases the last failure the moment ANY later call
            // returns — and on a busy install the overwhelmingly common return is an
            // ordinary skip (SKIP_TOO_SOON on every delivery inside the interval,
            // SKIP_LOCKED on every concurrent one), which happens within seconds of the
            // failure. The marker would then be gone before anyone read it and
            // `bridge:check`'s error leg would be dead code. Same rule, same reason, as
            // RetentionGate/StandupGate, which forget it only after the work completed.
            if ($result->didRun()) {
                Cache::forget(self::ERROR_KEY);
            }

            return $result;
        } catch (Throwable $e) {
            Cache::put(self::ERROR_KEY, [
                'at' => now()->toIso8601String(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ], max(self::ERROR_TTL, self::minPassInterval() + 3600));
            Log::warning('scheduled job pass failed', [
                'source' => $source->value,
                'exception' => $e::class,
                'at' => $e->getFile().':'.$e->getLine(),
                'error' => $e->getMessage(),
            ]);

            return JobPassResult::failed($source, 'the pass itself failed: '.$e->getMessage());
        }
    }

    /**
     * The pass proper. Throws on anything that is not one job's fault — the DB being
     * unreachable, the cache backend being gone — so {@see self::passSafely()} can record
     * it as a PASS failure rather than mis-attributing it to whichever job was next.
     */
    public function pass(JobPassSource $source): JobPassResult
    {
        $cfg = JobsConfig::fromConfig();

        // ONE home for the enabled predicate (canon #5): the gate asks it to decide whether
        // to register a callback at all, and this asks it because the tick and a hand-run
        // pass never go through the gate.
        if (! $cfg->enabled) {
            return JobPassResult::skipped($source, JobPassResult::SKIP_DISABLED);
        }

        // A cadence this install cannot act on is REFUSED, not clamped — see JobsConfig.
        // It is a FAULT and not an ordinary skip: nothing in the registry will run until
        // somebody fixes the value, so `bridge:tick` exits non-zero on it and the
        // `jobs.posture` leg reports it at preflight.
        if (! $cfg->isUsable()) {
            Log::warning('the periodic-job registry is enabled but misconfigured; no pass ran', [
                'source' => $source->value,
                'problem' => $cfg->problem,
            ]);

            return JobPassResult::failed($source, 'the registry is MISCONFIGURED and no pass can run: '.$cfg->problem);
        }

        // The due-check lives INSIDE the lock, and only there. A cheaper check before the
        // lock would be an optimization that buys a race: two receives can both read "due"
        // before either acquires, and the loser then runs a second pass the moment the
        // winner releases. One check under the lock has no such window (DL-199).
        //
        // `Lock::get(Closure)` returns FALSE when the lock was not acquired and the
        // closure's own return when it was — so the returned TYPE is the discriminator,
        // and no separate "did we acquire" flag (whose disagreement with the result would
        // be an unreachable branch to guard) is needed.
        $outcome = Cache::lock(self::LOCK_KEY, self::LOCK_TTL)->get(function () use ($source, $cfg): JobPassResult {
            if (Cache::has(self::MARKER_KEY)) {
                return JobPassResult::skipped($source, JobPassResult::SKIP_TOO_SOON);
            }

            // Marked BEFORE the work, so a pass that throws backs off a full interval
            // instead of retrying on every subsequent delivery.
            Cache::put(self::MARKER_KEY, true, $cfg->minPassInterval);

            return $this->runDue($source, $cfg->maxPerPass);
        });

        return $outcome instanceof JobPassResult
            ? $outcome
            : JobPassResult::skipped($source, JobPassResult::SKIP_LOCKED);
    }

    private function runDue(JobPassSource $source, int $maxPerPass): JobPassResult
    {
        $due = ScheduledJob::query()
            ->where('enabled', true)
            ->where(function ($q) {
                $q->whereNull('next_due_at')->orWhere('next_due_at', '<=', now());
            })
            // Oldest-due first, and NULL (never run) first of all: both SQLite and MariaDB
            // sort NULL ahead on ASC, so a just-inserted job runs on the next pass rather
            // than queueing behind every existing one. `id` breaks ties deterministically.
            ->orderBy('next_due_at')
            ->orderBy('id')
            ->limit($maxPerPass)
            ->get();

        $ok = $failed = $refused = 0;
        $names = [];

        foreach ($due as $job) {
            $names[] = (string) $job->name;
            match ($this->runOne($job, $source)) {
                ScheduledJob::STATUS_OK => $ok++,
                ScheduledJob::STATUS_REFUSED => $refused++,
                default => $failed++,
            };
        }

        return JobPassResult::ran($source, $ok, $failed, $refused, $names);
    }

    /**
     * Run one instance and record what happened ON THE ROW. The registry is the durable
     * account of what this install's periodic population actually did; a log line is not,
     * because nobody tails it (DL-012).
     *
     * @return string one of the {@see ScheduledJob} STATUS_* constants
     */
    private function runOne(ScheduledJob $job, JobPassSource $source): string
    {
        $job->last_run_at = now();
        $job->next_due_at = now()->addSeconds((int) $job->interval_s);

        $runnable = $this->handlers->runnable($job->handler);
        if ($runnable instanceof JobRefusal) {
            $refusal = $runnable;
            // LOUD, never a silent skip. Three surfaces, because each is read by a different
            // person at a different time: the row (enumeration + `bridge:check`), the log
            // (an operator already debugging), and the status field (a machine).
            $job->last_status = ScheduledJob::STATUS_REFUSED;
            $job->last_summary = $refusal->reason;
            $job->last_error = $refusal->message;
            $job->last_duration_ms = 0;
            $job->consecutive_failures = (int) $job->consecutive_failures + 1;
            $job->save();

            Log::error('scheduled job REFUSED', [
                'name' => $job->name,
                'handler' => $job->handler,
                'reason' => $refusal->reason,
                'error' => $refusal->message,
            ]);

            return ScheduledJob::STATUS_REFUSED;
        }

        $startedAt = hrtime(true);

        try {
            $outcome = $runnable->run(JobContext::forJob($job, $source));
            $job->last_status = ScheduledJob::STATUS_OK;
            $job->last_summary = mb_substr($outcome->summary, 0, 255);
            $job->last_error = null;
            $job->consecutive_failures = 0;
            $status = ScheduledJob::STATUS_OK;

            Log::info('scheduled job pass', [
                'name' => $job->name,
                'handler' => $job->handler,
                'source' => $source->value,
                'summary' => $outcome->summary,
            ]);
        } catch (Throwable $e) {
            $job->last_status = ScheduledJob::STATUS_FAILED;
            $job->last_summary = mb_substr($e::class, 0, 255);
            $job->last_error = $e->getMessage();
            $job->consecutive_failures = (int) $job->consecutive_failures + 1;
            $status = ScheduledJob::STATUS_FAILED;

            Log::warning('scheduled job FAILED', [
                'name' => $job->name,
                'handler' => $job->handler,
                'source' => $source->value,
                'exception' => $e::class,
                'at' => $e->getFile().':'.$e->getLine(),
                'error' => $e->getMessage(),
                'consecutive_failures' => $job->consecutive_failures,
            ]);
        }

        $job->last_duration_ms = max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000));
        $job->save();

        return $status;
    }

    /**
     * A TTL FLOOR, and its only caller is the catch arm: the last-error marker must outlive
     * the pass cadence, or an install with a long interval loses the marker before the next
     * pass could clear it. ⚑ The `max(1, …)` here is NOT the clamp {@see JobsConfig} exists
     * to refuse — a TTL has to be a positive number of seconds whatever the config says, and
     * this runs in the arm where the config may be the very thing that could not be
     * resolved. Nothing decides a CADENCE from this value.
     */
    private static function minPassInterval(): int
    {
        return max(1, (int) config('bridge.jobs.min_pass_interval', 60));
    }
}
