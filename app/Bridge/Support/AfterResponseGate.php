<?php

namespace App\Bridge\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * THE AFTER-RESPONSE GATE SHAPE, ONCE (card#8432). DL-199 introduced it for retention,
 * DL-306 copied it for the standup digest and DL-325 copied it a third time for the
 * periodic-job registry — each copy a defensible local call, and the class that let the
 * shape multiply is the duplication itself.
 *
 * ⛔ WHAT THE THREE COPIES COST, stated because it is the whole reason this class exists:
 * the lock TTL, the marker-before-work ordering, the non-blocking acquire and the fault
 * recording were restated by hand in each, so a defect fixed in ONE copy silently survived
 * in the other two — which is exactly what happened to the catch arm that DL-325 had to
 * repair in three places at once ({@see FaultMarker}). The next periodic subsystem gets
 * these rules by construction instead of by copying them correctly.
 *
 * ⚑ THE THREE PROPERTIES IT OWNS, each load-bearing rather than a nicety:
 *  - **NON-BLOCKING LOCK.** A concurrent receive that loses the lock skips INSTANTLY.
 *    ⛔ A BLOCKING LOCK HERE IS FORBIDDEN: it would queue every concurrent receive behind
 *    whatever the winner is doing, which is precisely the DL-001 latency regression the
 *    after-response design exists to avoid. Never give this one a wait.
 *  - **THE DUE-CHECK LIVES INSIDE THE LOCK, AND ONLY THERE.** A cheaper check before the
 *    lock would be an optimization that buys a race: two receives can both read "due"
 *    before either acquires, and the loser then does a second pass the moment the winner
 *    releases. One check under the lock has no such window.
 *  - **THE MARKER IS ARMED BEFORE THE WORK, NEVER AFTER.** A pass that throws must back off
 *    a full interval rather than retry on every subsequent delivery, and that is only true
 *    if the marker is already written when the work starts. Callers cannot get this wrong
 *    any more: {@see self::oncePerInterval()} writes it, and a caller that wants a LONGER
 *    back-off (a misconfigured posture that must not warn per delivery) extends it with
 *    {@see self::backOff()} rather than choosing when to arm it.
 *
 * ⚠ WHAT IT DELIBERATELY DOES NOT OWN, because the three subsystems genuinely differ there
 * and a primitive with a branch per caller would be the wrong cut:
 *  - **The `enabled` predicate and the terminating-callback registration.** Each subsystem
 *    resolves its own posture (`config('bridge.retention.enabled')`, `JobsConfig`), and what
 *    is left after that is one `Application::terminating()` call — nothing to hoist.
 *  - **What a pass DOES, and what it returns.** The work is a closure; its value is passed
 *    straight back.
 *  - **When the fault marker is cleared.** All three clear it only after work that actually
 *    completed, but they reach that point differently (inside the pass for the two gates, off
 *    `JobPassResult::didRun()` for the scheduler), so the key and the operation live here
 *    ({@see self::clearFault()}) and the moment stays with the caller.
 *
 * ⚑ IT COMPOSES WITH {@see FaultMarker} RATHER THAN RESTATING IT. That class already owns
 * the one thing every catch arm here needs — log first, marker second, each leg guarded, so
 * recording a fault cannot re-raise it — and hoisting a second overlapping recorder would be
 * this class's own defect. What this adds is that the key, the cadence and the message are
 * bound ONCE per subsystem instead of being spelled out at each call.
 */
final class AfterResponseGate
{
    /**
     * Ceiling on how long one bounded pass may hold the lock before it is presumed dead and
     * released. Only relevant if a worker is killed mid-pass; a pass that takes anywhere
     * near this is already pathological.
     */
    public const LOCK_TTL = 300;

    /**
     * @param  string  $lockKey  the cache key the non-blocking pass lock lives at
     * @param  string  $markerKey  the cache key the interval marker lives at
     * @param  string  $errorKey  the cache key this subsystem's last-fault marker lives at
     * @param  string  $faultMessage  the log message a recorded fault carries
     * @param  Closure(): int  $cadenceSeconds  resolved at FAULT time, not at construction: the
     *                                          config may have changed, and a marker must outlive
     *                                          the cadence of the pass that would clear it
     */
    public function __construct(
        private readonly string $lockKey,
        private readonly string $markerKey,
        private readonly string $errorKey,
        private readonly string $faultMessage,
        private readonly Closure $cadenceSeconds,
    ) {}

    /**
     * Run $work at most once per $interval seconds, under the non-blocking lock.
     *
     * Returns $work's own value when it ran, or a {@see GateSkip} saying why it did not. A
     * caller with nothing to decide may ignore the return entirely; one that reports its
     * outcome (the periodic-job registry) discriminates on the enum.
     *
     * ⚑ THE LOCK IS TAKEN EXPLICITLY RATHER THAN THROUGH `Lock::get(Closure)`, which is the
     * same acquire-then-try/finally-release, because the closure form reports a lost lock as
     * the boolean FALSE — indistinguishable from a work closure that legitimately returned
     * false. The scheduler open-coded an `instanceof` guard around that hazard; here it
     * cannot arise, so no caller has to know about it.
     *
     * ⚠ THROWS, and that is deliberate: the fault has to reach the caller's shell — either
     * {@see self::neverThrows()} or, where a typed result must be synthesised, the caller's
     * own catch arm recording through {@see self::recordFault()}. Swallowing anything here
     * would put a fault where nothing reports it. That includes a cache backend so dead that
     * acquiring the lock is itself the fault.
     */
    public function oncePerInterval(int $interval, Closure $work): mixed
    {
        $lock = Cache::lock($this->lockKey, self::LOCK_TTL);

        if (! $lock->get()) {
            return GateSkip::Locked;
        }

        try {
            if (Cache::has($this->markerKey)) {
                return GateSkip::TooSoon;
            }

            $this->backOff($interval);

            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * Run $pass and record — never re-raise — anything that escapes it.
     *
     * This is what a terminating callback registers. `Application::terminate()` has no
     * try/catch of its own, so a throw escaping here is an unhandled fatal AFTER the
     * response, in the FPM worker nobody watches, on every delivery. Periodic work is never
     * worth a failed webhook either: a 5xx makes the provider redeliver, which under load
     * makes whatever broke worse.
     *
     * @param  Closure  $pass  the pass to run; its return value, if any, is discarded
     * @param  array<string, mixed>  $context  extra log context for a fault
     */
    public function neverThrows(Closure $pass, array $context = []): void
    {
        try {
            $pass();
        } catch (Throwable $e) {
            $this->recordFault($e, $context);
        }
    }

    /**
     * Record a fault against this subsystem's error key, without re-raising it.
     *
     * Public because a caller that must synthesise a typed RESULT on its fault arm — the
     * periodic-job registry, whose `bridge:tick` exit code is read off that result — cannot
     * hand its catch arm to {@see self::neverThrows()} and needs this leg alone.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordFault(Throwable $e, array $context = []): void
    {
        FaultMarker::record($this->errorKey, $e, ($this->cadenceSeconds)(), $this->faultMessage, $context);
    }

    /**
     * Suppress further passes for $seconds. {@see self::oncePerInterval()} already calls this
     * with the ordinary cadence before the work; a caller calls it again only to EXTEND the
     * back-off — a misconfigured posture that must not turn every delivery into a log line.
     */
    public function backOff(int $seconds): void
    {
        Cache::put($this->markerKey, true, $seconds);
    }

    /**
     * Re-open the interval so the NEXT delivery works instead of waiting it out — what a
     * bounded pass that filled its batch does, since there is known backlog left to drain
     * and each pass is still individually bounded.
     */
    public function reopen(): void
    {
        Cache::forget($this->markerKey);
    }

    /**
     * Erase a standing fault: the pass completed, so whatever the preflight was surfacing is
     * no longer true.
     *
     * ⛔ ONLY A PASS THAT ACTUALLY DID THE WORK MAY CALL THIS. On a busy install the
     * overwhelmingly common outcome is an ordinary skip within seconds of a failure, so
     * clearing on any non-throwing return would erase the fault before anyone could read it
     * and leave `bridge:check`'s error leg dead — DL-012's silent inertness rebuilt inside
     * the alarm that exists to prevent it.
     */
    public function clearFault(): void
    {
        Cache::forget($this->errorKey);
    }
}
