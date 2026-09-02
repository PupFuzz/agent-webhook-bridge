<?php

namespace App\Bridge\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RECORD A FAULT WITHOUT RE-RAISING IT — the one thing every after-response shell in this
 * app needs and each of them used to open-code (card#8425 / DL-325).
 *
 * ⛔ THE DEFECT THIS CLASS EXISTS TO END. Three shells — `App\Bridge\Retention\RetentionGate`,
 * `App\Bridge\Standup\StandupGate` and `App\Bridge\Scheduling\JobScheduler` — each promised
 * *"nothing escapes"* and each recorded the fault by writing to the CACHE, unguarded, as the
 * first statement of its own catch arm. When the CACHE is the fault the arm re-raised: no log
 * line, no marker, and the throw escaped the shell that exists to contain it — an unhandled
 * fatal in the FPM worker on every delivery, and a stack trace instead of an exit code on the
 * tick. Every test of the promise broke the DATABASE instead, which leaves the recorder
 * working, so all three passed while all three were broken.
 *
 * ⚑ THE ORDER IS THE CONTRACT, not a style choice. The LOG goes first and the marker second,
 * because the marker is the leg that cannot work when the cache is the fault: recording in
 * the opposite order costs the operator the only surviving record of why their registry
 * stopped. Each leg is guarded separately, so a fault in either still leaves the other.
 *
 * ⚠ A TOTAL RECORDING FAILURE IS SILENT HERE, AND THAT IS THE RIGHT TRADE — but it is not
 * unreported: every caller reports the fault through its own RESULT as well (the pass
 * result's `passFailed()`, which the tick's exit code reads), so the fact survives even
 * when neither surface can hold it. What must never happen is the recording putting the
 * throw back on the path the shell was written to keep clean.
 */
final class FaultMarker
{
    /**
     * Floor on a marker's lifetime, 30 days. A marker must outlive the cadence of the pass
     * that would clear it, or an install with a long interval loses the record before
     * anyone could read it; the floor covers the ordinary cadences and the `+ 1h` covers
     * the long ones.
     */
    public const TTL_FLOOR = 2592000;

    /**
     * @param  string  $key  the cache key this subsystem's last-fault marker lives at
     * @param  int  $cadenceSeconds  seconds between this subsystem's passes — the marker must outlive it
     * @param  array<string, mixed>  $context  extra log context; the exception's own detail is added here
     */
    public static function record(string $key, Throwable $e, int $cadenceSeconds, string $message, array $context = []): void
    {
        self::log($message, $context + [
            'exception' => $e::class,
            'at' => $e->getFile().':'.$e->getLine(),
            'error' => $e->getMessage(),
        ]);

        try {
            Cache::put($key, [
                'at' => now()->toIso8601String(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ], max(self::TTL_FLOOR, $cadenceSeconds + 3600));
        } catch (Throwable) {
            // The store the marker lives in is the very thing that failed. The log line
            // above already carries the fault, and the caller's result still reports it.
        }
    }

    /**
     * Log a fault without re-raising. Public because a caller whose fault has no marker
     * (a tick stamp that could not be written) needs exactly this leg and nothing else.
     *
     * @param  array<string, mixed>  $context
     */
    public static function log(string $message, array $context = []): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // An unwritable log is the same class of fault as an unreachable cache, and
            // re-raising from here would defeat the whole point of this class.
        }
    }
}
