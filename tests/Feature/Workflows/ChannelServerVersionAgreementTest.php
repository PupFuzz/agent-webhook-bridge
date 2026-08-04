<?php

namespace Tests\Feature\Workflows;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Executes the REAL bash out of `.github/workflows/channel-server-supply-chain.yml`
 * — the step's `run:` block is extracted from the YAML and driven under `bash`
 * against a throwaway tree, so this cannot drift from what CI runs the way a
 * re-implementation of the predicate would. Same shape as {@see PrTitleLintTest}.
 *
 * WHY THE FIXTURES ARE REAL HISTORY, not hand-written JSON (card#5232). The
 * drifted pair is `examples/channel-servers/` at `4abe8e3` — the last commit at
 * which the defect was live on `dev`, lock `0.7.1` against a manifest at `0.8.3`. The guard this file
 * covers did not exist then, so the state it must reject is a state the repo
 * actually shipped rather than one invented to fit the assertion. It stopped
 * being live at `6c4f504`, and NOT because anyone fixed it: an `npm` run resynced
 * the lock as a side effect. That is the whole argument for the guard — the field
 * was unwatched, so its agreement was luck.
 *
 * WHAT THE ASSERTIONS ARE TIED TO. Both version sites the lockfile carries
 * (`.version` and `.packages[""].version`) are driven independently, because the
 * defect class here is precisely a guard that reads one of N fields that must
 * agree — covering only the root would re-mint it one level down (card#5910).
 */
class ChannelServerVersionAgreementTest extends TestCase
{
    private const STEP = 'Require the lockfile version to agree';

    /**
     * The vendored `examples/channel-servers/` pair as it stood at `4abe8e3`, the
     * last commit at which card#5232's drift was live. Vendored rather than read
     * through `git show`, because CI checks out at the default depth of 1 and
     * `git show 4abe8e3:` returns 128 in a shallow clone (measured) — the control
     * would have redded in CI while passing locally. See the fixture's README for
     * why the files carry a `.fixture` suffix.
     */
    private const DRIFT_FIXTURE = 'Fixtures/channel-server-drift-4abe8e3';

    /** Extract one step's `run:` script from the workflow by name prefix. */
    private function stepScript(string $namePrefix): string
    {
        $wf = Yaml::parseFile(base_path('.github/workflows/channel-server-supply-chain.yml'));
        foreach ($wf['jobs']['version-bump-guard']['steps'] as $step) {
            if (str_starts_with((string) ($step['name'] ?? ''), $namePrefix)) {
                $this->assertSame('bash', $step['shell'] ?? null, 'the step must pin bash');

                return (string) $step['run'];
            }
        }
        $this->fail("no step named like '{$namePrefix}' in channel-server-supply-chain.yml");
    }

    /**
     * Materialize a throwaway tree holding just the two files the step reads, at
     * the repo-relative path the step names, and run the real script in it.
     *
     * The step's paths are deliberately NOT parameterized: a directory knob added
     * so a test could point the step elsewhere would be surface that only the test
     * uses, and the step CI runs would stop being the step this drives.
     *
     * @return array{0:int,1:string} [exit code, combined output]
     */
    private function runStepAgainst(string $manifestJson, string $lockJson): array
    {
        $root = sys_get_temp_dir().'/cs-agree-'.bin2hex(random_bytes(6));
        $dir = $root.'/examples/channel-servers';
        mkdir($dir, 0o777, true);
        file_put_contents($dir.'/package.json', $manifestJson);
        file_put_contents($dir.'/package-lock.json', $lockJson);

        $rc = 0;
        $out = [];
        exec('cd '.escapeshellarg($root).' && bash -c '.escapeshellarg($this->stepScript(self::STEP)).' 2>&1', $out, $rc);

        exec('rm -rf '.escapeshellarg($root));

        return [$rc, implode("\n", $out)];
    }

    /** Read one vendored fixture file. Absence is a loud failure, never a skip. */
    private function fixture(string $name): string
    {
        $path = __DIR__.'/../../'.self::DRIFT_FIXTURE.'/'.$name.'.fixture';
        $this->assertFileExists($path, 'the positive control cannot run without its fixture — a missing one must fail, not quietly pass');

        return (string) file_get_contents($path);
    }

    public function test_the_live_tree_agrees_and_the_step_passes(): void
    {
        [$rc, $out] = $this->runStepAgainst(
            (string) file_get_contents(base_path('examples/channel-servers/package.json')),
            (string) file_get_contents(base_path('examples/channel-servers/package-lock.json')),
        );

        $this->assertSame(0, $rc, "the working tree must satisfy the guard it ships with:\n".$out);
        $this->assertStringContainsString('lockfile version agrees', $out);
    }

    /**
     * The positive control this whole file exists for: the guard must reject the
     * state the repo actually shipped. A guard that has never been seen to fail on
     * a real defect is a decoration (canon #9).
     */
    public function test_the_historical_drift_is_rejected(): void
    {
        $manifest = $this->fixture('package.json');
        $lock = $this->fixture('package-lock.json');

        // The fixture must still CARRY the drift. If it were ever resynced — by an
        // automated dependency bump or a well-meaning edit — the control would go
        // green over a guard nothing was testing any more.
        $this->assertSame('0.8.3', json_decode($manifest, true)['version']);
        $this->assertSame('0.7.1', json_decode($lock, true)['version']);

        [$rc, $out] = $this->runStepAgainst($manifest, $lock);

        $this->assertSame(1, $rc, "the guard must reject the drift the repo actually shipped:\n".$out);
        $this->assertStringContainsString("is '0.7.1' but package.json version is '0.8.3'", $out,
            'the error must name both observed values — an operator cannot resync from "they disagree"');
    }

    /**
     * Each lockfile version site is driven on its own, so covering the root while
     * leaving `packages[""]` unread would red here rather than pass as coverage.
     *
     * @return array<string,array{0:string}>
     */
    public static function lockVersionSites(): array
    {
        return ['root' => ['.version'], 'packages-self' => ['.packages[""].version']];
    }

    #[DataProvider('lockVersionSites')]
    public function test_each_lockfile_version_site_is_read(string $jqPath): void
    {
        $manifest = '{"name":"x","version":"1.2.3"}';
        $lock = json_decode('{"name":"x","version":"1.2.3","lockfileVersion":3,"packages":{"":{"name":"x","version":"1.2.3"}}}', true);

        // Drift exactly one site; every other site keeps agreeing.
        if ($jqPath === '.version') {
            $lock['version'] = '9.9.9';
        } else {
            $lock['packages']['']['version'] = '9.9.9';
        }

        [$rc, $out] = $this->runStepAgainst($manifest, (string) json_encode($lock));

        $this->assertSame(1, $rc, "a disagreement at {$jqPath} alone must fail the step:\n".$out);
        $this->assertStringContainsString($jqPath, $out, 'the error must name WHICH site disagrees');
    }

    /**
     * An absent manifest version makes every lock site "agree" at jq's `null`, so
     * the comparison alone would pass a tree with no drift signal at all — the
     * guard's own blind spot, closed explicitly rather than left to the compare.
     */
    public function test_a_manifest_with_no_version_fails_rather_than_agreeing_at_null(): void
    {
        [$rc, $out] = $this->runStepAgainst(
            '{"name":"x"}',
            '{"name":"x","lockfileVersion":3,"packages":{"":{"name":"x"}}}',
        );

        $this->assertSame(1, $rc, "a versionless manifest must fail, not compare null to null:\n".$out);
        $this->assertStringContainsString('has no version field', $out);
    }
}
