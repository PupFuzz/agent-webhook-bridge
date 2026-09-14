<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Now" as the DATABASE's clock reads it — the clock that fills every `useCurrent()` column,
 * `webhook_events.received_at` first among them.
 *
 * ⛔ WHY A SECOND "NOW" EXISTS AT ALL. An age computed as `now() − received_at` subtracts the
 * DB host's clock from the app host's. They are usually one box and one clock, and nothing in
 * this app establishes that: DL-346 pinned the connection's time ZONE, never its clock. A
 * caller that needs an age of a DB-stamped instant to be skew-free reads "now" here, so both
 * operands come off one clock.
 *
 * ⚑ ONE QUERY PER DRIVER, AND AN UNKNOWN DRIVER THROWS. `CURRENT_TIMESTAMP(3)` is a syntax
 * error on SQLite, the default connection and the test suite's; SQLite's `strftime('%f')` is
 * unknown to MariaDB. A guessed fallback would hand back a value on a clock nobody named.
 * Both queries return UTC: SQLite's `'now'` is UTC by definition, and the MySQL-family
 * session zone is pinned to `+00:00` by `config/database.php` (DL-346).
 *
 * ⚠ RESOLUTION IS NOT PRECISION. This read carries milliseconds on both drivers, but a
 * `useCurrent()` column on SQLite is filled at ONE-SECOND resolution, so an age against such a
 * column is only good to a second there. Callers budget that quantum rather than trusting the
 * fractional part of the other operand.
 */
final class DbClock
{
    public static function now(?string $connection = null): CarbonImmutable
    {
        $conn = DB::connection($connection);
        $driver = $conn->getDriverName();

        $sql = match ($driver) {
            'sqlite' => "select strftime('%Y-%m-%d %H:%M:%f', 'now') as now",
            'mysql', 'mariadb' => 'select CURRENT_TIMESTAMP(3) as now',
            default => throw new ConfigException("DbClock: no clock query for database driver '{$driver}'"),
        };

        $raw = $conn->selectOne($sql)->now ?? null;
        if (! is_string($raw) || $raw === '') {
            throw new ConfigException("DbClock: the {$driver} clock query returned no timestamp");
        }

        return CarbonImmutable::parse($raw, 'UTC');
    }
}
