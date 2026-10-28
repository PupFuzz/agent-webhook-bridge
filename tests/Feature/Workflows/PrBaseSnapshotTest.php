<?php

namespace Tests\Feature\Workflows;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * `bin/pr-base-snapshot.sh` is a WRAPPER since card#12338: it execs
 * agent-board-toolkit's `bin/pr-base-snapshot --head <sha>` from the checkout
 * `AGENT_BOARD_TOOLKIT_DIR` names. The derivation, its refusals and their exit
 * codes are the toolkit's and are exercised by its own selftest at the pinned SHA.
 *
 * What is tested here is what the wrapper adds: the call shape it adapts, that
 * the tool's stdout, stderr and exit status reach the caller unchanged (the
 * workflow steps capture stdout with `$( )` and propagate the status under
 * `set -e`), that a missing toolkit is a refusal rather than an empty base, and
 * that every workflow step calling it is preceded by the action that makes the
 * toolkit checkout. The tool is a STUB that records its argv, so the wrapper's
 * own behaviour is what each case observes.
 */
class PrBaseSnapshotTest extends TestCase
{
    private const WRAPPER = 'bin/pr-base-snapshot.sh';

    private const TOOLKIT_STEP = './.github/actions/agent-board-toolkit';

    /** @var list<string> */
    private array $trees = [];

    protected function tearDown(): void
    {
        foreach ($this->trees as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }
        $this->trees = [];
        parent::tearDown();
    }

    /** A fake toolkit checkout whose `bin/pr-base-snapshot` records its argv and answers as told. */
    private function fakeToolkit(string $stdout, string $stderr, int $rc): string
    {
        $dir = sys_get_temp_dir().'/pr-base-'.bin2hex(random_bytes(6));
        $this->trees[] = $dir;
        mkdir($dir.'/bin', 0o777, true);
        file_put_contents($dir.'/bin/pr-base-snapshot', implode("\n", [
            '#!/usr/bin/env bash',
            'printf "%s\n" "$@" > '.escapeshellarg($dir.'/argv'),
            'printf "%s" '.escapeshellarg($stdout),
            'printf "%s" '.escapeshellarg($stderr).' >&2',
            'exit '.$rc,
            '',
        ]));
        chmod($dir.'/bin/pr-base-snapshot', 0o755);

        return $dir;
    }

    /** @return array{0:int,1:string,2:string} [exit code, stdout, stderr] */
    private function runWrapper(?string $toolkitDir, string ...$args): array
    {
        $errFile = tempnam(sys_get_temp_dir(), 'pr-base-err-');
        $this->trees[] = (string) $errFile;
        $env = $toolkitDir === null ? 'env -u AGENT_BOARD_TOOLKIT_DIR ' : 'env AGENT_BOARD_TOOLKIT_DIR='.escapeshellarg($toolkitDir).' ';
        $cmd = $env.escapeshellarg(base_path(self::WRAPPER))
            .implode('', array_map(fn (string $a): string => ' '.escapeshellarg($a), $args))
            .' 2>'.escapeshellarg((string) $errFile);

        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);

        return [$rc, implode("\n", $out), (string) file_get_contents((string) $errFile)];
    }

    public function test_it_passes_the_head_sha_as_the_toolkit_s_head_flag_and_prints_only_its_sha(): void
    {
        $sha = str_repeat('b', 40);
        $toolkit = $this->fakeToolkit($sha."\n", '', 0);

        [$rc, $stdout, $stderr] = $this->runWrapper($toolkit, str_repeat('a', 40));

        $this->assertSame(0, $rc, $stderr);
        $this->assertSame("--head\n".str_repeat('a', 40)."\n", (string) file_get_contents($toolkit.'/argv'));
        $this->assertSame($sha, $stdout);
        $this->assertSame('', $stderr);
    }

    public function test_the_toolkit_s_refusal_reaches_the_caller_with_its_own_exit_code(): void
    {
        // 4 is the toolkit's "HEAD is not a two-parent merge". The callers run under
        // `set -e`, so the status must survive the wrapper unchanged.
        $toolkit = $this->fakeToolkit('', "::error::HEAD is not a two-parent merge commit\n", 4);

        [$rc, $stdout, $stderr] = $this->runWrapper($toolkit, str_repeat('a', 40));

        $this->assertSame(4, $rc);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('not a two-parent merge commit', $stderr);
    }

    public function test_an_unset_toolkit_dir_is_a_refusal_not_an_empty_base(): void
    {
        [$rc, $stdout, $stderr] = $this->runWrapper(null, str_repeat('a', 40));

        $this->assertSame(2, $rc);
        $this->assertSame('', $stdout, 'a caller capturing stdout would treat any line as a revision');
        $this->assertStringContainsString('AGENT_BOARD_TOOLKIT_DIR', $stderr);
        $this->assertStringContainsString('.github/actions/agent-board-toolkit', $stderr);
    }

    public function test_a_toolkit_dir_without_the_tool_is_a_refusal(): void
    {
        $dir = sys_get_temp_dir().'/pr-base-'.bin2hex(random_bytes(6));
        $this->trees[] = $dir;
        mkdir($dir.'/bin', 0o777, true);

        [$rc, $stdout] = $this->runWrapper($dir, str_repeat('a', 40));

        $this->assertSame(2, $rc);
        $this->assertSame('', $stdout);
    }

    public function test_every_workflow_step_calling_the_wrapper_comes_after_the_toolkit_checkout(): void
    {
        $callers = 0;
        foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $file) {
            $wf = Yaml::parseFile($file);
            foreach ($wf['jobs'] ?? [] as $jobId => $job) {
                $toolkitAt = null;
                foreach ($job['steps'] ?? [] as $i => $step) {
                    if (($step['uses'] ?? null) === self::TOOLKIT_STEP) {
                        $toolkitAt ??= $i;
                    }
                    if (! str_contains((string) ($step['run'] ?? ''), self::WRAPPER)) {
                        continue;
                    }
                    $callers++;
                    $where = basename($file).' job '.$jobId.' step '.$i;
                    $this->assertNotNull($toolkitAt, "{$where} runs ".self::WRAPPER.' with no '.self::TOOLKIT_STEP.' step before it');
                    // A conditional caller needs the checkout under at least the same condition.
                    $this->assertSame(
                        $step['if'] ?? null,
                        $job['steps'][$toolkitAt]['if'] ?? null,
                        "{$where}: the toolkit checkout must run under the same `if:` as its caller",
                    );
                }
            }
        }

        // The population is derived from the files, so it cannot be empty by accident:
        // with no caller found, this case would be checking nothing.
        $this->assertGreaterThan(0, $callers, 'no workflow step calls '.self::WRAPPER.' — the scan is reading nothing');
    }
}
