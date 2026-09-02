<?php

namespace App\Bridge\Scheduling;

use App\Bridge\Support\FaultMarker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * When this install last received a tick — DEATH IS THE ALARM (card#8425 / DL-325).
 *
 * ⭐ WHY THIS EXISTS AT ALL. On an install that adopted the tick, one crontab line drives
 * every periodic job, so it is the single point of failure — and the failure mode is
 * SILENCE. `bridge:prune` shipped at DL-012 and was scheduled nowhere: three installs, ~45
 * days, zero prunes, and nothing anywhere said so. A registry with a nicer API and the same
 * blind spot would be that defect with better ergonomics. So the tick announces itself, and
 * a hook can assert on the announcement.
 *
 * ⚑ THE CACHE, NOT THE DATABASE — and the failure direction is the reason. A cache flush
 * loses the stamp, and a lost stamp reads as {@see TickState::Unmeasured}: LOUD on a
 * declared install, silent on an undeclared one. It cannot read as fresh. The store is the
 * same one the DL-199 gates already depend on (file/redis; both the FPM worker writing the
 * event-gated pass marker and the CLI process running `bridge:tick` share it), so this adds
 * no new infrastructure dependency. A DB column would survive a flush and buy a write on
 * every tick plus a schema for one timestamp.
 *
 * ⚠ IT IS STAMPED BY `bridge:tick` AND BY NOTHING ELSE. The event-gated pass deliberately
 * does NOT stamp it: the question this answers is *"is the clock alive?"*, and a busy
 * install stamping it from traffic would answer *"is anything arriving?"* — a different
 * question, answered in a way that reads as the first one. That is the whole class of
 * defect DL-306 refused for the digest ("a field the bridge cannot source is ABSENT").
 */
final class TickRecord
{
    public const KEY = 'bridge:jobs:last-tick';

    /**
     * 30 days. Long enough that an install which stopped ticking a fortnight ago still has
     * an ANSWER (`stale`, with an age) rather than degrading to `unmeasured` and losing the
     * evidence at exactly the point it matters most.
     */
    private const TTL = 2592000;

    /**
     * ⛔ A STAMP THAT CANNOT BE WRITTEN IS UNMEASURED, NEVER A THROW. This is called first
     * and unconditionally by `bridge:tick`, so an unguarded write made a dead cache store
     * kill the command before its pass ever ran — a stack trace and an accidental exit 1
     * where the contract promises a summary line and a reported pass fault. The failure
     * direction this class is built on (absence reads as unmeasured, never as fresh) is
     * only true if the write can fail quietly.
     */
    public static function stamp(): void
    {
        try {
            Cache::put(self::KEY, now()->toIso8601String(), self::TTL);
        } catch (Throwable $e) {
            FaultMarker::log('the tick stamp could not be written; this tick will read as unmeasured', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function lastAt(): ?Carbon
    {
        try {
            $raw = Cache::get(self::KEY);

            // A stamp we cannot read — unparseable, or in a store that is gone — is not a
            // tick we can date. Unmeasured, never fresh, and never an exception thrown at
            // a caller whose whole job is to REPORT the tick's state.
            return is_string($raw) ? Carbon::parse($raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The resolved posture, against this install's declared horizon (or the absence of one).
     */
    public static function posture(): TickPosture
    {
        return TickPosture::resolve(self::lastAt(), self::declaredHorizon());
    }

    /**
     * The declared horizon in seconds, or null when this install declared none.
     *
     * ⚠ The config key is deliberately NOT cast in `config/bridge.php` — `(int) null` is
     * `0`, which would read back as "declared, zero seconds" and judge an install against a
     * horizon it never set. The parse therefore lives here, once, and
     * {@see self::declarationProblem()} is its other half.
     */
    public static function declaredHorizon(): ?int
    {
        $declared = config('bridge.jobs.tick_expected_every');

        if (! is_numeric($declared)) {
            return null;
        }

        $seconds = (int) $declared;

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Why this install's tick declaration cannot be read, or null when there is nothing
     * wrong with it (including the ordinary case of no declaration at all).
     *
     * ⛔ WITHOUT THIS THE ALARM FAILS SILENTLY IN THE ONE DIRECTION THAT MATTERS. A
     * fat-fingered `BRIDGE_JOBS_TICK_EXPECTED_EVERY=ten` is not numeric, so the posture
     * reads "not adopted" — indistinguishable from an install that never wanted the tick —
     * and the operator who thought they had armed death-is-the-alarm has armed nothing and
     * is told nothing. An unreadable declaration is a DIFFERENT state from an absent one,
     * and `bridge:check` reports it.
     */
    public static function declarationProblem(): ?string
    {
        $declared = config('bridge.jobs.tick_expected_every');

        if ($declared === null || $declared === '') {
            return null;
        }

        if (! is_numeric($declared)) {
            return 'BRIDGE_JOBS_TICK_EXPECTED_EVERY is set but is not a number of seconds'
                .' — the tick freshness alarm is OFF on this install, and a dead crontab line would go unreported.';
        }

        if ((int) $declared <= 0) {
            return 'BRIDGE_JOBS_TICK_EXPECTED_EVERY is set to '.(int) $declared
                .', which is not an interval — the tick freshness alarm is OFF on this install, and a dead crontab line would go unreported.';
        }

        return null;
    }
}
