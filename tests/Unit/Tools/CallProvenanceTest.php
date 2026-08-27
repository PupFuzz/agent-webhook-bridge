<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\CallProvenance;
use Tests\Support\FakeServingProcessEnvironment;
use Tests\TestCase;

/**
 * The one measurement behind the stronger client-half verdict (card#7836 / DL-316).
 *
 * ⭐ WHAT THIS CLASS EXISTS TO STOP, and neither case is hypothetical — each was MEASURED on
 * the development host, and each defeated a predicate that had already shipped:
 *   1. `SSH_CONNECTION` present ALONE. sshd exports its session variables into the login
 *      shell and every descendant inherits them, so an operator hand-running
 *      `php artisan bridge:tools-call --agent=X` in their own ssh shell carries it verbatim.
 *   2. `SSH_CONNECTION` present AND `SSH_TTY` absent — the FIRST fix for (1), on the claim
 *      that an interactive ssh shell "exports SSH_TTY and passes it to everything it
 *      spawns". ⛔ FALSE, and tmux is the counter-example: `update-environment` carries
 *      `SSH_CONNECTION` into every pane and NOT `SSH_TTY`, so a tmux server that never had
 *      one hands every pane the exact shape that predicate called proven.
 *      {@see test_a_tmux_pane_attached_over_ssh_is_not_sshd_provenance} is that regression,
 *      and it is THE discriminating case: it is the only input here on which the shipped
 *      predicate and its predecessor disagree.
 *
 * The CONTROLLING TERMINAL is what separates them, and it is a property of the process
 * rather than of a string somebody exported: a pty-less forced command has none, and every
 * hand-run from a terminal has one and KEEPS it when stdin is a pipe. The rule itself,
 * including why the `SSH_TTY` term survives as a narrowing one, is owned by
 * {@see CallProvenance}'s docblock.
 *
 * ⛔ EVERY ARM DRIVES A STATED FIXTURE, NEVER THE AMBIENT PROCESS. The suite cannot
 * manufacture a controlling terminal for itself, and the host it runs on may or may not be
 * inside an ssh session — an arm that read the real process would be green or red by
 * accident of how the developer launched it, which is how the first predicate's evidence
 * came to be a control that could not fail.
 */
class CallProvenanceTest extends TestCase
{
    /**
     * A value shaped exactly like the real thing (ssh(1): client IP, client port, server IP,
     * server port) using RFC 5737 documentation addresses. It exists so the boundary test
     * below asserts about a string that WOULD have appeared had the seam handed back values
     * rather than booleans.
     */
    private const CONNECTION_VALUE = '203.0.113.9 53210 198.51.100.4 22';

    public function test_a_pty_less_sshd_forced_command_is_sshd_provenance(): void
    {
        $env = new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: false, ptyMarker: false);

