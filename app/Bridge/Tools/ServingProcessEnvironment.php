<?php

namespace App\Bridge\Tools;

/**
 * The three facts about THIS process that decide {@see CallProvenance}, behind a seam so
 * both arms are drivable in a unit test without a real ssh session and without a real
 * terminal (card#7836 / DL-316). The default {@see SystemServingProcessEnvironment} reads
 * the real process; a test binds an in-memory fake, exactly as {@see SshProbeEnvironment}
 * does for the `bridge:check` host facts.
 *
 * ⛔ EVERY METHOD RETURNS A BOOL, AND THAT IS A CONSTRAINT ON THE SEAM RATHER THAN A
 * CONVENIENCE. The underlying facts are a network peer (`SSH_CONNECTION` is
 * `<client-ip> <client-port> <server-ip> <server-port>`) and a device path (`SSH_TTY`), and
 * the verdict they produce is persisted to a row `bridge:check` prints VERBATIM. An
 * interface that handed back the STRINGS would put the decision to disclose them one careless
 * caller away; handing back booleans makes the reduction structural.
 *
 * ⭐ WHY THE PREDICATE THESE FEED IS WHAT IT IS — including which of them is load-bearing and
 * which is merely narrowing — is owned by {@see CallProvenance}'s class docblock and is
 * deliberately not restated here.
 */
interface ServingProcessEnvironment
{
    /** Did sshd export its session environment into this process's lineage? */
    public function hasSshSession(): bool;

    /**
     * Does this process have a CONTROLLING TERMINAL?
     *
     * The one fact that separates a pty-less sshd forced command from a hand-run, and the
     * reason it is asked instead of `stream_isatty(STDIN)`: operators hand-run this door as
     * `echo '{...}' | php artisan bridge:tools-call`, so stdin is a PIPE either way — while
     * a hand-run's controlling terminal survives the pipe untouched.
     */
    public function hasControllingTerminal(): bool;

    /**
     * Does this process carry sshd's pty marker (`SSH_TTY`)?
     *
     * ⛔ ITS ABSENCE ESTABLISHES NOTHING — see {@see CallProvenance} for the measurement that
     * proved so. It is asked for its PRESENCE, which is positive evidence of a pty-bearing
     * lineage the pinned forced command can never have.
     */
    public function carriesPtyMarker(): bool;
}
