<?php

namespace Tests\Unit\Tools;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The REAL controlling-terminal probe, on the real surface (card#7836 / DL-316).
 *
 * ⭐ WHY THIS CLASS EXISTS AT ALL, when every other `System*Environment` in this tree is left
 * to the seam: the defect this card fixes was shipped on a measurement that COULD NOT FAIL
 * in the direction that mattered — a detached `screen`, which inherits its environment
 * wholesale and therefore agrees with the wrong predicate in both directions. A seam-only
 * suite would reproduce exactly that: {@see CallProvenanceTest} proves what the PREDICATE
 * does with three booleans and says nothing whatever about whether the third boolean is
 * measured correctly.
 *
 * ⛔ THE SUITE'S OWN PROCESS IS NOT AN ADMISSIBLE FIXTURE, in either direction. Whether
 * phpunit here has a controlling terminal depends on how the developer launched it — a
 * terminal run has one, a CI runner and an agent tool harness do not — so an assertion about
 * the ambient process is green or red by accident. Both arms below therefore SPAWN a child
 * whose terminal state is forced, and the two are compared: a probe stuck on either constant
 * fails the comparison even if it happens to match one arm.
 *
 * ⚑ `stream_isatty(STDIN)` IS NOT WHAT IS BEING MEASURED and could not stand in for it: this
 * door is hand-run as `echo '{...}' | php artisan bridge:tools-call`, so stdin is a PIPE on
 * both sides of the question while the controlling terminal is not.
 */
class SystemServingProcessEnvironmentTest extends TestCase
{
    /**
     * ⭐ THE TWO DIRECTIONS, MEASURED AND COMPARED. `setsid -w` runs its child in a NEW
     * session with no controlling terminal — which is what a pty-less sshd forced command
     * descends into — and `script -qec … /dev/null` runs its child under a freshly allocated
     * pty, which is what every hand-run from a terminal has. The comparison is the control:
     * `hasControllingTerminal()` returning a constant satisfies one arm and fails the other,
     * and fails the disagreement assertion regardless.
     */
    public function test_the_probe_distinguishes_a_session_with_no_terminal_from_one_under_a_pty(): void
    {
        $finder = new ExecutableFinder;
        $setsid = $finder->find('setsid', null, ['/usr/bin', '/bin']);
        $script = $finder->find('script', null, ['/usr/bin', '/bin']);
        if ($setsid === null || $script === null) {
            $this->markTestSkipped('setsid and script (util-linux) are needed to force a child\'s controlling-terminal state; without them the REAL probe goes unmeasured and only the seam in CallProvenanceTest is covered');
        }

        $probe = base_path('storage/framework/testing/ctty-probe-'.getmypid().'.php');
        @mkdir(dirname($probe), 0o755, true);
        file_put_contents($probe, "<?php\nrequire ".var_export(base_path('vendor/autoload.php'), true).";\n"
            ."echo (new App\\Bridge\\Tools\\SystemServingProcessEnvironment)->hasControllingTerminal() ? 'yes' : 'no';\n");

        try {
            $withoutTerminal = $this->runAndTrim([$setsid, '-w', PHP_BINARY, $probe]);
            $underAPty = $this->runAndTrim([$script, '-qec', PHP_BINARY.' '.escapeshellarg($probe), '/dev/null']);
        } finally {
            @unlink($probe);
        }

        // Each arm read a real process, not a fixture — an empty or unexpected string here
        // means the spawn failed and NOTHING was measured, which must not read as a pass.
        $this->assertContains($withoutTerminal, ['yes', 'no'], 'the setsid arm produced no usable answer: '.var_export($withoutTerminal, true));
        $this->assertContains($underAPty, ['yes', 'no'], 'the pty arm produced no usable answer: '.var_export($underAPty, true));

        $this->assertSame('no', $withoutTerminal, 'a process in a new session with no controlling terminal was reported as HAVING one — the probe would mint the stronger client-half verdict for every hand-run');
        $this->assertSame('yes', $underAPty, 'a process running under a pty was reported as having NO controlling terminal — the probe would refuse the stronger verdict for every genuine forced command');
        $this->assertNotSame($withoutTerminal, $underAPty, 'the probe answered the same for both — it is a constant, and this whole measurement is decorative');
    }

    /** @param list<string> $command */
    private function runAndTrim(array $command): string
    {
        $proc = new Process($command, base_path());
        $proc->setTimeout(30);
        $proc->run();

        // `script` writes the child's output through a pty, so line endings arrive as CRLF.
        return trim(str_replace("\r", '', $proc->getOutput()));
    }
}
