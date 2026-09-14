<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Expression;
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
 * The value is parsed as UTC. SQLite's `'now'` IS UTC; the MySQL-family read is rendered in
 * the connection's session zone, which `config/database.php` pins to `+00:00` by default
 * (DL-346). Under a different `DB_TIMEZONE` it is off by that offset, and so is every
 * `useCurrent()` column read back through the same connection, so an AGE against one still
 * holds.
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

        $raw = $conn->selectOne('select '.self::sql($driver).' as now')->now ?? null;
        if (! is_string($raw) || $raw === '') {
            throw new ConfigException("DbClock: the {$driver} clock query returned no timestamp");
        }

        return CarbonImmutable::parse($raw, 'UTC');
    }

    /**
     * The same clock as a value to WRITE, evaluated by the database at the write — so a column
     * stamped with it and a later {@see now()} read are on one clock, which a PHP `now()` bound
     * into the query would not be.
     */
    public static function expression(?string $connection = null): Expression
    {
        return DB::raw(self::sql(DB::connection($connection)->getDriverName()));
    }

    /**
     * A literal per driver and never built from input — which is what makes it safe to hand to
     * `DB::raw()`.
     *
     * @return literal-string
     */
    private static function sql(string $driver): string
    {
        return match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d %H:%M:%f', 'now')",
            'mysql', 'mariadb' => 'CURRENT_TIMESTAMP(3)',
            default => throw new ConfigException("DbClock: no clock query for database driver '{$driver}'"),
        };
    }
}
