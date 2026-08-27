<?php

namespace App\Bridge\Tools;

use App\Bridge\Check\Checks\BoardToolsClientHalfCheck;

/**
 * How the PROCESS that served one board-tools call was started — the one thing the bridge
 * can observe about a caller it is not entitled to go and look at (card#7836 / DL-316).
 *
 * ⭐ THIS DOCBLOCK IS THE SINGLE OWNER OF THE PREDICATE AND OF WHAT IT ESTABLISHES. Every
 * other surface that mentions provenance — {@see ClientHalfLedger}, {@see
 * \App\Models\BoardToolsClientCall}, {@see BoardToolsClientHalfCheck}, {@see
 * \App\Console\Commands\Bridge\ToolsCallCommand}, `docs/board-tools.md`,
 * `docs/config-schema.md`, `CLAUDE.md` and the DL-316 entry — POINTS HERE and states only
 * what its own subject adds. That is not tidiness: the first cut of this feature carried
 * eleven hand-maintained restatements of the rule below, and when the rule was measured
 * WRONG all eleven were wrong together, in prose no test reads.
 *
 * WHY THIS EXISTS. {@see ClientHalfLedger}'s row says the board-tools door OPENED for an
 * agent, and DL-313 shipped it saying exactly that and no more, because three things reach
 * the dispatcher's success point with no seat behind them at all: `bridge:check
 * --probe-tools`, `bin/provision-board-tools.py --self-cert`, and an operator hand-running
 * `php artisan bridge:tools-call --agent=X` on the bridge host. An adversarial review
 * REPRODUCED a green line from a process with no keypair, no `known_hosts`, no `.mcp.json`
 * and no channel server. This enum is the discrimination that was named and not built.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⭐ THE PREDICATE. {@see self::Sshd} iff ALL THREE hold of the serving process:
 *   (1) sshd's session environment is present (`SSH_CONNECTION`);
 *   (2) the process has NO CONTROLLING TERMINAL;
 *   (3) it carries no pty marker (`SSH_TTY`).
 *
 * ⭐ (2) IS THE DISCRIMINATOR, AND IT IS A PROPERTY OF THE PROCESS RATHER THAN OF A STRING
 * SOMEBODY EXPORTED. Every hand-run FROM A TERMINAL has a controlling terminal — a login
 * shell over ssh, a tmux pane, a `screen` window, the machine's own console — and it KEEPS
 * it when stdin is a pipe, which is exactly how this door is hand-run
 * (`echo '{...}' | php artisan bridge:tools-call --agent=X`). ⛔ `stream_isatty(STDIN)` is
 * therefore NOT the test: stdin is a pipe on both sides of the question. `/dev/tty` is,
 * because that device IS the controlling terminal — {@see
 * SystemServingProcessEnvironment::hasControllingTerminal()} opens it, learns the answer
 * from whether the open succeeded, and closes it without reading a byte. The hand-run half
 * of that is MEASURED (see the tmux paragraph, and `SystemServingProcessEnvironmentTest`).
 *
 * ⚑ THE OTHER HALF IS INFERRED, NOT MEASURED, AND THE DISTINCTION IS RECORDED RATHER THAN
 * SMOOTHED OVER. That a REAL pty-less sshd forced command has no controlling terminal
 * follows from sshd allocating no pty for it (`restrict` ⇒ `no-pty`, which the pinned-line
 * leg asserts as an outcome) and from the daemon it descends from having none either — but
 * it was NOT driven through a real sshd here, because standing one up means writing a key
 * into an `authorized_keys` file, which is not this card's to do. ⭐ THE INFERENCE FAILS
 * SAFE IF IT IS WRONG: a forced command that DID hold a controlling terminal would be
 * reported {@see self::NotSshd} and the leg would print DL-313's weaker line — a missed
 * strong claim, never a false one. It is closed on a provisioned install by
 * `bridge:check --probe-tools-ssh` or `provision-board-tools.py --self-cert`, each of which
 * drives a real round-trip: if the stronger line never appears there, this is why.
 *
 * ⛔ (3) IS NARROWING ONLY, AND ITS ABSENCE ESTABLISHES NOTHING — BELIEVING OTHERWISE IS THE
 * DEFECT THIS CLASS WAS SHIPPED WITH ONCE AND MUST NOT BE AGAIN. The first cut used
 * `SSH_CONNECTION` present AND `SSH_TTY` absent, on the claim that an interactive ssh shell
 * "exports SSH_TTY and passes it to everything it spawns". ⛔ THAT CLAIM IS FALSE, AND TMUX
 * IS THE COUNTER-EXAMPLE: tmux's `update-environment` default carries `SSH_CONNECTION` into
 * every new pane and does NOT carry `SSH_TTY` (measured — the shipped default list is
 * `DISPLAY KRB5CCNAME SSH_ASKPASS SSH_AUTH_SOCK SSH_AGENT_PID SSH_CONNECTION WINDOWID
 * XAUTHORITY`), and `SSH_CONNECTION` is REFRESHED on every attach. So a tmux server that
 * never had `SSH_TTY` — started from the console, from a systemd unit, by `tmux new -d`, or
 * simply BEFORE the ssh login — hands every new pane connection-present-and-tty-absent: the
 * exact shape the old predicate called proven. Measured end to end on the development host:
 * a piped hand-run in such a pane reported `SSH_CONNECTION` set, `SSH_TTY` unset,
 * `stream_isatty(STDIN)` false — and a controlling terminal PRESENT, which is what (2)
 * rejects and the old predicate could not. What (3) still buys is the INVERSE lineage:
 * `SSH_TTY` PRESENT is positive evidence of a pty-bearing ancestry, which a pinned forced
 * command can never have, and it survives the loss of the controlling terminal that a
 * `setsid`/`nohup` or an agent tool harness performs — also measured on this host, where the
 * harness this was written through carried `SSH_CONNECTION` and `SSH_TTY` with NO controlling
 * terminal. (1) AND (2) alone would call that hand-run proven.
 *
 * ⚑ THE METHODOLOGICAL ROOT, RECORDED SO THE SHAPE IS RECOGNISABLE NEXT TIME. The
 * measurement that produced the false predicate used a detached `screen`, which inherits its
 * environment WHOLESALE and therefore carries both markers or neither. screen agrees with
 * the predicate in both directions — it was a control THAT COULD NOT FAIL in the direction
 * that mattered (canon #9) — and generalising from it to "an interactive ssh shell has both"
 * is the defect, not the arithmetic on top of it.
 *
 * ⭐ WHAT {@see self::Sshd} RULES OUT — and nothing here is inferred from a name, each is a
 * term above:
 *   - the `--probe-tools` HTTP probe and every other http call: that door STATES {@see
 *     self::NotSshd} as a constant and never reaches this measurement at all;
 *   - EVERY hand-run from a terminal — an ssh login shell, a tmux pane, a `screen` window, a
 *     local console — each keeps its controlling terminal through the pipe on stdin;
 *   - a hand-run whose lineage held a pty and still carries `SSH_TTY`, even after something
 *     dropped its controlling terminal;
 *   - anything running with no ssh session environment at all.
 *
 * ⛔ WHAT IT DOES NOT RULE OUT, AND {@see BoardToolsClientHalfCheck} PRINTS BOTH REMAINDERS
 * RATHER THAN LETTING A STRONGER VERDICT IMPLY THEM AWAY:
 *   - ANY OTHER PTY-LESS ssh INVOCATION of this command — `ssh <host> '<command>'` included,
 *     and in particular `bridge:check --probe-tools-ssh` and `provision-board-tools.py
 *     --self-cert`, each of which drives a real pty-less round-trip that sshd stamps
 *     IDENTICALLY to the seat's. Closing this needs the PROBES to mark their own requests,
 *     which changes what a front door accepts — an ask-first gate, not this card.
 *   - a hand-run from a TERMINAL-LESS context that carries `SSH_CONNECTION` and no `SSH_TTY`
 *     — a cron entry or a systemd USER unit after `systemctl --user import-environment`
 *     (which inherits the variable outright, so "cron and systemd export neither marker" is
 *     ALSO false and is not claimed anywhere), an agent tool harness that scrubs `SSH_TTY`,
 *     or a `setsid` wrapper.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ⛔ `SSH_ORIGINAL_COMMAND` IS DELIBERATELY NOT READ, and it is the obvious-looking mistake
 * here. sshd sets it only when the client REQUESTED a command that the forced command
 * displaced — and no board-tools client requests one: the seat's channel server and
 * {@see SshProbeEnvironment::sshRoundTrip()} both connect with no command at all, so on the
 * real path it is ABSENT. Keying on it would report every genuine call as non-sshd.
 *
 * ⛔ THE VALUES ARE NEVER READ OUT, STORED, OR PRINTED. `SSH_CONNECTION` is
 * `<client-ip> <client-port> <server-ip> <server-port>` (ssh(1) ENVIRONMENT) — four facts
 * about a network peer — and `SSH_TTY` is a device path, on a row whose whole content
 * `bridge:check` prints verbatim. {@see ServingProcessEnvironment} hands back BOOLEANS and
 * nothing else, so the reduction is structural rather than a rule this method remembers.
 *
 * ⛔ THE HTTP DOOR MUST NOT MEASURE, and the reason is not stylistic. `LoopbackOnly` pins the
 * peer to `127.0.0.1` for the probe and the seat alike, so nothing on that door
 * discriminates — and the PHP process serving it can carry an inherited `SSH_CONNECTION`
 * perfectly legitimately (an operator running `php artisan serve` inside an ssh session is
 * the live case, and a PHP-FPM pool has no controlling terminal either), which would mint the
 * strong verdict out of an environment it never chose. {@see
 * \App\Http\Controllers\AgentTools\AgentToolsController} therefore states {@see self::NotSshd}
 * as a constant.
 */
enum CallProvenance: string
{
    /**
     * The serving process matched all three terms of THE PREDICATE above — the shape of a
     * pinned, pty-less forced command, and NOT the shape of any hand-run from a terminal.
     */
    case Sshd = 'sshd';

    /**
     * MEASURED, and it did not. Distinct from a NULL column, which is a row written before
     * this existed and carries no measurement in either direction — the check must not read
     * the two as one, so the enum has no `Unknown` case to collapse them onto.
     */
    case NotSshd = 'not_sshd';

    /**
     * Decide from the serving process's own facts.
     *
     * ⛔ THE ENVIRONMENT IS A PARAMETER, NOT A GLOBAL READ, and that is what makes both arms
     * testable at all: a suite cannot manufacture a controlling terminal for itself, and one
     * that measured its own ambient process would be green or red by accident of how the
     * developer launched it.
     */
    public static function of(ServingProcessEnvironment $env): self
    {
        return $env->hasSshSession() && ! $env->hasControllingTerminal() && ! $env->carriesPtyMarker()
            ? self::Sshd
            : self::NotSshd;
    }
}
