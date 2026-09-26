<?php

namespace Tests\Feature\Workflows;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * `bin/gen-client-capabilities.mjs` driven against throwaway git repositories whose history
 * this test writes, so every `since` / `removed_in` it asserts is one the history was built to
 * produce (card#10566 / DL-425).
 *
 * The hazards pinned here, each with a history that trips it:
 *   - a release merge on `main` must date a tool exactly as `dev` does (a first-parent walk
 *     from main sees one state per release and dates everything late);
 *   - a work-in-progress commit that carries the OLD version is not a release of it;
 *   - two trees introduced under one version declare only what both declared;
 *   - an introducing commit whose literal cannot be evaluated, or evaluates differently from
 *     run to run, or whose version is not bare X.Y.Z, is REFUSED BY NAME (exit 2) — never
 *     skipped, never guessed;
 *   - a shallow clone is refused, because missing history dates everything to the oldest
 *     commit present.
 *
 * It also pins WHERE CI runs `--check`: in the required SQLite job of a workflow with no path
 * filter, with the full history fetched.
 */
class GenClientCapabilitiesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/gen-caps-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** @param list<string> $args */
    private function git(string $repo, array $args): string
    {
        $p = new Process(['git', '-C', $repo, '-c', 'user.name=fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', ...$args]);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), 'git '.implode(' ', $args).': '.$p->getErrorOutput());

        return trim($p->getOutput());
    }

    private function newRepo(string $name = 'repo'): string
    {
        $repo = $this->root.'/'.$name;
        mkdir($repo.'/examples/channel-servers', 0o777, true);
        mkdir($repo.'/resources', 0o777, true);
        $this->git($repo, ['init', '-q', '-b', 'dev']);

        return $repo;
    }

    /** @param array<string, list<string>> $tools */
    private static function literal(array $tools): string
    {
        $entries = '';
        foreach ($tools as $tool => $args) {
            $props = implode(' ', array_map(fn (string $a) => "{$a}: { type: 'string', description: 'about ' + '{$a}' },", $args));
            $entries .= "  {\n    name: '{$tool}',\n    inputSchema: { type: 'object', properties: { {$props} } },\n  },\n";
        }

        return "import fs from 'node:fs';\n\nconst TOOL_DEFINITIONS = [\n{$entries}];\n\nexport default TOOL_DEFINITIONS;\n";
    }

    /**
     * Write one state of the subject directory and commit it. `$tools` null means a server with
     * no TOOL_DEFINITIONS at all; `$source` replaces the generated server file outright.
     *
     * @param  array<string, list<string>>|null  $tools
     */
    private function commit(string $repo, string $version, ?array $tools, ?string $source = null, string $message = 'state'): string
    {
        $dir = $repo.'/examples/channel-servers';
        file_put_contents($dir.'/package.json', json_encode(['name' => 'fixture', 'version' => $version], JSON_PRETTY_PRINT)."\n");
        file_put_contents($dir.'/agent-webhook-bridge-channel.mjs', $source ?? ($tools === null ? "console.log('no tools');\n" : self::literal($tools)));
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['commit', '-q', '--allow-empty', '-m', $message]);

        return $this->git($repo, ['rev-parse', 'HEAD']);
    }

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private function gen(string $repo, string ...$flags): array
    {
        $p = new Process(['node', base_path('bin/gen-client-capabilities.mjs'), '--repo', $repo, ...$flags]);
        $p->run();

        return [(int) $p->getExitCode(), $p->getOutput(), $p->getErrorOutput()];
    }

    /** @return array<string, mixed> */
    private function generated(string $repo): array
    {
        [$rc, , $err] = $this->gen($repo);
        $this->assertSame(0, $rc, $err);

        return json_decode((string) file_get_contents($repo.'/resources/client-capabilities.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $table
     * @return array<string, array{0: string, 1: ?string}> `tool` / `tool.arg` => [since, removed_in]
     */
    private static function spans(array $table): array
    {
        $out = [];
        foreach ($table['tools'] as $tool => $span) {
            $out[$tool] = [$span['since'], $span['removed_in']];
            foreach ($span['arguments'] as $arg => $a) {
                $out["{$tool}.{$arg}"] = [$a['since'], $a['removed_in']];
            }
        }
        ksort($out);

        return $out;
    }

    /** dev history used by several tests: tools arrive, an argument is added, one is removed. */
    private function devHistory(string $repo): void
    {
        $this->commit($repo, '0.1.0', null);
        $this->commit($repo, '0.2.0', ['my_cards' => ['stage']]);
        $this->commit($repo, '0.3.0', ['my_cards' => ['stage', 'limit'], 'old_tool' => []]);
        $this->commit($repo, '0.9.0', ['my_cards' => ['stage', 'limit']]);
        $this->commit($repo, '0.10.0', ['my_cards' => ['limit']]);
    }

    public function test_spans_are_dated_by_the_version_that_introduced_and_dropped_each(): void
    {
        $repo = $this->newRepo();
        $this->devHistory($repo);

        $table = $this->generated($repo);

        $this->assertSame('0.10.0', $table['current_client_version']);
        $this->assertSame([
            'my_cards' => ['0.2.0', null],
            'my_cards.limit' => ['0.3.0', null],
            // Numeric ordering: 0.10.0 is after 0.9.0, not between 0.1.0 and 0.2.0.
            'my_cards.stage' => ['0.2.0', '0.10.0'],
            'old_tool' => ['0.3.0', '0.9.0'],
        ], self::spans($table));
    }

    public function test_a_release_merge_on_main_dates_every_span_exactly_as_dev_does(): void
    {
        $repo = $this->newRepo();
        $this->commit($repo, '0.1.0', null);
        $this->git($repo, ['branch', 'main']);
        $this->commit($repo, '0.2.0', ['my_cards' => ['stage']]);
        $this->commit($repo, '0.3.0', ['my_cards' => ['stage', 'limit'], 'old_tool' => []]);
        $this->commit($repo, '0.9.0', ['my_cards' => ['stage', 'limit']]);
        $this->git($repo, ['checkout', '-q', 'main']);
        $this->git($repo, ['merge', '-q', '--no-ff', '-m', 'release 1', 'dev']);
        $this->git($repo, ['checkout', '-q', 'dev']);
        $this->commit($repo, '0.10.0', ['my_cards' => ['limit']]);
        $this->git($repo, ['checkout', '-q', 'main']);
        $this->git($repo, ['merge', '-q', '--no-ff', '-m', 'release 2', 'dev']);

        // The control: from main, a first-parent walk sees the two release merges and nothing
        // between them, so it could not date `limit` at 0.3.0.
        $firstParent = $this->git($repo, ['log', '--first-parent', '--format=%s', 'HEAD', '--', 'examples/channel-servers/']);
        $this->assertSame("release 2\nrelease 1\nstate", $firstParent);

        $fromMain = $this->generated($repo);
        $this->git($repo, ['checkout', '-q', 'dev']);
        $fromDev = $this->generated($repo);

        $this->assertSame($fromDev, $fromMain);
        $this->assertSame(['0.3.0', null], self::spans($fromMain)['my_cards.limit']);
    }

    public function test_a_work_in_progress_commit_carrying_the_old_version_is_not_a_release_of_it(): void
    {
        $repo = $this->newRepo();
        $this->devHistory($repo);
        // On a branch: add an argument while still at 0.10.0, one commit whose literal does
        // not even parse, and only then bump.
        $this->commit($repo, '0.10.0', ['my_cards' => ['limit', 'tag']], message: 'wip');
        $this->commit($repo, '0.10.0', null, "const TOOL_DEFINITIONS = [\n  { name: 'my_cards', \n];\n", 'broken wip');
        $this->commit($repo, '0.11.0', ['my_cards' => ['limit', 'tag']]);

        $this->assertSame(['0.11.0', null], self::spans($this->generated($repo))['my_cards.tag']);
    }

    public function test_a_branch_that_bumps_first_dates_its_argument_at_the_bump(): void
    {
        $repo = $this->newRepo();
        $this->devHistory($repo);
        $this->commit($repo, '0.11.0', ['my_cards' => ['limit']], message: 'bump only');
        $this->commit($repo, '0.11.0', ['my_cards' => ['limit', 'tag']], message: 'then the argument');

        $this->assertSame(['0.11.0', null], self::spans($this->generated($repo))['my_cards.tag']);
    }

    public function test_a_version_introduced_twice_declares_only_what_both_trees_declared(): void
    {
        $repo = $this->newRepo();
        $this->commit($repo, '0.1.0', null);
        $this->commit($repo, '0.2.0', ['my_cards' => []]);
        $this->git($repo, ['checkout', '-q', '-b', 'a']);
        $this->commit($repo, '0.3.0', ['my_cards' => ['limit']]);
        $this->git($repo, ['checkout', '-q', 'dev']);
        // The other branch bumps to the same number and adds nothing.
        file_put_contents($repo.'/examples/channel-servers/README.md', "b\n");
        $this->commit($repo, '0.3.0', ['my_cards' => []]);
        $this->git($repo, ['merge', '-q', '--no-ff', '-X', 'theirs', '-m', 'merge a', 'a']);
        $this->commit($repo, '0.4.0', ['my_cards' => ['limit']]);

        $this->assertSame(['0.4.0', null], self::spans($this->generated($repo))['my_cards.limit']);
    }

    public function test_check_passes_on_the_derived_table_and_fails_on_a_hand_edit(): void
    {
        $repo = $this->newRepo();
        $this->devHistory($repo);
        $table = $this->generated($repo);
        [$rc] = $this->gen($repo, '--check');
        $this->assertSame(0, $rc);

        $table['tools']['my_cards']['arguments']['limit']['since'] = '0.2.0';
        file_put_contents($repo.'/resources/client-capabilities.json', json_encode($table, JSON_PRETTY_PRINT)."\n");
        [$rc, , $err] = $this->gen($repo, '--check');

        $this->assertSame(1, $rc);
        $this->assertStringContainsString('is NOT what the history of examples/channel-servers/ derives', $err);
    }

    public function test_hand_declared_features_survive_regeneration(): void
    {
        $repo = $this->newRepo();
        $this->devHistory($repo);
        $table = $this->generated($repo);
        $table['features'] = ['client_version_report' => '0.3.0'];
        file_put_contents($repo.'/resources/client-capabilities.json', json_encode($table));

        $this->assertSame(['client_version_report' => '0.3.0'], $this->generated($repo)['features']);
    }

    /** @return array<string, array{string, string}> */
    public static function unevaluable(): array
    {
        return [
            'syntax error' => ["const TOOL_DEFINITIONS = [\n  { name: 'my_cards',\n];\n", 'could not be evaluated deterministically'],
            'free identifier' => ["const X = 'y';\nconst TOOL_DEFINITIONS = [\n  { name: 'my_cards', inputSchema: { properties: { a: X } } },\n];\n", 'X is not defined'],
            'clock' => ["const TOOL_DEFINITIONS = [\n  { name: 'my_cards', inputSchema: { properties: { a: { description: String(Date.now()) } } } },\n];\n", 'Date is not defined'],
            'randomness' => ["const TOOL_DEFINITIONS = [\n  { name: 'my_cards', inputSchema: { properties: { a: { d: Math.random() } } } },\n];\n", 'Math.random is nondeterministic'],
            'no properties object' => ["const TOOL_DEFINITIONS = [\n  { name: 'my_cards' },\n];\n", 'no inputSchema.properties object'],
            'unclosed at a line of its own' => ["const TOOL_DEFINITIONS = [{ name: 'my_cards', inputSchema: { properties: {} } }];\n", 'does not close at a line reading'],
        ];
    }

    /**
     * The hard pass/fail: a release whose literal cannot be evaluated, or not the same way twice,
     * stops the run and NAMES the commit — the table is never written around it.
     */
    #[DataProvider('unevaluable')]
    public function test_an_introducing_commit_that_cannot_be_evaluated_is_refused_by_name(string $source, string $why): void
    {
        $repo = $this->newRepo();
        $this->commit($repo, '0.1.0', null);
        $bad = $this->commit($repo, '0.2.0', null, $source);
        $this->commit($repo, '0.3.0', ['my_cards' => ['a']]);

        [$rc, , $err] = $this->gen($repo);

        $this->assertSame(2, $rc, $err);
        $this->assertStringContainsString("commit {$bad}", $err);
        $this->assertStringContainsString($why, $err);
        $this->assertFileDoesNotExist($repo.'/resources/client-capabilities.json');
    }

    /** R3-M5: a v-prefixed (or otherwise non-bare) manifest version never reaches the table. */
    public function test_a_v_prefixed_version_in_history_is_refused_by_name(): void
    {
        $repo = $this->newRepo();
        $this->commit($repo, '0.1.0', null);
        $bad = $this->commit($repo, 'v0.2.0', ['my_cards' => []]);
        $this->commit($repo, '0.3.0', ['my_cards' => []]);

        [$rc, , $err] = $this->gen($repo);

        $this->assertSame(2, $rc, $err);
        $this->assertStringContainsString("commit {$bad}: package.json version \"v0.2.0\" is not bare X.Y.Z", $err);
    }

    public function test_a_v_prefixed_working_tree_version_is_refused(): void
    {
        $repo = $this->newRepo();
        $this->commit($repo, '0.1.0', null);
        file_put_contents($repo.'/examples/channel-servers/package.json', '{"version": "v0.2.0"}');

        [$rc, , $err] = $this->gen($repo, '--current');

        $this->assertSame(2, $rc);
        $this->assertStringContainsString('working tree: package.json version "v0.2.0" is not bare X.Y.Z', $err);
    }

    public function test_a_tool_dropped_and_restored_is_refused_rather_than_guessed(): void
    {
        $repo = $this->newRepo();
        $this->commit($repo, '0.1.0', ['my_cards' => ['limit']]);
        $this->commit($repo, '0.2.0', ['my_cards' => []]);
        $this->commit($repo, '0.3.0', ['my_cards' => ['limit']]);

        [$rc, , $err] = $this->gen($repo);

        $this->assertSame(2, $rc);
        $this->assertStringContainsString('my_cards.limit is declared, dropped at 0.2.0 and declared again at 0.3.0', $err);
    }

    public function test_a_shallow_clone_is_refused(): void
    {
        $repo = $this->newRepo();
        $this->devHistory($repo);
        $shallow = $this->root.'/shallow';
        $p = new Process(['git', 'clone', '-q', '--depth', '1', 'file://'.$repo, $shallow]);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        mkdir($shallow.'/resources');

        [$rc, , $err] = $this->gen($shallow);

        $this->assertSame(2, $rc);
        $this->assertStringContainsString('SHALLOW clone', $err);
    }

    /**
     * r3-m7: the `--check` step must run on EVERY pull request — one touching only the table or
     * the generator included — and must be able to block a merge. So it is a step of the SQLite
     * job (a required check on dev and main; DL-040 pins its name), in a workflow whose
     * pull_request trigger carries no path filter, and its checkout fetches the whole history.
     */
    public function test_ci_runs_check_in_the_required_unfiltered_job_with_full_history(): void
    {
        $wf = Yaml::parseFile(base_path('.github/workflows/laravel-tests.yml'));
        $trigger = $wf['on']['pull_request'] ?? $wf[true]['pull_request'] ?? null;
        $this->assertIsArray($trigger);
        $this->assertArrayNotHasKey('paths', $trigger);
        $this->assertArrayNotHasKey('paths-ignore', $trigger);

        $job = $wf['jobs']['phpunit'];
        $this->assertSame('PHPUnit + Pint + PHPStan (SQLite)', $job['name']);

        $steps = $job['steps'];
        $checkout = $steps[0];
        $this->assertStringStartsWith('actions/checkout@', $checkout['uses']);
        $this->assertSame(0, $checkout['with']['fetch-depth'] ?? null, 'the checkout must fetch the whole history, or --check refuses a shallow clone');

        $runs = array_map(fn (array $s): string => (string) ($s['run'] ?? ''), $steps);
        $this->assertContains('node bin/gen-client-capabilities.mjs --check', $runs);
    }

    /**
     * Every PHPUnit job runs the generator (this class and `ClientCapabilityTableTest`), so
     * each gets Node from the shared `setup-app` action — explicitly, and at the version the
     * channel-server workflow runs the server's own suite on — rather than from whatever the
     * runner image happens to carry.
     */
    public function test_every_phpunit_job_gets_node_at_the_channel_server_workflows_version(): void
    {
        $nodeOf = function (array $steps): ?string {
            foreach ($steps as $step) {
                if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/setup-node@')) {
                    return (string) $step['with']['node-version'];
                }
            }

            return null;
        };
        $supply = Yaml::parseFile(base_path('.github/workflows/channel-server-supply-chain.yml'));
        $expected = $nodeOf($supply['jobs']['channel-server-deps']['steps']);
        $this->assertNotNull($expected, 'the channel-server workflow no longer sets up Node — re-anchor this test');

        $action = Yaml::parseFile(base_path('.github/actions/setup-app/action.yml'));
        $this->assertSame($expected, $nodeOf($action['runs']['steps']));

        $wf = Yaml::parseFile(base_path('.github/workflows/laravel-tests.yml'));
        foreach ($wf['jobs'] as $id => $job) {
            $uses = array_map(fn (array $s): string => (string) ($s['uses'] ?? ''), $job['steps']);
            $this->assertContains('./.github/actions/setup-app', $uses, "laravel-tests.yml job {$id} runs PHPUnit without the setup-app action, so without Node");
        }
    }
}
