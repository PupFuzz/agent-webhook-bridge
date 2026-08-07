<?php

namespace Tests\Feature\Workflows;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Executes the REAL bash out of `.github/workflows/changelog-gate.yml` and the
 * release-publishing step of `.github/workflows/auto-tag-version.yml` — each
 * step's `run:` block is extracted from the YAML and driven under `bash`
 * against a throwaway git repository, so these cannot drift from what CI runs
 * the way a re-implementation of the predicate would. Same shape as
 * {@see PrTitleLintTest} and {@see ChannelServerVersionAgreementTest}.
 *
 * WHY A REAL GIT REPOSITORY AND NOT A FLAT DIRECTORY. Both gate steps classify
 * the PR from `git diff --name-only BASE HEAD`, and the tokenless arm reads the
 * changelog AT THE BASE through `git show`. A fixture that handed the step a
 * pre-computed file list would be asserting the fixture; the base/head SHAs are
 * genuine workflow inputs (`github.event.pull_request.base.sha`), so the test
 * supplies genuine ones.
 *
 * THE STEPS CALL THE REAL `bin/changelog-section.py`. It is copied into each
 * throwaway tree at the repo-relative path the steps name, so a change to the
 * extractor that broke either caller reds here as well as in
 * `bin/test_changelog_section.py`.
 */
class ChangelogGateTest extends TestCase
{
    private const RELEASE_STEP = 'Release PR — VERSION has a CHANGELOG section';

    private const FEATURE_STEP = 'Feature PR — the change is named in the CHANGELOG';

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

    /** Extract one step's `run:` script from a workflow job, by name prefix. */
    private function stepScript(string $workflow, string $job, string $namePrefix): string
    {
        $wf = Yaml::parseFile(base_path(".github/workflows/{$workflow}"));
        foreach ($wf['jobs'][$job]['steps'] as $step) {
            if (str_starts_with((string) ($step['name'] ?? ''), $namePrefix)) {
                $this->assertSame('bash', $step['shell'] ?? null, 'the step must pin bash — the scripts use [[ =~ ]] and pipefail');

                return (string) $step['run'];
            }
        }
        $this->fail("no step named like '{$namePrefix}' in {$workflow}");
    }

    /**
     * Materialize a throwaway git repo with a base commit and a head commit.
     *
     * @param  array<string,string>  $base  repo-relative path => contents
     * @param  array<string,string>  $head  files as they stand on the PR head
     * @return array{dir:string,base:string,head:string}
     */
    private function makeRepo(array $base, array $head, bool $withExtractor = true): array
    {
        $dir = sys_get_temp_dir().'/changelog-gate-'.bin2hex(random_bytes(6));
        $this->trees[] = $dir;
        mkdir($dir, 0o777, true);

        $git = 'git -C '.escapeshellarg($dir).' -c user.email=t@example.invalid -c user.name=t ';
        exec($git.'init -q -b main 2>&1');

        $write = function (array $files) use ($dir) {
            foreach ($files as $path => $contents) {
                $full = $dir.'/'.$path;
                if (! is_dir(dirname($full))) {
                    mkdir(dirname($full), 0o777, true);
                }
                file_put_contents($full, $contents);
            }
        };

        $write($base);
        exec($git.'add -A && '.$git.'commit -q -m base 2>&1');
        $baseSha = trim((string) shell_exec($git.'rev-parse HEAD'));

        // Only paths named in $head are rewritten; everything else carries over,
        // which is what makes "this PR touched no app/ file" expressible.
        $write($head);
        exec($git.'add -A && '.$git.'commit -q --allow-empty -m head 2>&1');
        $headSha = trim((string) shell_exec($git.'rev-parse HEAD'));

        // The steps invoke it by repo-relative path; give them the real one.
        // Committed AFTER the head commit on purpose: it must never appear in
        // the base..head diff, or every fixture would read as touching bin/.
        if ($withExtractor) {
            if (! is_dir($dir.'/bin')) {
                mkdir($dir.'/bin', 0o777, true);
            }
            copy(base_path('bin/changelog-section.py'), $dir.'/bin/changelog-section.py');
        }

        return ['dir' => $dir, 'base' => $baseSha, 'head' => $headSha];
    }

