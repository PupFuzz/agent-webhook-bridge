<?php

namespace App\Console\Commands\Bridge;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

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
     * @param  \Closure(): int  $body
     */
    protected function guardDatabase(\Closure $body): int
    {
        try {
            return $body();
        } catch (QueryException $e) {
            $this->error('database unreachable — check DB_HOST / DB_DATABASE / credentials in .env ('.$e->getMessage().')');

            return self::FAILURE;
        }
    }
}
