<?php

namespace App\Bridge\Scheduling;

use App\Bridge\Retention\RetentionConfig;

/**
 * The resolved, validated `bridge.jobs.*` posture (card#8425 / DL-325).
 *
 * ONE home for the rules, and the same shape as {@see RetentionConfig}
 * for the same reason: there are two readers — {@see JobScheduler}, which acts on them, and
 * `bridge:check`'s `jobs.posture` leg, which reports them. A checker deriving its own copy is
 * how a preflight ends up cheerfully describing a cadence the scheduler is not running.
 *
 * ⛔ A BAD VALUE IS REFUSED, NOT CLAMPED, and that is what this class exists for. Both knobs
 * used to be read as `max(1, (int) config(...))` at the point of use, so
 * `BRIDGE_JOBS_MIN_PASS_INTERVAL=sixty` cast to `0` and floored to **1** — turning an
 * operator's intended 60-second floor into a pass per SECOND on every delivery, silently, on
 * the one path DL-001's latency bet is spent. Clamping picks a number the operator never
 * asked for and then hides that it did. Refusing runs nothing and says why, in the preflight
 * and in the pass's own summary line — the direction retention already fails in (an
 * unparseable window prunes NOTHING rather than falling back to a default cutoff).
 *
 * ⚑ THE TYPO IS ALREADY A ZERO BY THE TIME IT ARRIVES. `config/bridge.php` casts with
 * `(int) env(...)`, so `sixty` is `0` here and the raw string is unrecoverable — which is
 * exactly why the predicate is `< 1` and the message quotes the resolved value: the same
 * bound `RetentionConfig` puts on `retention.interval`, for the same reason.
 */
final class JobsConfig
{
    /**
     * The largest `max_per_pass` that is still a bound. `(int)` on a 20-digit string
     * saturates to PHP_INT_MAX, which would make one after-response callback try to run the
     * whole registry inside an FPM worker — the exact thing the bound exists to stop.
     */
    public const MAX_PER_PASS_CEILING = 1000;

    private function __construct(
        public readonly bool $enabled,
        public readonly int $minPassInterval,
        public readonly int $maxPerPass,
        /** Why this install can run no pass at all, in operator vocabulary; null when usable. */
        public readonly ?string $problem,
    ) {}

    public static function fromConfig(): self
    {
        $minPassInterval = (int) config('bridge.jobs.min_pass_interval', 60);
        $maxPerPass = (int) config('bridge.jobs.max_per_pass', 3);

        return new self(
            enabled: (bool) config('bridge.jobs.enabled', true),
            minPassInterval: $minPassInterval,
            maxPerPass: $maxPerPass,
            problem: self::problemWith($minPassInterval, $maxPerPass),
        );
    }

    public function isUsable(): bool
    {
        return $this->problem === null;
    }

    private static function problemWith(int $minPassInterval, int $maxPerPass): ?string
    {
        if ($minPassInterval < 1) {
            return "jobs.min_pass_interval must be a positive number of seconds (BRIDGE_JOBS_MIN_PASS_INTERVAL), got {$minPassInterval}"
                .' — a non-numeric value reads as 0 here';
        }
        if ($maxPerPass < 1 || $maxPerPass > self::MAX_PER_PASS_CEILING) {
            return sprintf(
                'jobs.max_per_pass must be an instance count between 1 and %d (BRIDGE_JOBS_MAX_PER_PASS), got %d'
                    .' — a non-numeric value reads as 0 here',
                self::MAX_PER_PASS_CEILING,
                $maxPerPass,
            );
        }

        return null;
    }
}
