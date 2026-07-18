<?php

namespace App\Bridge\Retention;

/**
 * The resolved, validated `bridge.retention.*` posture (DL-199).
 *
 * ONE home for the rules, because there are two readers — the gate that acts on
 * them and `bridge:check` that reports them. A checker that re-derives its own
 * copy is the divergent-copy defect the DL-199 service extraction exists to
 * prevent, and the failure would be the worst kind: a preflight that cheerfully
 * reports a posture the receiver isn't running.
 *
 * Windows are parsed by {@see RetentionService::parseDays()} — the same guard
 * `--older-than` uses — so a config window and a CLI window cannot diverge.
 */
final class RetentionConfig
{
    private function __construct(
        public readonly bool $enabled,
        public readonly int $interval,
        public readonly int $batch,
        public readonly ?int $olderThanDays,
        public readonly ?int $nullPayloadsOlderThanDays,
        /** Why this config prunes nothing, in operator vocabulary; null when usable. */
        public readonly ?string $problem,
    ) {}

    /**
     * The largest `batch` that is still a bound. Same rationale as MAX_DAYS: a
     * fat-fingered value must not silently become no-bound-at-all. `(int)` on a
     * 20-digit string saturates to PHP_INT_MAX, which would make one pass delete
     * the entire backlog on an FPM worker — the exact thing `batch` exists to stop.
     */
    public const MAX_BATCH = 100000;

    public static function fromConfig(): self
    {
        $interval = (int) config('bridge.retention.interval');
        $batch = (int) config('bridge.retention.batch');
        $olderRaw = self::rawWindow('older_than');
        $nullRaw = self::rawWindow('null_payloads_older_than');

        $older = is_string($olderRaw) && $olderRaw !== '' ? RetentionService::parseDays($olderRaw) : null;
        $nullOlder = is_string($nullRaw) && $nullRaw !== '' ? RetentionService::parseDays($nullRaw) : null;

        return new self(
            enabled: (bool) config('bridge.retention.enabled'),
            interval: $interval,
            batch: $batch,
            olderThanDays: $older,
            nullPayloadsOlderThanDays: $nullOlder,
            problem: self::problemWith($interval, $batch, $olderRaw, $nullRaw, $older, $nullOlder),
        );
    }

    /**
     * The raw configured window, or FALSE when it is not a string at all.
     *
     * `env()` turns the literal `true` into a bool, and casting that to a string
     * yields `'1'` — a VALID one-day window. So a `BRIDGE_RETENTION_OLDER_THAN=true`
     * typo would quietly delete everything older than a day instead of 30, and the
     * preflight would report it as healthy. A non-string is a type error, not a
     * value to coerce.
     */
    private static function rawWindow(string $key): string|false
    {
        $v = config("bridge.retention.{$key}");

        return is_string($v) ? trim($v) : ($v === null ? '' : false);
    }

    public function isUsable(): bool
    {
        return $this->problem === null;
    }

    /**
     * An unparseable window prunes NOTHING rather than falling back to a default
     * cutoff — the one direction a destructive path must never fail is "deleted
     * more than asked because the value was a typo".
     */
    private static function problemWith(
        int $interval,
        int $batch,
        string|false $olderRaw,
        string|false $nullRaw,
        ?int $older,
        ?int $nullOlder,
    ): ?string {
        $bounds = sprintf('a number of days between %d and %d (e.g. 30 or 30d)', RetentionService::MIN_DAYS, RetentionService::MAX_DAYS);

        foreach (['older_than' => $olderRaw, 'null_payloads_older_than' => $nullRaw] as $key => $raw) {
            if ($raw === false) {
                return "retention.{$key} must be a quoted string like \"30d\" — a bare true/false in .env is read as a boolean, not a window";
            }
        }
        if ($olderRaw !== '' && $older === null) {
            return "retention.older_than must be {$bounds}, got '{$olderRaw}'";
        }
        if ($nullRaw !== '' && $nullOlder === null) {
            return "retention.null_payloads_older_than must be {$bounds}, got '{$nullRaw}'";
        }
        if ($older === null && $nullOlder === null) {
            return 'retention is enabled but neither retention.older_than nor retention.null_payloads_older_than is set';
        }
        if ($interval < 1) {
            return "retention.interval must be a positive number of seconds, got {$interval}";
        }
        if ($batch < 1 || $batch > self::MAX_BATCH) {
            return sprintf('retention.batch must be a row count between 1 and %d, got %d', self::MAX_BATCH, $batch);
        }

        return null;
    }

    /** One-line operator summary of what this install will actually do. */
    public function summary(): string
    {
        $legs = [];
        if ($this->olderThanDays !== null) {
            $legs[] = "delete >{$this->olderThanDays}d";
        }
        if ($this->nullPayloadsOlderThanDays !== null) {
            $legs[] = "null payloads >{$this->nullPayloadsOlderThanDays}d";
        }

        return sprintf('%s, every %ds, %d rows/pass', implode(' + ', $legs), $this->interval, $this->batch);
    }
}
