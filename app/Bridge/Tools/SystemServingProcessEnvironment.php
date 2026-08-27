<?php

namespace App\Bridge\Tools;

/**
 * The real-process {@see ServingProcessEnvironment} (card#7836 / DL-316).
 *
 * ⛔ EVERY LEG FAILS TOWARD THE WEAKER VERDICT. A fact that cannot be established returns
 * the value that makes {@see CallProvenance::of()} say {@see CallProvenance::NotSshd}, never
 * the value that mints the stronger claim: an unopenable `/dev/tty` on a host that has no
 * such device is reported as "no controlling terminal", which is why the ssh-session term is
 * required alongside it and is not decoration.
 */
final class SystemServingProcessEnvironment implements ServingProcessEnvironment
{
    /**
     * `getenv()` and not `env()` / `$_SERVER`: Laravel's dotenv adapters write `.env` into
     * `$_ENV`/`$_SERVER` and NOT into the process environment, so `getenv()` answers about
     * how this process was actually spawned and a `.env` line cannot forge it.
     *
     * PRESENCE means non-empty — an exported-but-empty variable is what a wrapper that
     * cleared the environment leaves behind, and it is not a measurement of an ssh session.
     */
    public function hasSshSession(): bool
    {
        $connection = getenv('SSH_CONNECTION');

        return is_string($connection) && $connection !== '';
    }

    /**
     * ⛔ OPENED AND IMMEDIATELY CLOSED, AND NOT ONE BYTE IS READ. `/dev/tty` IS the calling
     * process's controlling terminal, so the OPEN SUCCEEDING is the whole measurement:
     * a process with no controlling terminal has no such device to resolve and the open
     * fails. Reading from it would consume the operator's keystrokes; on a background
     * process group it would raise SIGTTIN and stop the process. The handle is closed on the
     * spot so a hand-run cannot leave the tty held open by a command that never wanted it.
     *
     * `@` is deliberate: the failing case is the EXPECTED one on the door this decides, and
     * a PHP warning on it would land on STDERR of a command whose STDOUT purity is
     * load-bearing and whose STDERR is relayed as a diagnostic.
     */
    public function hasControllingTerminal(): bool
    {
        $tty = @fopen('/dev/tty', 'r');
        if ($tty === false) {
            return false;
        }
        fclose($tty);

        return true;
    }

    /** Presence only — the tty device path in the value is never read. */
    public function carriesPtyMarker(): bool
    {
        $tty = getenv('SSH_TTY');

        return is_string($tty) && $tty !== '';
    }
}
