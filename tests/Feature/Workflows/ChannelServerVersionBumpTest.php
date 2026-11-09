<?php

namespace Tests\Feature\Workflows;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Executes the REAL `run:` block of the `version-bump-guard` job's bump step out of
 * `.github/workflows/channel-server-supply-chain.yml`, under `bash`, in a throwaway git
 * repository whose HEAD is a `--no-ff` PR merge (GitHub never fast-forwards
 * `refs/pull/<n>/merge`), so a re-implementation of the predicate cannot drift from it.
 *
 * The throwaway repository carries copies of the real `bin/build-client-pack.py` and
 * `bin/pr-base-snapshot.sh`; the toolkit tool the wrapper execs is a stub printing
 * `HEAD^1`, which is the derivation's answer on a two-parent merge.
 */
class ChannelServerVersionBumpTest extends TestCase
{
    private const STEP = 'Require a package.json version bump';

    private const CHANNEL = 'examples/channel-servers';

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

    private function stepScript(): string
    {
        $wf = Yaml::parseFile(base_path('.github/workflows/channel-server-supply-chain.yml'));
        foreach ($wf['jobs']['version-bump-guard']['steps'] as $step) {
            if (str_starts_with((string) ($step['name'] ?? ''), self::STEP)) {
                $this->assertSame('bash', $step['shell'] ?? null, 'the step must pin bash');

                return (string) $step['run'];
            }
        }
        $this->fail("no step named like '".self::STEP."' in channel-server-supply-chain.yml");
    }

    private function git(string $root, string $args): void
    {
        $out = [];
        $rc = 0;
        exec('git -C '.escapeshellarg($root).' -c user.name=t -c user.email=t@example.invalid -c commit.gpgsign=false '
            .$args.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, "git {$args}:\n".implode("\n", $out));
    }

    private function write(string $root, string $path, string $content): void
    {
        @mkdir(dirname($root.'/'.$path), 0o777, true);
        file_put_contents($root.'/'.$path, $content);
    }

