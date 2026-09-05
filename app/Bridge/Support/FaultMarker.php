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
 * ⚠ A TOTAL RECORDING FAILURE — both legs down at once — IS SILENT HERE, AND THAT IS THE
 * RIGHT TRADE. Whether the FACT still reaches anyone then is NOT uniform across the PATHS
 * that reach this class, so it is stated per path instead of promised for all of them. Since
 * card#8432 `record()` has exactly ONE caller — {@see AfterResponseGate::recordFault()},
 * which binds the key, the cadence and the message per subsystem — and three paths arrive
 * through it:
 * ⭐ REPORTED — the TICK path: `App\Bridge\Scheduling\JobScheduler::passSafely()` returns a
 * `JobPassResult` carrying the fault, and `bridge:tick` / `bridge:jobs run` read
 * `passFailed()` off that result and exit non-zero, so a crontab line still learns the pass
 * failed with neither log nor marker written.
 * ⛔ UNREPORTED — the two gates and the EVENT ingress: `App\Bridge\Retention\RetentionGate::runSafely()`
 * and `App\Bridge\Standup\StandupGate::runSafely()` record from a `void` terminating callback,
 * which has no result to carry, and `App\Bridge\Scheduling\JobSchedulerGate` calls the same
 * `passSafely()` from a terminating callback that DISCARDS its return value. On those three
 * paths a simultaneous log-and-cache failure leaves no record at all, and that pairing is not
 * exotic: on the default config both legs are files under one directory tree
 * (`LOG_CHANNEL=stack` → `single` writes `storage/logs`, `CACHE_STORE=file` writes
 * `storage/framework/cache/data`), so ONE cause — a full disk, an unwritable `storage/` —
 * takes both.
 * What must never happen either way is the recording putting the throw back on the path the
 * shell was written to keep clean.
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
     * ⚑ THE MESSAGE IS SCRUBBED ONCE, HERE, AND BOTH LEGS GET THE SAME STRING (card#8433).
     * The throw can come from a third-party `App\Bridge\Scheduling\JobHandler`, so its text
     * is data this app did not compose; the marker is DURABLE and `bridge:check` prints it
     * back to an operator. Scrubbing at each leg instead would be two chances for the two
     * records of one fault to disagree. The exception CLASS and the file:line are ours and
     * are left alone.
     *
     * @param  string  $key  the cache key this subsystem's last-fault marker lives at
     * @param  int  $cadenceSeconds  seconds between this subsystem's passes — the marker must outlive it
     * @param  array<string, mixed>  $context  extra log context; the exception's own detail is added here
     */
    public static function record(string $key, Throwable $e, int $cadenceSeconds, string $message, array $context = []): void
    {
        $error = SecretScrubber::text($e->getMessage());

        self::log($message, $context + [
            'exception' => $e::class,
            'at' => $e->getFile().':'.$e->getLine(),
            'error' => $error,
        ]);

        try {
            Cache::put($key, [
                'at' => now()->toIso8601String(),
                'exception' => $e::class,
                'error' => $error,
            ], max(self::TTL_FLOOR, $cadenceSeconds + 3600));
        } catch (Throwable) {
            // The store the marker lives in is the very thing that failed. The log line
            // above already carries the fault; whether anything ELSE carries it is the
            // caller's, not this class's — see the per-caller split in the class docblock.
        }
    }

    /**
     * THE READ HALF OF {@see self::record()} — the standing fault at $key, rendered for one
     * operator line, or `null` when none stands (card#8683).
     *
     * ⚑ IT LIVES BESIDE THE WRITE SITE BECAUSE THE PAYLOAD KEYS ARE THE CONTRACT. `at`,
     * `exception` and `error` are written a few lines above and were re-spelled, with their
     * fallbacks, at each `bridge:check` posture leg that reads a marker — two copies at the
     * hoist, and the standup leg would have been the third. A key renamed at the write site
     * would have degraded every reader to its fallbacks in silence, because a fallback
     * cannot tell a missing key from a marker that never carried one.
     *
     * ⛔ IT DOES NOT CATCH, AND THAT IS THE CUT. The store this reads may itself be the
     * fault, and what a reader does about that is the READER's decision, not this class's:
     * `CheckRunner` deliberately does not isolate, so each posture leg keeps its own local
     * catch and answers with its own `unvalidated` line rather than aborting `bridge:check`.
     * Swallowing here would hand every reader a `null` — indistinguishable from a clean
     * subsystem, which is the vacuous green the markers exist to prevent.
     *
     * ⚠ IT ASSERTS NOTHING ABOUT FRESHNESS. A marker outlives the condition on a since-quieted
     * install (it is cleared by the next successful pass, and otherwise by its TTL), so the
     * `at` is rendered and no staleness verdict is derived from it. A reader that wants one
     * must state the rule it is applying; none does today.
     */
    public static function lastFault(string $key): ?string
    {
        $marker = Cache::get($key);

        if (! is_array($marker)) {
            return null;
        }

        return ($marker['exception'] ?? 'error').': '.($marker['error'] ?? '')
            .' at '.($marker['at'] ?? '?');
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
