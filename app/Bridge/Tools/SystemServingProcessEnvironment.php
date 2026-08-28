<?php

namespace App\Bridge\Tools;

/**
 * The real-process {@see ServingProcessEnvironment} (card#7836 / DL-316).
 *
 * ⛔ NO LEG EVER ANSWERS A QUESTION IT COULD NOT MEASURE. Each returns `?bool`, and `null` —
 * "this process cannot establish the fact" — is a THIRD state, never folded onto the `false`
 * beside it. That is structural rather than a promise this class keeps: {@see
 * CallProvenance::of()} mints {@see CallProvenance::Sshd} only from three POSITIVE
 * measurements, so an unestablishable fact reaches {@see CallProvenance::NotSshd} by the
 * shape of the predicate and not by each leg picking a safe-looking constant. Same discipline
 * as {@see SshProbeEnvironment}, for the same reason (DL-259): a missing CAPABILITY reported
 * as a measured `false` is spent by the caller as a measurement.
 *
 * ⚑ THE COST OF THAT IS A MISSED STRONG CLAIM AND IT IS THE RIGHT SIDE TO MISS ON. On a host
 * where the probe cannot reach `/dev/tty` NOR `/proc`, a genuine pty-less forced command
 * reports `null` and the leg prints DL-313's weaker line, forever. A `false` there would
 * print the STRONGER line for every hand-run on that host.
 */
final class SystemServingProcessEnvironment implements ServingProcessEnvironment
{
    /**
     * `getenv()` and not `env()` / `$_SERVER`: Laravel's dotenv adapters write `.env` into
     * `$_ENV`/`$_SERVER` and NOT into the process environment, so `getenv()` answers about
     * how this process was actually spawned and a `.env` line cannot forge it.
     */
    public function hasSshSession(): ?bool
    {
        return $this->environmentVariablePresent('SSH_CONNECTION');
    }

    /**
     * TWO LEGS, AND THE SECOND EXISTS BECAUSE A FAILED OPEN IS NOT A MEASUREMENT.
     *
     * ⛔ OPENED AND IMMEDIATELY CLOSED, AND NOT ONE BYTE IS READ. `/dev/tty` IS the calling
     * process's controlling terminal, so the OPEN SUCCEEDING settles the POSITIVE half on its
     * own. Reading from it would consume the operator's keystrokes; on a background process
     * group it would raise SIGTTIN and stop the process. The handle is closed on the spot so
     * a hand-run cannot leave the tty held open by a command that never wanted it.
     *
     * ⛔ THE OPEN FAILING SETTLES NOTHING, AND READING IT AS "no controlling terminal" IS THE
     * DEFECT THIS METHOD SHIPPED WITH ONCE. Two different failures are indistinguishable at
     * the return value — both measured on the development host:
     *   - no controlling terminal ⇒ `fopen(/dev/tty): … No such device or address`;
     *   - a controlling terminal the process may not OPEN ⇒ `… Operation not permitted`,
     *     which is what a CLI `php.ini` carrying `open_basedir` produces — an ordinary
     *     hardening line, on a host nothing here inspects.
     * A tmux pane under such a php.ini is exactly the composition the pty-marker term cannot
     * catch (see {@see CallProvenance}), so the resurrection of that defect is one config
     * file away. `error_get_last()` is deliberately NOT the discriminator: `strerror` text is
     * locale-dependent, and pinning the verdict to an English string is a second silent
     * failure mode. The kernel's own record of the fact is read instead.
     *
     * `@` is deliberate on both reads: the failing case is EXPECTED on the door this decides,
     * and a PHP warning on it would land on STDERR of a command whose STDOUT purity is
     * load-bearing and whose STDERR is relayed as a diagnostic.
     */
    public function hasControllingTerminal(): ?bool
    {
        $tty = @fopen('/dev/tty', 'r');
        if ($tty !== false) {
            fclose($tty);

            return true;
        }

        return $this->controllingTerminalFromProcStat();
    }

    /** Presence only — the tty device path in the value is never read. */
    public function carriesPtyMarker(): ?bool
    {
        return $this->environmentVariablePresent('SSH_TTY');
    }

    /**
     * PRESENCE means non-empty — an exported-but-empty variable is what a wrapper that
     * cleared the environment leaves behind, and it is not a measurement of an ssh session.
     *
     * The capability test comes FIRST and returns null: `getenv` is disableable
     * (`disable_functions`, a hardened-host staple — `function_exists()` reports it absent,
     * measured), and without it there is no environment to consult, so a `false` would claim
     * a variable was looked for and found missing.
     */
    private function environmentVariablePresent(string $name): ?bool
    {
        if (! function_exists('getenv')) {
            return null;
        }

        $value = getenv($name);

        return is_string($value) && $value !== '';
    }

    /**
     * `tty_nr`, field 7 of `/proc/self/stat` — the kernel's own record of THIS process's
     * controlling terminal: `0` when it has none, the device number when it has one (measured
     * here: `0` under `setsid`, `34816` under a `script` pty). It answers the question
     * directly, so it is not a heuristic standing in for the open.
     *
     * ⚑ IT IS THE SECOND LEG AND NOT THE FIRST because `/dev/tty` is portable and `/proc` is
     * Linux-only; an unreadable, unmounted or `hidepid`-hidden `/proc` therefore costs the
     * strong verdict rather than corrupting it. The comm field is parenthesised and may itself
     * contain spaces and `)`, which is why the fields are counted from the LAST `)` and not
     * split from the start.
     */
    private function controllingTerminalFromProcStat(): ?bool
    {
        $stat = @file_get_contents('/proc/self/stat');
        if (! is_string($stat)) {
            return null;
        }

        $afterComm = strrpos($stat, ')');
        if ($afterComm === false) {
            return null;
        }

        // Field 3 (state) is the first one after comm, so tty_nr (field 7) is offset 4.
        $fields = preg_split('/\s+/', trim(substr($stat, $afterComm + 1))) ?: [];
        $ttyNr = $fields[4] ?? '';
        if (preg_match('/^-?\d+$/', $ttyNr) !== 1) {
            return null;
        }

        return (int) $ttyNr !== 0;
    }
}
