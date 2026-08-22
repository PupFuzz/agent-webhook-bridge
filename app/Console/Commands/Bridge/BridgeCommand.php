<?php

namespace App\Console\Commands\Bridge;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base for the bridge:* commands. Holds small shared helpers so each command
 * doesn't re-implement them: the "non-empty string option or null" coercion
 * (e.g. --agent) and the DB-failure guard.
 */
abstract class BridgeCommand extends Command
{
    /**
     * A console option coerced to a non-empty string, or null when absent/blank.
     */
    protected function strOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Run a DB-touching command body, turning an unreachable/misconfigured
     * database into a clear one-line message + FAILURE rather than an uncaught
     * QueryException stack trace (the exit code is non-zero either way). Mirrors
     * CheckCommand's clean "database: …" handling for the read/maintenance
     * commands. Non-DB exceptions propagate unchanged.
     *
     * ⛔ THE CAUSE IS MEASURED, NOT ASSUMED. Every `QueryException` used to be reported as
     * *"database unreachable — check DB_HOST / DB_DATABASE / credentials"*, which sends an
     * operator to the one place that is demonstrably fine whenever the server answered and
     * the SCHEMA is what is wrong — the reachable case, and the one an install that upgraded
     * without running `php artisan migrate` lands in. A wrong-but-specific cause is worse
     * than an honest generic one, so the branch is decided by asking the server, not by
     * pattern-matching a driver-specific message (`no such table` is `HY000` on SQLite and
     * `42S02` on MariaDB — a sniff would have been right on one CI leg and wrong on the other).
     *
     * @param  \Closure(): int  $body
     */
    protected function guardDatabase(\Closure $body): int
    {
        try {
            return $body();
        } catch (QueryException $e) {
            $this->error($this->databaseAnswers($e->getConnectionName())
                ? 'database query failed, but the server ANSWERED — this is not connectivity. An install '
                    .'that has not run `php artisan migrate` is the usual cause ('.$e->getMessage().')'
                : 'database unreachable — check DB_HOST / DB_DATABASE / credentials in .env ('.$e->getMessage().')');

            return self::FAILURE;
        }
    }

    /** Whether the connection the failed query ran on answers a trivial one at all. */
    private function databaseAnswers(string $connection): bool
    {
        try {
            DB::connection($connection)->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
