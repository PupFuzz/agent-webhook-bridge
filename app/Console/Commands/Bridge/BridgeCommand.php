<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\TerminalProbe;
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
     * WILL A HUMAN SEE THIS QUESTION, AND CAN THEY ANSWER IT? That one sentence is the whole
     * of what this predicate decides, and it is what every `bridge:*` command that gates a
     * mutation behind a confirmation must ask before it prepares one. It is not "is there a
     * tty", not "is interaction enabled", and not "is there a keyboard" — each of those is one
     * necessary condition, each has been SHIPPED ALONE IN THIS REPO AS THE WHOLE GATE, and
     * each was then falsified by measurement:
     *
     *  - `isInteractive()` ALONE is not a human. Under a PIPE it stays true and
     *    `QuestionHelper` goes on to read stdin — `printf 'yes\n' |` answered for nobody and
     *    WROTE, and a pipe that never writes blocked the command.
     *  - {@see TerminalProbe::hasKeyboard()} ALONE is not consent. Under `-n` AT A TERMINAL a
     *    keyboard is present and the operator has still said do not interact — a gate reading
     *    only the probe prepared an offer and made bearer-authenticated API calls before
     *    `confirm()` silently took the NO default, and under `-q` it did that with a silent console.
     *  - Both of those together are still only "an answer CAN COME BACK". They say nothing
     *    about whether the question ARRIVES. `Illuminate\Console\OutputStyle` does not
     *    implement `ConsoleOutputInterface`, so `QuestionHelper::ask`'s `getErrorOutput()`
     *    diversion never fires and the question is written to STDOUT (measured, with a raw
     *    `ConsoleOutput` as the positive control that DOES divert to stderr). So under
     *    `php artisan bridge:provision | tee setup.log` at a terminal the gate passed, the
     *    request was made, the question landed in the log file, and the command blocked on the
     *    tty with nothing on screen — indistinguishable from a hang.
     *
     * ⚑ WHY THE INTERACTIVITY TERM IS NOT SPELLED OUT AS A FLAG LIST. `Application::configureIO`
     * clears `isInteractive()` for `--no-interaction`/`-n` AND for any negative shell verbosity
     * — `-q`, `--silent`, or an inherited `SHELL_VERBOSITY<0`. An enumeration of those flags is
     * a restatement, and every DOC restatement this repo has written has drifted from it — the
     * two this card shipped were already narrower than the predicate on the day they were
     * written, each inside the same sentence that claimed not to re-enumerate. So there are
     * exactly TWO copies of the flag list left and both are accounted for: THIS one, which
     * owns the rule, and the operator-facing fallback message in
     * `App\Bridge\Provision\WritebackIdentityOffer`. That second copy is load-bearing —
     * an operator reading a console line cannot follow a `{@see}` — so DELETE-and-point is
     * not available for it and it is GUARDED instead: `Tests\Unit\Docs\InteractivityFlagListGuardTest`
     * re-derives the flag set from `Application::configureIO` ITSELF and reds both on a copy
     * that drops a condition and on a THIRD copy appearing anywhere in the tree. Every other
     * surface states the PROPERTY and points HERE — this run may ask only where a human is at
     * a terminal on both ends and has not said otherwise.
     *
     * ⚠ `bridge:jobs install-tick` HAS a second copy already — same class, and its message
     * says "no TTY" over an `isInteractive()` test (measured live: a piped `yes` installs a
     * crontab line with no human present; a held pipe blocks). It is deliberately NOT
     * migrated here: changing what an already-shipped command refuses is an acceptance
     * change, and that is operator-gated — **card#9255**. ⚠ That migration is also the point
     * at which the screen half's measurement seam starts to matter — `App\Bridge\Support\SystemTerminalProbe`
     * owns why fd 1 and `$this->output` can diverge and what to do about it then.
     */
    protected function canPromptToConfirm(): bool
    {
        $terminal = $this->laravel->make(TerminalProbe::class);

        return $this->input->isInteractive()
            && $terminal->hasKeyboard()
            && $terminal->hasScreen();
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