    /**
     * @param  array<string,string>  $env
     * @return array{0:int,1:string} [exit code, combined output]
     */
    private function runStep(string $script, string $dir, array $env): array
    {
        $assignments = '';
        foreach ($env as $k => $v) {
            $assignments .= $k.'='.escapeshellarg($v).' ';
        }
        $cmd = 'cd '.escapeshellarg($dir).' && env '.$assignments.'bash -c '.escapeshellarg($script).' 2>&1';

        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    private function changelog(string $unreleasedBody, string ...$released): string
    {
        return "# Changelog\n\n## [Unreleased]\n\n".$unreleasedBody."\n".implode('', $released);
    }

    // ---------------------------------------------------------------- release step

    public function test_a_pr_that_does_not_move_version_is_not_a_release_pr(): void
    {
        $repo = $this->makeRepo(
            ['VERSION' => "1.0.0\n", 'docs/CHANGELOG.md' => $this->changelog('- work'), 'app/X.php' => 'a'],
            ['app/X.php' => 'b'],
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::RELEASE_STEP),
            $repo['dir'],
            ['BASE' => $repo['base'], 'HEAD' => $repo['head']],
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('does not move VERSION', $out);
    }

    public function test_a_version_bump_with_its_changelog_section_passes(): void
    {
        $repo = $this->makeRepo(
            ['VERSION' => "1.0.0\n", 'docs/CHANGELOG.md' => $this->changelog('- work')],
            ['VERSION' => "1.1.0\n", 'docs/CHANGELOG.md' => $this->changelog('', "\n## [1.1.0] - 2026-01-01\n\nthe entry\n")],
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::RELEASE_STEP),
            $repo['dir'],
            ['BASE' => $repo['base'], 'HEAD' => $repo['head']],
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('within the release-body limit', $out);
    }

    public function test_a_version_bump_without_a_changelog_section_fails(): void
    {
        $repo = $this->makeRepo(
            ['VERSION' => "1.0.0\n", 'docs/CHANGELOG.md' => $this->changelog('- work')],
            ['VERSION' => "1.1.0\n"],
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::RELEASE_STEP),
            $repo['dir'],
            ['BASE' => $repo['base'], 'HEAD' => $repo['head']],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString("no '## [1.1.0]' section", $out);
    }

    public function test_a_section_over_the_release_body_limit_fails_at_pr_time(): void
    {
        // The v0.72.0 failure, reproduced: the tag lands and the Release does
        // not. 125,000 is the API's stated ceiling; pad past it.
        $huge = str_repeat("a padded changelog line that carries no meaning\n", 3_000);
        $repo = $this->makeRepo(
            ['VERSION' => "1.0.0\n", 'docs/CHANGELOG.md' => $this->changelog('- work')],
            ['VERSION' => "1.1.0\n", 'docs/CHANGELOG.md' => $this->changelog('', "\n## [1.1.0] - 2026-01-01\n\n".$huge)],
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::RELEASE_STEP),
            $repo['dir'],
            ['BASE' => $repo['base'], 'HEAD' => $repo['head']],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('larger than GitHub can publish', $out);
        $this->assertStringContainsString('tagged but unpublished', $out);
    }

    public function test_the_release_assertion_still_fires_when_the_diff_is_larger_than_a_pipe_buffer(): void
    {
        // REGRESSION GUARD, not a stress test. Under `pipefail`,
        // `git diff --name-only … | grep -q` reports the WRITER's status when
        // grep exits on its first match and the writer dies of SIGPIPE
        // (measured: `seq 1 5000000 | grep -qxF 3` yields 141 while matching).
        // The step captures the diff into a variable instead, so this must hold
        // however large the diff is. A "tidy" back to a pipeline reds here.
        $base = ['VERSION' => "1.0.0\n", 'docs/CHANGELOG.md' => $this->changelog('- work')];
        $head = ['VERSION' => "1.1.0\n"];
        for ($i = 0; $i < 4_000; $i++) {
            $path = sprintf('app/Padding/File%04dWithALongEnoughName.php', $i);
            $base[$path] = "x\n";
            $head[$path] = "y\n";
        }
        $repo = $this->makeRepo($base, $head);

        $bytes = strlen((string) shell_exec(
            'git -C '.escapeshellarg($repo['dir']).' diff --name-only '.$repo['base'].' '.$repo['head']
        ));
        $this->assertGreaterThan(65_536, $bytes, 'the fixture must exceed a pipe buffer or it proves nothing');

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::RELEASE_STEP),
            $repo['dir'],
            ['BASE' => $repo['base'], 'HEAD' => $repo['head']],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString("no '## [1.1.0]' section", $out);
    }

    // ---------------------------------------------------------------- feature step

