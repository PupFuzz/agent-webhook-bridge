<?php

namespace Tests\Feature\Workflows;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Executes the REAL bash of `auto-tag-version.yml`'s release-publishing step —
 * its `run:` block is extracted from the YAML and driven under `bash` in a
 * throwaway tree holding the real `bin/changelog-section.py`, with `gh` stubbed
 * on PATH. The step's only outward action is `gh release create`, and the
 * artifact under test is the notes file it hands that call.
 */
class ReleaseNotesPublishTest extends TestCase
{
    private const PUBLISH_STEP = 'Create GitHub Release from the CHANGELOG section';

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

    private function publishScript(): string
    {
        $wf = Yaml::parseFile(base_path('.github/workflows/auto-tag-version.yml'));
        foreach ($wf['jobs']['auto-tag']['steps'] as $step) {
            if (str_starts_with((string) ($step['name'] ?? ''), self::PUBLISH_STEP)) {
                $this->assertSame('bash', $step['shell'] ?? null);

                return (string) $step['run'];
            }
        }
        $this->fail("no step named like '".self::PUBLISH_STEP."' in auto-tag-version.yml");
    }

    private function changelog(string ...$released): string
    {
        return "# Changelog\n\n## [Unreleased]\n\n- pending\n".implode('', $released);
    }

    /**
     * @return array{0:int,1:string,2:string} [exit code, output, release-notes body]
     */
    private function runPublishStep(string $changelog, string $version, ?string $releaseConfig = '{}'): array
    {
        $dir = sys_get_temp_dir().'/release-notes-publish-'.bin2hex(random_bytes(6));
        $this->trees[] = $dir;
        mkdir($dir.'/docs', 0o777, true);
        mkdir($dir.'/bin', 0o777, true);
        mkdir($dir.'/stubbin', 0o777, true);
        file_put_contents($dir.'/docs/CHANGELOG.md', $changelog);
        copy(base_path('bin/changelog-section.py'), $dir.'/bin/changelog-section.py');
        if ($releaseConfig !== null) {
            file_put_contents($dir.'/.release-pr.json', $releaseConfig);
        }
        // `release view` must report ABSENT (exit 1) or the step short-circuits
        // as idempotent and never builds a body at all.
        file_put_contents($dir.'/stubbin/gh', "#!/bin/bash\nif [ \"\$2\" = view ]; then exit 1; fi\nexit 0\n");
        chmod($dir.'/stubbin/gh', 0o755);

        $env = [
            'PATH' => $dir.'/stubbin:'.getenv('PATH'),
            'GH_TOKEN' => 'stub',
            'VERSION' => $version,
            'GITHUB_REPOSITORY' => 'PupFuzz/agent-webhook-bridge',
        ];
        $assignments = '';
        foreach ($env as $k => $v) {
            $assignments .= $k.'='.escapeshellarg($v).' ';
        }
        $out = [];
        $rc = 0;
        exec('cd '.escapeshellarg($dir).' && env '.$assignments.'bash -c '.escapeshellarg($this->publishScript()).' 2>&1', $out, $rc);

        return [$rc, implode("\n", $out), (string) @file_get_contents($dir.'/release-notes.md')];
    }

    private function oversizeChangelog(int $lines): string
    {
        $huge = str_repeat("a padded changelog line that carries no meaning\n", $lines);

        return $this->changelog("\n## [1.1.0] - 2026-01-01\n\n".$huge);
    }

    public function test_a_fitting_section_is_published_verbatim(): void
    {
        $changelog = $this->changelog("\n## [1.1.0] - 2026-01-01\n\nthe entry\n");
        [$rc, $out, $notes] = $this->runPublishStep($changelog, '1.1.0');

        $this->assertSame(0, $rc, $out);
        $this->assertSame("## [1.1.0] - 2026-01-01\n\nthe entry\n", $notes);
    }

    public function test_an_oversize_section_is_truncated_with_a_pointer_instead_of_failing(): void
    {
        // card#5972: without this the tag exists and the Release does not.
        [$rc, $out, $notes] = $this->runPublishStep($this->oversizeChangelog(3_000), '1.1.0');

        $this->assertSame(0, $rc, $out);
        $this->assertLessThanOrEqual(125_000, strlen($notes));
        $this->assertStringStartsWith("## [1.1.0] - 2026-01-01\n", $notes);
        $this->assertStringContainsString(
            'https://github.com/PupFuzz/agent-webhook-bridge/blob/v1.1.0/docs/CHANGELOG.md',
            $notes,
        );
    }

    public function test_the_limit_is_read_from_the_release_config(): void
    {
        // ~4.9 KB of section against a configured 2000-byte limit: truncated only
        // if the step hands the configured figure to the extractor.
        [$rc, $out, $notes] = $this->runPublishStep(
            $this->oversizeChangelog(100),
            '1.1.0',
            '{"changelog": {"release_body_limit_bytes": 2000}}',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertLessThanOrEqual(2_000, strlen($notes));
        $this->assertStringContainsString('2000-byte release-body limit', $notes);
    }

    public function test_an_absent_limit_key_publishes_under_the_extractors_default(): void
    {
        // A bare `jq .key` prints `null` here, `--max-bytes null` is an argparse
        // usage error, and the step would exit 1 after the tag is minted.
        [$rc, $out, $notes] = $this->runPublishStep($this->oversizeChangelog(100), '1.1.0', '{}');

        $this->assertSame(0, $rc, $out);
        $this->assertStringStartsWith("## [1.1.0] - 2026-01-01\n", $notes);
        $this->assertStringNotContainsString('release-body limit', $notes);
    }

    public function test_an_absent_section_still_publishes_a_generated_note(): void
    {
        // Fail-soft here is deliberate and card#5910's answer refused to change
        // it: failing would leave a merged release untagged. The release PR is
        // where a missing section is refused (release-artifacts-gate.yml).
        [$rc, $out, $notes] = $this->runPublishStep($this->changelog(), '1.1.0');

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('WARNING: no docs/CHANGELOG.md section', $out);
        $this->assertSame("Release v1.1.0. See docs/CHANGELOG.md.\n", $notes);
    }
}
