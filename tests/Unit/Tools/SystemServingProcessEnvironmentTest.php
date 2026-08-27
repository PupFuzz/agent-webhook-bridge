<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\CallProvenance;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The REAL process probes, on real processes (card#7836 / DL-316).
 *
 * ⭐ WHY THIS CLASS EXISTS AT ALL, when every other `System*Environment` in this tree is left
 * to the seam: the defect this card fixes was shipped on a measurement that COULD NOT FAIL
 * in the direction that mattered — a detached `screen`, which inherits its environment
 * wholesale and therefore agrees with the wrong predicate in both directions. A seam-only
 * suite would reproduce exactly that: {@see CallProvenanceTest} proves what the PREDICATE
 * does with three stated answers and says nothing whatever about whether those answers are
 * measured correctly.
 *
 * ⛔ THE SUITE'S OWN PROCESS IS NOT AN ADMISSIBLE FIXTURE, in either direction. Whether
 * phpunit here has a controlling terminal, or an ssh session environment, depends on how the
 * developer launched it — a terminal run has one, a CI runner and an agent tool harness do
 * not — so an assertion about the ambient process is green or red by accident. Every arm
 * below SPAWNS a child whose state is forced, and the arms that can be compared are: a probe
 * stuck on any constant fails a comparison even where it happens to match one arm.
 *
 * ⚑ THE CHILD LOADS THE TWO SHIPPED FILES DIRECTLY, not the Composer autoloader. The
 * autoloader itself reads the environment (`laravel/agent-detector` at load time), which
 * makes the `disable_functions` arm below unrunnable through it — and the subject here is one
 * class with no dependencies, so requiring the real file by path measures the shipped code
 * without dragging a framework's own environment reads into the fixture.
 *
 * ⚑ `stream_isatty(STDIN)` IS NOT WHAT IS BEING MEASURED and could not stand in for it: this
 * door is hand-run as `echo '{...}' | php artisan bridge:tools-call`, so stdin is a PIPE on
 * both sides of the question while the controlling terminal is not.
 */
class SystemServingProcessEnvironmentTest extends TestCase
{
    /**
     * ⭐ THE TWO ORDINARY DIRECTIONS, MEASURED AND COMPARED. `setsid -w` runs its child in a
     * NEW session with no controlling terminal — which is what a pty-less sshd forced command
     * descends into — and `script -qec … /dev/null` runs its child under a freshly allocated
     * pty, which is what every hand-run from a terminal has. The comparison is the control:
     * `hasControllingTerminal()` returning a constant satisfies one arm and fails the other,
     * and fails the disagreement assertion regardless.
     */
    public function test_the_probe_distinguishes_a_session_with_no_terminal_from_one_under_a_pty(): void
    {
        [$setsid, $script] = $this->utilLinuxOrSkip();
        $probe = $this->writeProbe('two-directions', '$env->hasControllingTerminal()');

        try {
            $withoutTerminal = $this->runAndTrim([$setsid, '-w', PHP_BINARY, $probe]);
            $underAPty = $this->runAndTrim([$script, '-qec', PHP_BINARY.' '.escapeshellarg($probe), '/dev/null']);
        } finally {
            @unlink($probe);
        }

        // Each arm read a real process, not a fixture — an empty or unexpected string here
        // means the spawn failed and NOTHING was measured, which must not read as a pass.
        $this->assertAnswer($withoutTerminal, 'the setsid arm');
        $this->assertAnswer($underAPty, 'the pty arm');

        $this->assertSame('no', $withoutTerminal, 'a process in a new session with no controlling terminal was reported as HAVING one, or as unmeasurable on a host where it IS measurable — the first refuses the stronger verdict for every genuine forced command, the second never mints it at all');
        $this->assertSame('yes', $underAPty, 'a process running under a pty was reported as having NO controlling terminal — the probe would mint the stronger client-half verdict for every hand-run');
        $this->assertNotSame($withoutTerminal, $underAPty, 'the probe answered the same for both — it is a constant, and this whole measurement is decorative');
    }

    /**
     * ⭐ THE FACT IS UNESTABLISHABLE, AND THE ONE ANSWER IT MUST NOT GIVE IS THE ONE THAT MINTS
     * THE STRONGER VERDICT. `open_basedir` is the reachable, measured instance — an ordinary
     * hardening line in a CLI `php.ini` — and it denies `/dev/tty` AND `/proc/self/stat`
     * alike, so BOTH legs of the probe are refused while the process demonstrably HAS a
     * controlling terminal (it is running under `script`'s pty).
     *
     * ⛔ THIS ARM REDS AGAINST THE PROBE THIS FIX REPLACES. That probe read a failed
     * `fopen('/dev/tty')` as "no controlling terminal" whatever the failure was, so this child
     * answered `no` — the shape {@see CallProvenance} calls proven, from a
     * tmux pane whose php.ini happens to carry an `open_basedir` line. The tmux blocker,
     * resurrected by a config file nothing here reads.
     *
     * ⚑ `unknown` IS ASSERTED EXACTLY, not merely "not `no`". A relaxed assertion passes
     * VACUOUSLY the moment `open_basedir` stops taking effect — the child would then open
     * `/dev/tty` normally and answer `yes`, and the arm would certify a restriction it never
     * applied. Requiring `unknown` fails in both directions: `no` is the defect, `yes` is the
     * fixture not being in force.
     */
    public function test_a_probe_that_can_reach_neither_the_device_nor_proc_answers_unknown(): void
    {
        [, $script] = $this->utilLinuxOrSkip();
        $probe = $this->writeProbe('open-basedir', '$env->hasControllingTerminal()');

        try {
            $restrictedUnderAPty = $this->runAndTrim([$script, '-qec',
                $this->php(['open_basedir' => base_path()]).' '.escapeshellarg($probe), '/dev/null']);
        } finally {
            @unlink($probe);
        }

        $this->assertAnswer($restrictedUnderAPty, 'the restricted arm');
        $this->assertSame('unknown', $restrictedUnderAPty, $restrictedUnderAPty === 'no'
            ? 'a process WITH a controlling terminal, whose php.ini merely denied the probe the device, was reported as having NONE — that is the term the stronger provenance verdict is minted on, so an open_basedir line resurrects the tmux defect this card removed'
            : 'the restricted child answered as if unrestricted — `open_basedir` did not take effect, so this arm measured nothing and must not read as a pass');
    }

    /**
     * ⭐ THE SECOND LEG, DRIVEN IN BOTH DIRECTIONS ON ITS OWN. With `/dev/tty` denied but
     * `/proc` allowed, the answer can come from ONLY ONE place: `tty_nr`, field 7 of
     * `/proc/self/stat` — the kernel's own record of this process's controlling terminal. The
     * pty child must still read `yes` and the `setsid` child `no`, which is what makes the
     * leg a measurement rather than a way of saying "unknown" more slowly.
     *
     * ⚑ This is also the arm that fails if the field OFFSET is wrong: field 7 sits after a
     * parenthesised `comm` that may itself contain spaces and `)`, so a naive split lands on
     * a neighbouring field, and a neighbour that happens to be non-zero would answer `yes` for
     * both children.
     */
    public function test_the_proc_leg_answers_both_ways_when_the_device_alone_is_denied(): void
    {
        [$setsid, $script] = $this->utilLinuxOrSkip();
        $probe = $this->writeProbe('proc-only', '$env->hasControllingTerminal()');
        $php = $this->php(['open_basedir' => base_path().PATH_SEPARATOR.'/proc']);

        try {
            $underAPty = $this->runAndTrim([$script, '-qec', $php.' '.escapeshellarg($probe), '/dev/null']);
            $withoutTerminal = $this->runAndTrim([$setsid, '-w', 'sh', '-c', $php.' '.escapeshellarg($probe)]);
        } finally {
            @unlink($probe);
        }

        $this->assertAnswer($underAPty, 'the proc-only pty arm');
        $this->assertAnswer($withoutTerminal, 'the proc-only setsid arm');

        $this->assertSame('yes', $underAPty, 'the device was denied and the kernel record was readable, and the probe still could not tell that this process HAS a controlling terminal — `tty_nr` is being read wrong, or not read at all');
        $this->assertSame('no', $withoutTerminal, 'the kernel record says this process has no controlling terminal and the probe did not say so — the negative half of the second leg is not measuring');
        $this->assertNotSame($underAPty, $withoutTerminal, 'the proc leg answered the same for both — it is a constant');
    }

    /**
     * ⭐ THE ENVIRONMENT LEGS HAVE AN UNESTABLISHABLE STATE TOO, and `carriesPtyMarker()` is
     * the one that matters: its ABSENCE is a term of the stronger verdict, so a `getenv` this
     * process cannot call must not read as "no pty marker". `disable_functions=getenv` is the
     * reachable instance — a hardened-host staple, under which `function_exists('getenv')`
     * reports it absent (measured).
     *
     * ⛔ Both legs are asserted `unknown` rather than one: `hasSshSession()` returning a
     * measured `false` here would be the same fabrication in the direction that happens to be
     * safe today, and "safe because another term catches it" is precisely the reasoning tmux
     * demolished for the pty marker.
     */
    public function test_an_environment_the_process_cannot_read_is_unknown_and_not_absent(): void
    {
        $probe = $this->writeProbe('no-getenv', '$env->hasSshSession()', '$env->carriesPtyMarker()');

        try {
            $answers = $this->runAndTrim([$this->php(['disable_functions' => 'getenv']), $probe],
                shell: true, env: ['SSH_CONNECTION' => '203.0.113.9 53210 198.51.100.4 22', 'SSH_TTY' => '/dev/pts/9']);
        } finally {
            @unlink($probe);
        }

        // Both variables ARE set in the child, so `no` would be a claim about a lookup that
        // never happened — and the values are the real shapes, which is what makes it one.
        $this->assertSame('unknown unknown', $answers, 'a process that cannot call `getenv` reported the ssh session and the pty marker as measured-and-absent; the pty term is exactly what the stronger verdict is minted on');
    }

    /** @return array{0: string, 1: string} setsid + script, or the test is skipped. */
    private function utilLinuxOrSkip(): array
    {
        $finder = new ExecutableFinder;
        $setsid = $finder->find('setsid', null, ['/usr/bin', '/bin']);
        $script = $finder->find('script', null, ['/usr/bin', '/bin']);
        if ($setsid === null || $script === null) {
            $this->markTestSkipped('setsid and script (util-linux) are needed to force a child\'s controlling-terminal state; without them the REAL probe goes unmeasured and only the seam in CallProvenanceTest is covered');
        }

        return [$setsid, $script];
    }

    /** @param array<string, string> $ini */
    private function php(array $ini = []): string
    {
        $command = escapeshellarg(PHP_BINARY);
        foreach ($ini as $directive => $value) {
            $command .= ' -d '.escapeshellarg($directive.'='.$value);
        }

        return $command;
    }

    /**
     * The probe prints all THREE answers distinctly. `unknown` is a value the caller must be
     * able to see: collapsing it onto `no` here would hide exactly the defect these arms exist
     * to catch.
     */
    private function writeProbe(string $arm, string ...$expressions): string
    {
        $probe = base_path('storage/framework/testing/serving-process-probe-'.$arm.'-'.getmypid().'.php');
        @mkdir(dirname($probe), 0o755, true);
        $body = "<?php\n"
            .'require '.var_export(base_path('app/Bridge/Tools/ServingProcessEnvironment.php'), true).";\n"
            .'require '.var_export(base_path('app/Bridge/Tools/SystemServingProcessEnvironment.php'), true).";\n"
            ."\$env = new App\\Bridge\\Tools\\SystemServingProcessEnvironment;\n"
            ."\$say = fn (?bool \$answer) => \$answer === null ? 'unknown' : (\$answer ? 'yes' : 'no');\n"
            .'echo implode(\' \', ['.implode(', ', array_map(fn (string $e): string => "\$say({$e})", $expressions))."]);\n";
        file_put_contents($probe, $body);

        return $probe;
    }

    private function assertAnswer(string $answer, string $arm): void
    {
        $this->assertContains($answer, ['yes', 'no', 'unknown'], $arm.' produced no usable answer, so it measured NOTHING and must not read as a pass: '.var_export($answer, true));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function runAndTrim(array $command, bool $shell = false, array $env = []): string
    {
        $proc = $shell
            ? Process::fromShellCommandline(implode(' ', $command), base_path(), $env)
            : new Process($command, base_path(), $env ?: null);
        $proc->setTimeout(30);
        $proc->run();

        // `script` writes the child's output through a pty, so line endings arrive as CRLF.
        return trim(str_replace("\r", '', $proc->getOutput()));
    }
}