    /**
     * A repository at a channel-server base, a PR branch that writes $changes, and HEAD at the
     * `--no-ff` merge of that branch into the base.
     *
     * @param  array<string,string>  $changes  path under examples/channel-servers/ => new content
     * @return array{0:string,1:string} [repository root, PR head sha]
     */
    private function prMerge(array $changes, ?string $packScript = null): array
    {
        $root = sys_get_temp_dir().'/cs-bump-'.bin2hex(random_bytes(6));
        $this->trees[] = $root;
        mkdir($root.'/bin', 0o777, true);
        file_put_contents($root.'/bin/build-client-pack.py', $packScript ?? (string) file_get_contents(base_path('bin/build-client-pack.py')));
        copy(base_path('bin/pr-base-snapshot.sh'), $root.'/bin/pr-base-snapshot.sh');
        chmod($root.'/bin/pr-base-snapshot.sh', 0o755);

        $this->git($root, 'init -q -b main');
        $this->write($root, self::CHANNEL.'/package.json', '{"name":"x","version":"1.0.0"}'."\n");
        $this->write($root, self::CHANNEL.'/server.mjs', "// shipped\n");
        $this->write($root, self::CHANNEL.'/tests/server.test.mjs', "// a test\n");
        $this->write($root, self::CHANNEL.'/docs/notes.md', "notes\n");
        $this->git($root, 'add '.self::CHANNEL);
        $this->git($root, 'commit -qm base');

        $this->git($root, 'checkout -qb pr');
        foreach ($changes as $path => $content) {
            $this->write($root, self::CHANNEL.'/'.$path, $content);
        }
        $this->git($root, 'add '.self::CHANNEL);
        $this->git($root, 'commit -qm change');
        $head = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));

        $this->git($root, 'checkout -q main');
        $this->git($root, 'merge -q --no-ff -m merge pr');

        return [$root, $head];
    }

    /** @return array{0:int,1:string} [exit code, combined output] */
    private function runStep(string $root, string $headSha): array
    {
        $toolkit = $root.'/.toolkit';
        mkdir($toolkit.'/bin', 0o777, true);
        file_put_contents($toolkit.'/bin/pr-base-snapshot', "#!/usr/bin/env bash\ngit rev-parse HEAD^1\n");
        chmod($toolkit.'/bin/pr-base-snapshot', 0o755);

        $out = [];
        $rc = 0;
        exec('cd '.escapeshellarg($root)
            .' && env HEAD_SHA='.escapeshellarg($headSha).' AGENT_BOARD_TOOLKIT_DIR='.escapeshellarg($toolkit)
            .' bash -c '.escapeshellarg($this->stepScript()).' 2>&1', $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    public function test_a_diff_touching_only_tests_passes_without_a_bump(): void
    {
        [$root, $head] = $this->prMerge(['tests/server.test.mjs' => "// a changed test\n"]);

        [$rc, $out] = $this->runStep($root, $head);

        $this->assertSame(0, $rc, "the client pack does not ship tests/, so a tests-only PR needs no bump:\n".$out);
        $this->assertStringContainsString('No shipped channel-server files changed', $out);
    }

    public function test_a_diff_touching_a_shipped_file_without_a_bump_is_refused(): void
    {
        [$root, $head] = $this->prMerge(['server.mjs' => "// changed\n"]);

        [$rc, $out] = $this->runStep($root, $head);

        $this->assertSame(1, $rc, "a shipped-file change must still require a bump:\n".$out);
        $this->assertStringContainsString("package.json version is still '1.0.0'", $out);
        $this->assertStringContainsString(self::CHANNEL.'/server.mjs', $out);
    }

    public function test_a_diff_touching_tests_and_a_shipped_file_without_a_bump_is_refused(): void
    {
        [$root, $head] = $this->prMerge([
            'tests/server.test.mjs' => "// a changed test\n",
            'server.mjs' => "// changed\n",
        ]);

        [$rc, $out] = $this->runStep($root, $head);

        $this->assertSame(1, $rc, "a tests/ change alongside a shipped one must not exempt the PR:\n".$out);
        $this->assertStringContainsString(self::CHANNEL.'/server.mjs', $out);
        $this->assertStringNotContainsString(self::CHANNEL.'/tests/server.test.mjs', $out,
            'the listed files are the shipped ones only');
    }

    public function test_a_shipped_file_change_with_a_bump_passes(): void
    {
        [$root, $head] = $this->prMerge([
            'server.mjs' => "// changed\n",
            'package.json' => '{"name":"x","version":"1.0.1"}'."\n",
        ]);

        [$rc, $out] = $this->runStep($root, $head);

        $this->assertSame(0, $rc, "a bumped shipped change must pass:\n".$out);
        $this->assertStringContainsString("'1.0.0' -> '1.0.1'", $out);
    }

    /**
     * The step must read the excluded subtrees from build-client-pack.py rather than carry its
     * own copy: with the list moved to a different subtree, the verdicts move with it.
     */
    public function test_the_step_follows_build_client_pack_s_excluded_subtrees(): void
    {
        $source = (string) file_get_contents(base_path('bin/build-client-pack.py'));
        $moved = str_replace('EXCLUDED_CHANNEL_SUBTREES = ("tests/",)', 'EXCLUDED_CHANNEL_SUBTREES = ("docs/",)', $source, $count);
        $this->assertSame(1, $count, 'build-client-pack.py no longer declares EXCLUDED_CHANNEL_SUBTREES in the shape this test rewrites');

        [$root, $head] = $this->prMerge(['tests/server.test.mjs' => "// a changed test\n"], $moved);
        [$rc, $out] = $this->runStep($root, $head);
        $this->assertSame(1, $rc, "with tests/ no longer excluded by the pack, a tests-only PR needs a bump:\n".$out);

        [$root, $head] = $this->prMerge(['docs/notes.md' => "changed\n"], $moved);
        [$rc, $out] = $this->runStep($root, $head);
        $this->assertSame(0, $rc, "with docs/ excluded by the pack, a docs-only PR needs no bump:\n".$out);
    }
}
