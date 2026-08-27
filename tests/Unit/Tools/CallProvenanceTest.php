<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\CallProvenance;
use Tests\TestCase;

/**
 * The one measurement behind the stronger client-half verdict (card#7836 / DL-316).
 *
 * ⭐ WHAT THIS CLASS EXISTS TO STOP, and it is not hypothetical — it is the predicate the
 * card was SPECIFIED with. `SSH_CONNECTION` present was the obvious answer and it is WRONG:
 * sshd exports its session variables into the login shell, and every descendant of that
 * shell inherits them for as long as the lineage survives. Measured on the host this was
 * written on, a process four levels below a DETACHED `screen` — reparented to init, with no
 * sshd anywhere in its ancestry — still carried `SSH_CONNECTION`. So the ordinary way an
 * operator hand-runs `php artisan bridge:tools-call --agent=X` on a remote bridge host would
 * have minted the strong verdict, which is the exact over-read the card exists to remove.
 * {@see test_an_inherited_ssh_shell_environment_is_not_sshd_provenance} is that case, and it
 * is the discriminating one: every other test here passes against the naive predicate.
 *
 * The pty is what separates them. A pinned board-tools line denies pty (`restrict` ⇒
 * `no-pty`) and no board-tools client asks for one, so a genuine forced command has
 * `SSH_CONNECTION` and NO `SSH_TTY`; an interactive shell has both.
 */
class CallProvenanceTest extends TestCase
{
    /**
     * A value shaped exactly like the real thing (ssh(1): client IP, client port, server IP,
     * server port) using RFC 5737 documentation addresses, so an assertion that it never
     * appears anywhere is asserting about a string that WOULD have appeared had the value
     * been carried rather than reduced to a name.
     */
    private const CONNECTION_VALUE = '203.0.113.9 53210 198.51.100.4 22';

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The SUITE ITSELF can be running inside an ssh session (it was, on the host this
        // was written on), so an arm that did not SET these would be asserting against the
        // developer's terminal rather than against a fixture — green or red by accident.
        foreach (['SSH_CONNECTION', 'SSH_TTY'] as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            is_string($value) ? putenv("{$name}={$value}") : putenv($name);
        }
        parent::tearDown();
    }

    public function test_an_sshd_forced_command_environment_is_sshd_provenance(): void
    {
        putenv('SSH_CONNECTION='.self::CONNECTION_VALUE);
        // No SSH_TTY: the pinned line denies pty and no board-tools client requests one.

        $this->assertSame(CallProvenance::Sshd, CallProvenance::ofThisProcess());
    }

    /**
     * ⭐ THE DISCRIMINATING CASE. This is the one input on which the naive
     * `SSH_CONNECTION`-alone predicate and the shipped one DISAGREE, and the naive one is
     * wrong: an operator's interactive ssh shell — and anything it spawns, including a
     * `screen` that outlives the login — carries the connection variable verbatim.
     */
    public function test_an_inherited_ssh_shell_environment_is_not_sshd_provenance(): void
    {
        putenv('SSH_CONNECTION='.self::CONNECTION_VALUE);
        putenv('SSH_TTY=/dev/pts/7');

        $this->assertSame(CallProvenance::NotSshd, CallProvenance::ofThisProcess());
    }

    public function test_a_process_with_no_ssh_environment_at_all_is_not_sshd_provenance(): void
    {
        // A local console, a cron entry, a systemd unit — and the PHP-FPM pool serving the
        // HTTP door on a host nobody is logged into.
        $this->assertSame(CallProvenance::NotSshd, CallProvenance::ofThisProcess());
    }

    /**
     * PRESENCE means non-empty. An exported-but-empty variable is what a wrapper script that
     * cleared the environment leaves behind, and it is not a measurement of an ssh session.
     */
    public function test_an_empty_connection_variable_is_not_presence(): void
    {
        putenv('SSH_CONNECTION=');

        $this->assertSame(CallProvenance::NotSshd, CallProvenance::ofThisProcess());
    }

    /**
     * An empty SSH_TTY must not suppress a genuine forced command — the pty test is
     * PRESENCE, symmetrical with the connection test, and reading `''` as "a pty" would
     * report every such call as unproven.
     */
    public function test_an_empty_tty_variable_does_not_suppress_the_verdict(): void
    {
        putenv('SSH_CONNECTION='.self::CONNECTION_VALUE);
        putenv('SSH_TTY=');

        $this->assertSame(CallProvenance::Sshd, CallProvenance::ofThisProcess());
    }

    /**
     * ⛔ THE VALUE NEVER CROSSES THE BOUNDARY. `SSH_CONNECTION` is four facts about a network
     * peer and `SSH_TTY` is a device path, and everything this enum produces is persisted to
     * a row `bridge:check` prints VERBATIM. So the whole output surface is enumerated — the
     * enum has two cases and both are checked — and neither carries a byte of either input.
     */
    public function test_no_environment_value_is_ever_carried_out_of_the_measurement(): void
    {
        putenv('SSH_CONNECTION='.self::CONNECTION_VALUE);
        $sshd = CallProvenance::ofThisProcess();
        putenv('SSH_TTY=/dev/pts/7');
        $notSshd = CallProvenance::ofThisProcess();

        // Non-vacuous: the two inputs really did produce the two different cases, so this is
        // checking the whole output surface and not one case twice.
        $this->assertNotSame($sshd, $notSshd);
        foreach ([$sshd, $notSshd] as $provenance) {
            $this->assertContains($provenance->value, ['sshd', 'not_sshd']);
            $this->assertStringNotContainsString('203.0.113.9', $provenance->value);
            $this->assertStringNotContainsString('/dev/pts', $provenance->value);
        }
    }
}
