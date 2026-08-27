<?php

namespace App\Bridge\Tools;

use App\Bridge\Check\Checks\BoardToolsClientHalfCheck;

/**
 * How the PROCESS that served one board-tools call was started — the one thing the bridge
 * can observe about a caller it is not entitled to go and look at (card#7836 / DL-316).
 *
 * WHY THIS EXISTS. {@see ClientHalfLedger}'s row says the board-tools door OPENED for an
 * agent, and DL-313 shipped it saying exactly that and no more, because three things reach
 * the dispatcher's success point with no seat behind them at all: `bridge:check
 * --probe-tools`, `bin/provision-board-tools.py --self-cert`, and an operator hand-running
 * `php artisan bridge:tools-call --agent=X` on the bridge host. An adversarial review
 * REPRODUCED a green line from a process with no keypair, no `known_hosts`, no `.mcp.json`
 * and no channel server. This enum is the discrimination that was named and not built.
 *
 * ⭐ WHAT THE SSH DOOR CAN SEE. sshd exports `SSH_CONNECTION` into the environment of the
 * forced command it spawns, and sets `SSH_TTY` only when it allocated a pty. The pinned
 * board-tools line denies pty (`restrict` ⇒ `no-pty`, which the pinned-line leg asserts as
 * an OUTCOME), and no board-tools client asks for one — the seat's channel server and
 * {@see SshProbeEnvironment::sshRoundTrip()} both pipe JSON on stdin. So a genuine
 * forced-command call has `SSH_CONNECTION` and NO `SSH_TTY`, and that PAIR is the predicate.
 *
 * ⛔ `SSH_CONNECTION` ALONE IS NOT THE PREDICATE, AND BELIEVING IT WAS IS THE DEFECT THIS
 * CLASS WAS NEARLY SHIPPED WITH. Every environment variable sshd exports is INHERITED by
 * every descendant of the login shell, for as long as that lineage survives — so an operator
 * hand-running `php artisan bridge:tools-call --agent=X` in their own ssh shell carries
 * `SSH_CONNECTION` exactly as the forced command does, which is the ordinary way anyone runs
 * it on a remote bridge host. MEASURED, not reasoned: on the host this was written on, a
 * process four levels below a DETACHED `screen` — reparented to init, with no sshd anywhere
 * in its ancestry — still carried both `SSH_CONNECTION` and `SSH_TTY`, inherited from an ssh
 * login that had long since ended. `SSH_TTY`'s absence is what rejects that lineage: an
 * interactive ssh session has a pty, a pinned forced command does not.
 *
 * ⛔ WHAT IT STILL CANNOT SEE, AND THE READING CHECK SAYS SO. `Sshd` names the SHAPE of the
 * arrival, never the CLIENT: `bridge:check --probe-tools-ssh` and `provision-board-tools.py
 * --self-cert` each drive a real, pty-less `ssh` round-trip, so sshd stamps their forced
 * command with an environment identical to the seat's — and so would any other pty-less ssh
 * invocation of this command, `ssh <host> '<command>'` included. Narrowing the ambiguity is not closing it, and
 * {@see BoardToolsClientHalfCheck} prints the remainder rather
 * than letting a stronger verdict imply it away. Closing it needs the PROBES to mark their
 * own requests, which changes what a front door accepts — an ask-first gate, not this card.
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
 * `bridge:check` prints verbatim. Only their PRESENCE crosses this boundary; neither string
 * leaves {@see self::ofThisProcess()}, and neither is logged on the way.
 *
 * ⛔ THE HTTP DOOR MUST NOT CALL {@see self::ofThisProcess()}, and the reason is not
 * stylistic. `LoopbackOnly` pins the peer to `127.0.0.1` for the probe and the seat alike,
 * so nothing on that door discriminates — and the PHP process serving it can carry an
 * inherited `SSH_CONNECTION` perfectly legitimately (an operator running `php artisan serve`
 * inside an ssh session is the live case), which would mint the strong verdict out of an
 * environment variable that says nothing about the caller. {@see
 * \App\Http\Controllers\AgentTools\AgentToolsController} therefore states {@see self::NotSshd}
 * as a constant rather than measuring.
 */
enum CallProvenance: string
{
    /**
     * The serving process carried sshd's session environment AND no pty — the shape of a
     * pinned, `no-pty` forced command, and NOT the shape of an operator's interactive shell.
     */
    case Sshd = 'sshd';

    /**
     * MEASURED, and it did not. Distinct from a NULL column, which is a row written before
     * this existed and carries no measurement in either direction — the check must not read
     * the two as one, so the enum has no `Unknown` case to collapse them onto.
     */
    case NotSshd = 'not_sshd';

    /**
     * Read the CURRENT PROCESS's environment.
     *
     * `getenv()` and not `env()` / `$_SERVER`: Laravel's dotenv adapters write `.env` into
     * `$_ENV`/`$_SERVER` and NOT into the process environment, so `getenv()` answers about
     * how this process was actually spawned and a `.env` line cannot forge it.
     */
    public static function ofThisProcess(): self
    {
        return self::hasSshSession() && ! self::hasPty()
            ? self::Sshd
            : self::NotSshd;
    }

    /** Presence only — the four network facts in the value are never read. */
    private static function hasSshSession(): bool
    {
        $connection = getenv('SSH_CONNECTION');

        return is_string($connection) && $connection !== '';
    }

    /** Presence only — the tty device path in the value is never read. */
    private static function hasPty(): bool
    {
        $tty = getenv('SSH_TTY');

        return is_string($tty) && $tty !== '';
    }
}
