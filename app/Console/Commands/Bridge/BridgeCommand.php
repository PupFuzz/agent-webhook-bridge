<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\KeyboardProbe;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Base for the bridge:* commands. Holds small shared helpers so each command
 * doesn't re-implement them: the "non-empty string option or null" coercion
 * (e.g. --agent) and the DB-failure guard.
 */
abstract class BridgeCommand extends Command
{
    /**
     * Is there a keyboard to ask a question ON — the shared predicate for every
     * `bridge:*` command that gates a mutation behind a confirmation.
     *
     * ⛔ IT IS NOT `$this->input->isInteractive()`, AND THE DIFFERENCE IS A HANG.
     * {@see App\Bridge\Support\KeyboardProbe} owns the measurement and the reason;
     * this method exists so the next command that needs it finds one predicate rather
     * than minting a second (canon #5).
     *
     * ⚠ `bridge:jobs install-tick` HAS the second copy already — same class, wrong
     * predicate, and its message says "no TTY" while testing interactivity. It is
     * deliberately NOT migrated here: changing what an already-shipped command refuses
     * is an acceptance change, and that is operator-gated (card#9141 fix round; the
     * migration is filed as its own item).
     */
    protected function hasKeyboard(): bool
    {
        return $this->laravel->make(KeyboardProbe::class)->hasKeyboard();
    }

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
     * ⚑ `$diagnostics` EXISTS FOR THE COMMANDS THAT OWN A STDOUT DOCUMENT. `$this->error()`
     * writes to STDOUT, which is correct for a command whose stdout is prose and wrong for
     * one whose stdout is a single JSON document a hook parses — there, this message would
     * append a human sentence to the document and the consumer would fail to decode it at
     * exactly the moment the fault fired. Such a caller passes its own error stream; the
     * message text is the same either way, so the two cannot drift.
     *
     * @param  \Closure(): int  $body
     */
    protected function guardDatabase(\Closure $body, ?OutputInterface $diagnostics = null): int
    {
        try {
            return $body();
        } catch (QueryException $e) {
            $message = $this->databaseAnswers($e->getConnectionName())
                ? 'database query failed, but the server ANSWERED — this is not connectivity. An install '
                    .'that has not run `php artisan migrate` is the usual cause ('.$e->getMessage().')'
                : 'database unreachable — check DB_HOST / DB_DATABASE / credentials in .env ('.$e->getMessage().')';

            if ($diagnostics === null) {
                $this->error($message);
            } else {
                $diagnostics->writeln('<error>'.$message.'</error>');
            }

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