    /**
     * @param  array<string,string>  $head
     * @return array{0:int,1:string}
     */
    private function runFeatureStep(array $base, array $head, string $title, string $branch): array
    {
        $repo = $this->makeRepo($base, $head);

        return $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            ['BASE' => $repo['base'], 'HEAD' => $repo['head'], 'TITLE' => $title, 'HEAD_REF' => $branch],
        );
    }

    public static function exemptBranchProvider(): iterable
    {
        yield 'dependabot' => ['dependabot/composer/foo-1.2.3'];
        yield 'release' => ['release/v1.1.0'];
        yield 'back-merge sync' => ['sync/main-to-dev-post-v1.1.0'];
        yield 'revert' => ['revert-488-fix/1234-x'];
    }

    #[DataProvider('exemptBranchProvider')]
    public function test_exempt_branches_skip_the_entry_requirement(string $branch): void
    {
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['app/X.php' => 'b'],
            'chore: something',
            $branch,
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('is exempt', $out);
    }

    public function test_a_pr_touching_neither_app_nor_bin_needs_no_entry(): void
    {
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'docs/other.md' => 'a'],
            ['docs/other.md' => 'b'],
            'docs: tidy',
            'docs/1234-tidy',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('no app/ or bin/ file', $out);
    }

    public function test_an_app_change_whose_token_is_named_in_unreleased_passes(): void
    {
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $this->changelog('- old
- the new behaviour (card#1234)'), 'app/X.php' => 'b'],
            'fix(x): stop doing the wrong thing (card#1234)',
            'fix/1234-x',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('names this PR\'s card token 1234', $out);
    }

    public function test_an_app_change_whose_token_is_absent_from_unreleased_fails(): void
    {
        // The measured defect: the entry is missing entirely, and nothing reads
        // the changelog between PRs, so it stays missing until a release is cut.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $this->changelog('- old
- an entry for some OTHER work (card#9999)'), 'app/X.php' => 'b'],
            'fix(x): stop doing the wrong thing (card#1234)',
            'fix/1234-x',
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
    }

    public function test_an_entry_in_a_released_section_does_not_satisfy_the_gate(): void
    {
        // [Unreleased] is the only section that counts: a token that appears
        // only under an already-released heading describes shipped work, not
        // this PR's.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $this->changelog('- old', "\n## [1.0.0] - 2026-01-01\n\n- shipped (card#1234)\n"), 'app/X.php' => 'b'],
            'fix(x): more work (card#1234)',
            'fix/1234-x',
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
    }

    public function test_a_dl_token_matches_its_zero_padded_spelling(): void
    {
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $this->changelog('- old
- the decision landed (DL-0276)'), 'app/X.php' => 'b'],
            'feat(x): a decision (DL-276)',
            'feat/1234-x',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('names this PR\'s dl token 276', $out);
    }

    public function test_a_zero_padded_title_token_matches_the_unpadded_changelog_spelling(): void
    {
        // The two spellings are both live: the card payload zero-pads to
        // DL-0276 while the decision log header and PR titles write DL-276.
        // The match must be symmetric — without normalizing, the padded-title
        // direction silently fails while the padded-changelog one works.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $this->changelog('- old
- the decision landed (DL-276)'), 'app/X.php' => 'b'],
            'feat(x): a decision (DL-0276)',
            'feat/1234-x',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('names this PR\'s dl token 276', $out);
    }

    public function test_the_remediation_line_spells_a_dl_token_the_way_the_repo_writes_it(): void
    {
        // A guard's remediation string is a doc surface: told to cite `dl#276`,
        // an author writes a spelling nothing in the fleet correlates, and the
        // gate reds again on the fix it asked for.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $this->changelog('- old
- unrelated work'), 'app/X.php' => 'b'],
            'feat(x): a decision (DL-276)',
            'feat/1234-x',
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString("citing 'DL-276'", $out);
        $this->assertStringNotContainsString('dl#276', $out);
    }

    public function test_a_broken_extractor_is_reported_as_a_tooling_failure_not_a_missing_section(): void
    {
        // A wrong-but-specific cause is worse than an honest generic one: if
        // this arm collapsed into the "no [Unreleased] section" message, an
        // author would go edit a changelog that is perfectly fine.
        $repo = $this->makeRepo(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['app/X.php' => 'b'],
            withExtractor: false,
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            ['BASE' => $repo['base'], 'HEAD' => $repo['head'], 'TITLE' => 'fix: x (card#1234)', 'HEAD_REF' => 'fix/1234-x'],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('unexpected', $out);
        $this->assertStringNotContainsString("no '## [Unreleased]' section", $out);
    }

    public function test_a_glued_single_digit_card_is_not_read_as_a_token(): void
    {
        // `card4` names no card under the ratified grammar (glued takes 2+
        // digits, DL-233). Reading it as one would make the gate look for an
        // entry keyed on a token the writeback will never correlate — so it
        // falls through to the tokenless arm instead, which this PR satisfies
        // by having changed the section.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $this->changelog('- old
- something'), 'app/X.php' => 'b'],
            'chore: mentions card4 in passing',
            'chore/tidy',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('no correlation token in the title', $out);
    }

    public function test_a_tokenless_pr_that_leaves_unreleased_untouched_fails(): void
    {
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'bin/tool.py' => 'a'],
            ['bin/tool.py' => 'b'],
            'chore: tweak the tool',
            'chore/tweak',
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('leaves docs/CHANGELOG.md\'s [Unreleased] section untouched', $out);
    }

    public function test_a_bin_change_is_in_scope_exactly_like_an_app_change(): void
    {
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'bin/tool.py' => 'a'],
            ['bin/tool.py' => 'b'],
            'fix(tool): repair it (card#1234)',
            'fix/1234-tool',
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
    }

    // ---------------------------------------------------------------- publish step

    /**
     * Drive the real release-publishing step with `gh` stubbed on PATH: the
     * step's only outward action is the `gh release create` call, and the
     * artifact under test is the body it hands that call.
     *
     * @return array{0:int,1:string,2:string} [exit code, output, release-notes body]
     */
    private function runPublishStep(string $changelog, string $version): array
    {
        $repo = $this->makeRepo(['docs/CHANGELOG.md' => $changelog], []);
        $stub = $repo['dir'].'/stubbin';
        mkdir($stub, 0o777, true);
        // `release view` must report ABSENT (exit 1) or the step short-circuits
        // as idempotent and never builds a body at all.
        file_put_contents($stub.'/gh', "#!/bin/bash\nif [ \"\$2\" = view ]; then exit 1; fi\nexit 0\n");
        chmod($stub.'/gh', 0o755);

        [$rc, $out] = $this->runStep(
            $this->stepScript('auto-tag-version.yml', 'auto-tag', self::PUBLISH_STEP),
            $repo['dir'],
            [
                'PATH' => $stub.':'.getenv('PATH'),
                'GH_TOKEN' => 'stub',
                'VERSION' => $version,
                'GITHUB_REPOSITORY' => 'PupFuzz/agent-webhook-bridge',
            ],
        );

        return [$rc, $out, (string) @file_get_contents($repo['dir'].'/release-notes.md')];
    }

    public function test_a_fitting_section_is_published_verbatim(): void
    {
        $changelog = $this->changelog('- pending', "\n## [1.1.0] - 2026-01-01\n\nthe entry\n");
        [$rc, $out, $notes] = $this->runPublishStep($changelog, '1.1.0');

        $this->assertSame(0, $rc, $out);
        $this->assertSame("## [1.1.0] - 2026-01-01\n\nthe entry\n", $notes);
    }

    public function test_an_oversize_section_is_truncated_with_a_pointer_instead_of_failing(): void
    {
        // card#5972: without this the tag exists and the Release does not.
        $huge = str_repeat("a padded changelog line that carries no meaning\n", 3_000);
        $changelog = $this->changelog('- pending', "\n## [1.1.0] - 2026-01-01\n\n".$huge);
        [$rc, $out, $notes] = $this->runPublishStep($changelog, '1.1.0');

        $this->assertSame(0, $rc, $out);
        $this->assertLessThanOrEqual(125_000, strlen($notes));
        $this->assertStringStartsWith("## [1.1.0] - 2026-01-01\n", $notes);
        $this->assertStringContainsString(
            'https://github.com/PupFuzz/agent-webhook-bridge/blob/v1.1.0/docs/CHANGELOG.md',
            $notes,
        );
    }

    public function test_an_absent_section_still_publishes_a_generated_note(): void
    {
        // Fail-soft here is deliberate and card#5910's gate answer refused to
        // change it: failing would leave a merged release untagged. The loud
        // half lives in changelog-gate.yml, at PR time.
        [$rc, $out, $notes] = $this->runPublishStep($this->changelog('- pending'), '1.1.0');

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('WARNING: no docs/CHANGELOG.md section', $out);
        $this->assertSame("Release v1.1.0. See docs/CHANGELOG.md.\n", $notes);
    }
}
