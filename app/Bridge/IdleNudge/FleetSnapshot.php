<?php

namespace App\Bridge\IdleNudge;

use DateTimeImmutable;
use Exception;

/**
 * One parsed `GET /api/fleet/snapshot` envelope (Mezzanine, `api_version` 1 — rt#479
 * issuecomment-5657433728 and -5657493757).
 *
 * ⚑ SEATS STAY RAW ARRAYS. Every per-seat member is judged by {@see IdleNudgeEvaluator},
 * which reads each one from the seat object it belongs to; flattening them here would be a
 * second place deciding what a malformed member means.
 */
final class FleetSnapshot
{
    /**
     * @param  list<array<mixed>>  $seats  every seat of every install, in wire order
     */
    public function __construct(
        /** `server_time`, epoch milliseconds, on MEZZANINE's clock. */
        public readonly int $serverTimeMs,
        public readonly array $seats,
        /** Wall time the GET took on this host, in seconds (`hrtime`), for the error budget. */
        public readonly float $transitS,
    ) {}

    /**
     * An rfc3339 instant as epoch milliseconds, or null when the value is not one.
     *
     * The pattern is checked BEFORE parsing because PHP's parser accepts far more than rfc3339
     * (`now`, `+1 day`, a bare date) and would turn a malformed member into a confident instant.
     */
    public static function instantMs(mixed $value): ?int
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }

        return $dt->getTimestamp() * 1000 + intdiv((int) $dt->format('u'), 1000);
    }

    /** The canonical spelling an instant is STORED in: UTC, millisecond precision. */
    public static function canonicalInstant(int $epochMs): string
    {
        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($epochMs, 1000), ($epochMs % 1000) * 1000));

        return $dt === false ? '' : $dt->format('Y-m-d\TH:i:s.v\Z');
    }
}