        $this->assertSame(CallProvenance::Sshd, CallProvenance::of($env));
    }

    /**
     * ⭐ THE REGRESSION THIS FIX EXISTS TO PREVENT, and the ONE input on which the shipped
     * predicate and the `SSH_TTY`-absent one it replaced disagree.
     *
     * tmux's `update-environment` default is `DISPLAY KRB5CCNAME SSH_ASKPASS SSH_AUTH_SOCK
     * SSH_AGENT_PID SSH_CONNECTION WINDOWID XAUTHORITY` — `SSH_CONNECTION` IS on it and
     * `SSH_TTY` is NOT, and the connection variable is refreshed on every attach. So a tmux
     * server that never had `SSH_TTY` — started from the console, from a systemd unit, by
     * `tmux new -d`, or simply BEFORE the ssh login — gives every new pane
     * connection-present-and-tty-absent. Measured end to end on the development host: a
     * piped hand-run in such a pane reported `SSH_CONNECTION` set, `SSH_TTY` unset,
     * `stream_isatty(STDIN)` false, and a controlling terminal PRESENT.
     *
     * A hand-run there is a HAND-RUN. The old predicate called it the pinned forced command.
     */
    public function test_a_tmux_pane_attached_over_ssh_is_not_sshd_provenance(): void
    {
        $env = new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: true, ptyMarker: false);

        $this->assertSame(CallProvenance::NotSshd, CallProvenance::of($env));
    }

    /**
     * An operator's interactive ssh shell — the case the FIRST predicate was written for.
     * It is still rejected, now on TWO independent terms rather than one, which is why this
     * arm cannot tell the two predicates apart and the tmux arm above can.
     */
    public function test_an_interactive_ssh_shell_is_not_sshd_provenance(): void
    {
        $env = new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: true, ptyMarker: true);

        $this->assertSame(CallProvenance::NotSshd, CallProvenance::of($env));
    }

    /**
     * ⭐ THE SECOND MEASURED CASE, and the reason the `SSH_TTY` term is KEPT rather than
     * dropped when the controlling-terminal test replaced it as the discriminator. A
     * pty-bearing lineage can LOSE its controlling terminal — `setsid`, `nohup`, or an agent
     * tool harness that detaches the commands it runs — and still carry `SSH_TTY`. Measured:
     * the harness this fix was written through reported `SSH_CONNECTION` set, `SSH_TTY` set,
     * and NO controlling terminal, so the session-plus-no-terminal pair ALONE would call
     * that hand-run proven. `SSH_TTY` PRESENT is positive evidence of a pty the pinned
     * forced command can never have.
     */
    public function test_a_detached_descendant_of_a_pty_session_is_not_sshd_provenance(): void
    {
        $env = new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: false, ptyMarker: true);

        $this->assertSame(CallProvenance::NotSshd, CallProvenance::of($env));
    }

    /**
     * No ssh session at all — a local console hand-run, a cron entry or a systemd unit that
     * did NOT import one, and the PHP-FPM pool serving the HTTP door on a host nobody is
     * logged into. The terminal-less half of that set is why the session term is required
     * and is not decoration.
     */
    public function test_a_process_with_no_ssh_session_is_not_sshd_provenance(): void
    {
        foreach ([true, false] as $terminal) {
            $env = new FakeServingProcessEnvironment(sshSession: false, controllingTerminal: $terminal, ptyMarker: false);

            $this->assertSame(CallProvenance::NotSshd, CallProvenance::of($env), 'controllingTerminal='.var_export($terminal, true));
        }
    }

    /**
     * ⛔ EVERY ONE OF THE EIGHT INPUTS, so no combination reaches a verdict by accident and
     * the arms above are provably the whole surface rather than the cases someone thought
     * of. Exactly one triple may be `Sshd`.
     */
    public function test_exactly_one_of_the_eight_input_combinations_is_proven(): void
    {
        $proven = [];
        foreach ([true, false] as $session) {
            foreach ([true, false] as $terminal) {
                foreach ([true, false] as $pty) {
                    $env = new FakeServingProcessEnvironment($session, $terminal, $pty);
                    if (CallProvenance::of($env) === CallProvenance::Sshd) {
                        $proven[] = [$session, $terminal, $pty];
                    }
                }
            }
        }

        $this->assertSame([[true, false, false]], $proven, 'the set of input triples that mint the STRONGER verdict has moved — re-read CallProvenance before changing this pin');
    }

    /**
     * ⛔ NO ENVIRONMENT VALUE CAN CROSS THE BOUNDARY, AND THE SEAM IS WHY. `SSH_CONNECTION`
     * is four facts about a network peer and `SSH_TTY` is a device path, and the verdict is
     * persisted to a row `bridge:check` prints VERBATIM. The seam hands back BOOLEANS, so
     * there is no string here to leak — this asserts that the whole output surface (both
     * enum cases, driven non-vacuously) carries none of it.
     */
    public function test_no_environment_value_is_ever_carried_out_of_the_measurement(): void
    {
        $sshd = CallProvenance::of(new FakeServingProcessEnvironment(true, false, false));
        $notSshd = CallProvenance::of(new FakeServingProcessEnvironment(true, true, false));

        // Non-vacuous: the two inputs really did produce the two different cases, so this is
        // checking the whole output surface and not one case twice.
        $this->assertNotSame($sshd, $notSshd);
        foreach ([$sshd, $notSshd] as $provenance) {
            $this->assertContains($provenance->value, ['sshd', 'not_sshd']);
            $this->assertStringNotContainsString('203.0.113.9', $provenance->value);
            $this->assertStringNotContainsString('/dev/pts', $provenance->value);
        }
        // The fixture value is real enough to have leaked had anything carried it.
        $this->assertStringContainsString('203.0.113.9', self::CONNECTION_VALUE);
    }
}
