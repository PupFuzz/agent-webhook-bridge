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
 * the PR from `git diff --name-only "$BASE" HEAD`, and the tokenless arm reads
 * the changelog AT THE BASE through `git show`. A fixture that handed the step a
 * pre-computed file list would be asserting the fixture.
 *
 * WHY THE WORK TREE IS A MERGE COMMIT (card#8527). Neither end of that diff is a
 * workflow INPUT any more. CI checks out `github.sha` — the PR head merged into
 * the base branch — and the steps derive `$BASE` from that merge's FIRST PARENT
 * through `bin/pr-base-snapshot.sh`, because
 * `github.event.pull_request.base.sha` was measured LAGGING the merge commit's
 * real base (PR #640, run 33598959720, 2026-09-02) and a diff whose two ends are
 * not one snapshot reports on a tree nobody pushed. So `makeRepo()` builds the
 * base commit, the head commit, and then the merge of the second into the first,
 * and the only sha the steps are handed is the head sha the pairing is CHECKED
 * against. `bin/pr-base-snapshot.sh` has its own cases in
 * {@see PrBaseSnapshotTest}; here it is exercised as the steps call it.
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

    /**
     * The gate's operator-facing member list — ONE copy here, matching the one
     * `SCOPE` shell variable the workflow interpolates into all three messages.
     * A guard's remediation text is a doc surface, so the enumeration is
     * asserted rather than a substring of it. Pinned by the message-content
     * assertions below, which compare it against `${SCOPE}`'s rendered output —
     * not by the derivation leg, which never reads it.
     */
    private const SCOPE_ENUMERATION = '(app/, bin/, .github/workflows/, .github/actions/, .release-pr.json, phpstan-laravel.neon, pint.json, phpunit.xml, composer.json, composer.lock, .env.example)';

    private const OUT_OF_SCOPE_MESSAGE = 'no in-scope file '.self::SCOPE_ENUMERATION;

    private const IN_SCOPE_MESSAGE = 'an in-scope file '.self::SCOPE_ENUMERATION;

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

    /** A throwaway repo directory, registered for teardown, with git initialised. */
    private function initRepo(): string
    {
        $dir = sys_get_temp_dir().'/changelog-gate-'.bin2hex(random_bytes(6));
        $this->trees[] = $dir;
        mkdir($dir, 0o777, true);
        exec($this->git($dir).'init -q -b main 2>&1');

        return $dir;
    }

    private function git(string $dir): string
    {
        return 'git -C '.escapeshellarg($dir).' -c user.email=t@example.invalid -c user.name=t ';
    }

    /** @param array<string,string> $files repo-relative path => contents */
    private function writeFiles(string $dir, array $files): void
    {
        foreach ($files as $path => $contents) {
            $full = $dir.'/'.$path;
            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0o777, true);
            }
            file_put_contents($full, $contents);
        }
    }

    /**
     * The steps invoke the extractor by repo-relative path; give them the real
     * one. Copied AFTER the last commit on purpose: it must never appear in the
     * base..head diff, or every fixture would read as touching bin/.
     */
    private function installExtractor(string $dir): void
    {
        if (! is_dir($dir.'/bin')) {
            mkdir($dir.'/bin', 0o777, true);
        }
        copy(base_path('bin/changelog-section.py'), $dir.'/bin/changelog-section.py');
    }

    /**
     * Both steps derive `$BASE` from the checked-out merge through this script
     * (card#8527), so every fixture needs it — deliberately NOT behind
     * `$withExtractor`: a step that cannot derive its base never reaches the
     * extractor, so withholding it would turn "the extractor is missing" cases
     * into "the pairing is missing" ones and they would pass for the wrong
     * reason. Copied AFTER the last commit, like the extractor, so `bin/` never
     * appears in a fixture's diff.
     */
    private function installBaseSnapshot(string $dir): void
    {
        if (! is_dir($dir.'/bin')) {
            mkdir($dir.'/bin', 0o777, true);
        }
        copy(base_path('bin/pr-base-snapshot.sh'), $dir.'/bin/pr-base-snapshot.sh');
        chmod($dir.'/bin/pr-base-snapshot.sh', 0o755);
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
        $dir = $this->initRepo();
        $git = $this->git($dir);

        $this->writeFiles($dir, $base);
        exec($git.'add -A && '.$git.'commit -q -m base 2>&1');
        $baseSha = trim((string) shell_exec($git.'rev-parse HEAD'));

        // Only paths named in $head are rewritten; everything else carries over,
        // which is what makes "this PR touched no app/ file" expressible.
        $this->writeFiles($dir, $head);
        exec($git.'add -A && '.$git.'commit -q --allow-empty -m head 2>&1');
        $headSha = trim((string) shell_exec($git.'rev-parse HEAD'));

        // Leave the work tree where actions/checkout leaves it: on the merge of
        // the head INTO the base, first parent the base — the same place
        // `makeForkedRepo` leaves it. The base is an ancestor of the head here,
        // so the merge tree IS the head tree and every case's verdict is
        // unchanged by this; what changes is that the steps now have somewhere
        // to read their base FROM (card#8527).
        exec($git.'checkout -q --detach '.escapeshellarg($baseSha).' 2>&1');
        exec($git.'merge -q --no-ff --no-edit '.escapeshellarg($headSha).' 2>&1');

        $this->installBaseSnapshot($dir);
        if ($withExtractor) {
            $this->installExtractor($dir);
        }

        return ['dir' => $dir, 'base' => $baseSha, 'head' => $headSha];
    }

    /**
     * Materialize the shape `makeRepo` cannot express: a PR branch that FORKED,
     * with the base branch moving on afterwards, LEFT CHECKED OUT AT THE MERGE.
     *
     * That is the shape card#8339 is about — a branch cut before a release fold
     * — and both halves matter. The fork is what lets base and head each carry
     * changes the other does not have; the merged working tree is what the gate
     * actually reads, because `actions/checkout` on a `pull_request` event
     * checks out `refs/pull/<n>/merge` (measured in run 33544122079). A linear
     * base→head fixture is a branch that is already up to date with its base,
     * where merge and head coincide and the defect cannot exist.
     *
     * `$branchMergesBase` takes the branch one step further: the PR branch
     * MERGES the moved base and pushes, which is what the remedy's own first
     * step tells the author to do. The head sha is then a merge commit whose
     * merge-base with the base branch IS the base branch — the branch's history
     * has absorbed the fold — while its FORK POINT is unmoved. Nothing else
     * about the shape changes, which is what makes the pair a discriminator
     * between the two readings.
     *
     * `$afterMerge` rewrites files on the branch AFTER that merge, which is the
     * only way to express an edit made in FULL SIGHT of the folded text — the
     * author who merges the base and then corrects a line the fold had already
     * shipped. A pre-merge edit is a different shape: it was written when the
     * line still stood under `[Unreleased]`.
     *
     * @param  array<string,string>  $fork  files at the fork point
     * @param  array<string,string>  $base  files as the base branch rewrites them after the fork
     * @param  array<string,string>  $head  files as the PR branch rewrites them after the fork
     * @param  array<string,string>  $afterMerge  files the branch rewrites after merging the base
     * @return array{dir:string,base:string,head:string}
     */
    private function makeForkedRepo(
        array $fork,
        array $base,
        array $head,
        bool $branchMergesBase = false,
        array $afterMerge = [],
    ): array {
        $dir = $this->initRepo();
        $git = $this->git($dir);

        $this->writeFiles($dir, $fork);
        exec($git.'add -A && '.$git.'commit -q -m fork 2>&1');

        exec($git.'checkout -q -b pr 2>&1');
        $this->writeFiles($dir, $head);
        exec($git.'add -A && '.$git.'commit -q --allow-empty -m head 2>&1');
        $headSha = trim((string) shell_exec($git.'rev-parse HEAD'));

        exec($git.'checkout -q main 2>&1');
        $this->writeFiles($dir, $base);
        exec($git.'add -A && '.$git.'commit -q --allow-empty -m base 2>&1');
        $baseSha = trim((string) shell_exec($git.'rev-parse HEAD'));

        if ($afterMerge !== []) {
            // A post-merge rewrite on a branch that never merged is not a
            // shape — it is the fixture silently testing something else.
            $this->assertTrue($branchMergesBase, '$afterMerge needs the branch to have merged the base');
        }

        if ($branchMergesBase) {
            exec($git.'checkout -q pr 2>&1');
            $rc = 0;
            $out = [];
            exec($git.'merge -q --no-edit main 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "the branch's merge of the base must be clean: \n".implode("\n", $out));
            if ($afterMerge !== []) {
                $this->writeFiles($dir, $afterMerge);
                exec($git.'add -A && '.$git.'commit -q -m after-merge 2>&1');
            }
            $headSha = trim((string) shell_exec($git.'rev-parse HEAD'));
            exec($git.'checkout -q main 2>&1');
        }

        // A CONFLICTING pull request gets no merge ref and therefore no workflow
        // run at all, so a fixture that conflicts is testing nothing GitHub
        // would ever hand the gate. Assert the merge is clean rather than
        // discovering it through an unrelated failure downstream.
        //
        // `--no-ff` because GITHUB NEVER FAST-FORWARDS `refs/pull/<n>/merge`,
        // measured 2026-09-02 on this repo's two open PRs whose branches already
        // contained the base tip: #635 (merge 570d1dc) and #630 (merge 6286dc9)
        // are both two-parent merges even though `merge-base --is-ancestor base
        // head` is true for each. Without the flag the fixture fast-forwards on
        // exactly the `$branchMergesBase` shape and hands the step a work tree
        // GitHub would never produce — which `bin/pr-base-snapshot.sh` refuses,
        // correctly, since it cannot tell that tree from a mis-pointed one.
        $mergeRc = 0;
        $mergeOut = [];
        exec($git.'merge -q --no-ff --no-edit pr 2>&1', $mergeOut, $mergeRc);
        $this->assertSame(0, $mergeRc, "the fixture merge must be clean: \n".implode("\n", $mergeOut));

        $this->installExtractor($dir);
        $this->installBaseSnapshot($dir);

        return ['dir' => $dir, 'base' => $baseSha, 'head' => $headSha];
    }

    /**
     * Materialize the topology `makeForkedRepo` cannot express: a PR branch that
     * forked AFTER the release fold and whose OWN FIRST COMMIT merges a sibling
     * branch cut BEFORE it and not yet on the base.
     *
     * The point of the shape is what it does to `$HEAD ^$BASE`: the sibling's
     * commits are inside that range, so the range's oldest commit belongs to the
     * SIBLING, while the branch's own first commit is the merge. Those two
     * readings disagree about where this branch was cut from, and only the
     * first-parent one answers about THIS branch.
     *
     * The sibling touches a path of its own, so the merge is clean and the
     * changelog in the merged tree is the base's folded one — the branch's edit
     * of the released section is then unambiguously its own.
     *
     * @param  string  $head  the changelog the PR branch writes after that merge
     * @return array{dir:string,base:string,head:string,siblingFirst:string}
     */
    private function makeSiblingMergeRepo(string $head): array
    {
        $dir = $this->initRepo();
        $git = $this->git($dir);

        $this->writeFiles($dir, ['docs/CHANGELOG.md' => self::PRE_FOLD_CHANGELOG, 'app/X.php' => 'a']);
        exec($git.'add -A && '.$git.'commit -q -m root 2>&1');

        // The sibling: cut PRE-fold, still unmerged when the PR branch takes it.
        exec($git.'checkout -q -b sibling 2>&1');
        $this->writeFiles($dir, ['app/Z.php' => 'z']);
        exec($git.'add -A && '.$git.'commit -q -m sibling 2>&1');
        $siblingFirst = trim((string) shell_exec($git.'rev-parse HEAD'));

        // The fold lands on the base AFTER the sibling was cut and BEFORE the PR
        // branch is.
        exec($git.'checkout -q main 2>&1');
        $this->writeFiles($dir, ['docs/CHANGELOG.md' => self::FOLDED_CHANGELOG]);
        exec($git.'add -A && '.$git.'commit -q -m fold 2>&1');
        $baseSha = trim((string) shell_exec($git.'rev-parse HEAD'));

        exec($git.'checkout -q -b pr 2>&1');
        $rc = 0;
        $out = [];
        exec($git.'merge -q --no-edit --no-ff sibling 2>&1', $out, $rc);
        $this->assertSame(0, $rc, "the branch's merge of its sibling must be clean: \n".implode("\n", $out));
        $this->assertStringContainsString(
            self::FOLDED_CHANGELOG,
            (string) file_get_contents($dir.'/docs/CHANGELOG.md'),
            'the sibling merge must leave the FOLDED changelog standing, or the edit below is not the branch\'s own',
        );

        $this->writeFiles($dir, ['docs/CHANGELOG.md' => $head, 'app/X.php' => 'b']);
        exec($git.'add -A && '.$git.'commit -q -m head 2>&1');
        $headSha = trim((string) shell_exec($git.'rev-parse HEAD'));

        // Same reason as `makeForkedRepo`, including its `--no-ff`: a CONFLICTING
        // PR gets no merge ref and therefore no run, and GitHub never
        // fast-forwards the merge ref it does build.
        exec($git.'checkout -q main 2>&1');
        $mergeRc = 0;
        $mergeOut = [];
        exec($git.'merge -q --no-ff --no-edit pr 2>&1', $mergeOut, $mergeRc);
        $this->assertSame(0, $mergeRc, "the fixture merge must be clean: \n".implode("\n", $mergeOut));

        $this->installExtractor($dir);
        $this->installBaseSnapshot($dir);

        return ['dir' => $dir, 'base' => $baseSha, 'head' => $headSha, 'siblingFirst' => $siblingFirst];
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
            ['HEAD' => $repo['head']],
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
            ['HEAD' => $repo['head']],
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
            ['HEAD' => $repo['head']],
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
            ['HEAD' => $repo['head']],
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
            ['HEAD' => $repo['head']],
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
            ['HEAD' => $repo['head'], 'TITLE' => $title, 'HEAD_REF' => $branch],
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

    public function test_a_pr_touching_no_in_scope_path_needs_no_entry(): void
    {
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'docs/other.md' => 'a'],
            ['docs/other.md' => 'b'],
            'docs: tidy',
            'docs/1234-tidy',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString(self::OUT_OF_SCOPE_MESSAGE, $out);
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
        $this->assertStringContainsString(self::IN_SCOPE_MESSAGE, $out);
    }

    public function test_an_entry_in_a_released_section_does_not_satisfy_the_gate(): void
    {
        // [Unreleased] is the only section that counts: a token that appears
        // only under an already-released heading describes shipped work, not
        // this PR's.
        //
        // AND IT IS NOT A FOLD. This branch CUT `## [1.0.0]` itself — the
        // heading is absent on the base — so "v1.0.0 was cut while this branch
        // was open" would be false about the one branch that could not have
        // been surprised by it. The pre-fold text must NOT appear here; the
        // generic remedy must.
        //
        // WHICH LEG DECIDES, measured rather than assumed: the fixture is LINEAR
        // (base → head, no fork), so the branch's fork point IS the base and the
        // predicate's two legs read the same tree — "present on the base" and
        // "absent at the fork point" cannot both hold here whatever the history
        // says. The base-presence leg is the one that fails, and dropping it is
        // what makes this fixture print the fold remedy for [1.0.0]; dropping or
        // inverting the fork-point leg leaves this leg green (both mutations are
        // red elsewhere — see the forked fixtures below, which is where the
        // fork-point leg is the deciding one).
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $this->changelog('- old', "\n## [1.0.0] - 2026-01-01\n\n- shipped (card#1234)\n"), 'app/X.php' => 'b'],
            'fix(x): more work (card#1234)',
            'fix/1234-x',
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
        $this->assertStringNotContainsString('predates the fold', $out);
        $this->assertStringContainsString("Fix: add an [Unreleased] entry citing 'card#1234'", $out);
    }

    // ------------------------------------------------- the pre-fold shape (card#8339)

    /**
     * The changelog at the fork point: one entry, under `[Unreleased]`.
     */
    private const PRE_FOLD_CHANGELOG = "# Changelog\n\n## [Unreleased]\n\n- old\n";

    /**
     * The base branch AFTER the release fold. The fold is an INSERTION — the
     * `[Unreleased]` heading is renamed to the version and a fresh empty one is
     * opened above it — which is exactly why nothing conflicts and why a branch
     * entry stays put while the heading above it changes meaning.
     */
    private const FOLDED_CHANGELOG = "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n- old\n";

    /** The PR branch, which still sees its entry under `[Unreleased]`. */
    private const BRANCH_CHANGELOG = "# Changelog\n\n## [Unreleased]\n\n- old\n- the new behaviour (card#1234)\n";

    /**
     * The branch AFTER it merged the fold and then corrected the line the fold
     * had already shipped. Byte-for-byte `FOLDED_CHANGELOG` with one bullet
     * reworded — so the branch adds a line under a released heading without a
     * fold having carried anything of its own in.
     */
    private const CORRECTED_SHIPPED_CHANGELOG = "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n- old, described correctly\n";

    /**
     * @param  array<string,string>  $head
     * @param  array<string,string>  $afterMerge
     * @return array{0:int,1:string}
     */
    private function runFeatureStepForked(
        array $head,
        string $title,
        string $branch,
        string $mergedMustContain,
        bool $branchMergesBase = false,
        array $afterMerge = [],
    ): array {
        $repo = $this->makeForkedRepo(
            ['docs/CHANGELOG.md' => self::PRE_FOLD_CHANGELOG, 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => self::FOLDED_CHANGELOG],
            $head,
            $branchMergesBase,
            $afterMerge,
        );

        // The fixture is only worth its verdict if the MERGE really produced the
        // shape the card describes. Asserted on the merged file itself, so a
        // future git whose 3-way resolution differs reds here — where the cause
        // is legible — instead of silently turning the legs below into a test of
        // some other shape.
        $this->assertStringContainsString(
            $mergedMustContain,
            (string) file_get_contents($repo['dir'].'/docs/CHANGELOG.md'),
        );

        return $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            ['HEAD' => $repo['head'], 'TITLE' => $title, 'HEAD_REF' => $branch],
        );
    }

    public function test_a_pre_fold_branch_is_told_to_move_its_entry_not_to_add_a_second_one(): void
    {
        // card#8339, reproduced end to end: a branch cut before the v1.1.0 fold
        // merges CLEANLY and lands its entry inside the released section, while
        // [Unreleased] reads empty. The gate was already red here (measured on
        // run 33544122079) — what it could not do was say why, and its remedy
        // asked for a SECOND entry, which leaves the first one standing in
        // [1.1.0] claiming work that release did not ship.
        [$rc, $out] = $this->runFeatureStepForked(
            ['docs/CHANGELOG.md' => self::BRANCH_CHANGELOG, 'app/X.php' => 'b'],
            'fix(x): the new behaviour (card#1234)',
            'fix/1234-x',
            "## [1.1.0] - 2026-01-02\n\n- old\n- the new behaviour (card#1234)\n",
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('Your entry is under [1.1.0] because the branch predates the fold', $out);
        $this->assertStringContainsString('move it under [Unreleased]', $out);
        // The defect was the remedy, so the absence of the wrong one is half the
        // assertion: an author who follows "add an entry" here files a duplicate
        // and leaves the released section corrupted.
        $this->assertStringNotContainsString('add an [Unreleased] entry', $out);
    }

    public function test_a_tokenless_pre_fold_branch_gets_the_same_diagnosis(): void
    {
        // The tokenless arm reaches the same verdict by a different route — the
        // [Unreleased] section is byte-identical to the base's — and it carried
        // the same wrong remedy. A fix to one arm and not the other would leave
        // the class half-closed.
        [$rc, $out] = $this->runFeatureStepForked(
            [
                'docs/CHANGELOG.md' => "# Changelog\n\n## [Unreleased]\n\n- old\n- some untokened work\n",
                'app/X.php' => 'b',
            ],
            'chore: tidy the thing',
            'chore/tidy',
            "## [1.1.0] - 2026-01-02\n\n- old\n- some untokened work\n",
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('leaves docs/CHANGELOG.md\'s [Unreleased] section untouched', $out);
        $this->assertStringContainsString('Your entry is under [1.1.0] because the branch predates the fold', $out);
        $this->assertStringNotContainsString('add an [Unreleased] entry', $out);
    }

    public function test_the_diagnosis_is_not_printed_when_the_branch_wrote_no_entry_at_all(): void
    {
        // THE DISCRIMINATOR. Same fork, same fold, same red verdict — the one
        // variable is whether the branch contributed a changelog line. Without
        // this leg the new message could be firing on every failure and both
        // legs above would still pass, which would trade one misleading remedy
        // for another.
        [$rc, $out] = $this->runFeatureStepForked(
            ['app/X.php' => 'b'],
            'fix(x): the new behaviour (card#1234)',
            'fix/1234-x',
            "## [1.1.0] - 2026-01-02\n\n- old\n",
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString("Fix: add an [Unreleased] entry citing 'card#1234'", $out);
        $this->assertStringNotContainsString('predates the fold', $out);
    }

    public function test_a_branch_editing_an_already_released_section_is_not_told_a_fold_happened(): void
    {
        // THE SECOND DISCRIMINATOR, and the one the label alone cannot supply:
        // `[0.79.0]` was cut BEFORE this branch forked — it stands at the fork
        // point AND on the base, and the base never touched the changelog at
        // all. The branch corrects a line inside that released section (the
        // stale-fix shape), which puts a line it introduced under a released
        // heading — everything the label needs, and none of what the sentence
        // claims. Saying "v0.79.0 was cut while this branch was open" is false,
        // and telling the author to lift a SHIPPED line out of a release is
        // worse than the generic remedy it replaced.
        $forked = "# Changelog\n\n## [Unreleased]\n\n## [0.79.0] - 2026-01-01\n\n- the shipped behaviour (card#1000)\n";
        $corrected = "# Changelog\n\n## [Unreleased]\n\n## [0.79.0] - 2026-01-01\n\n- the shipped behaviour, described correctly (card#1000)\n";

        $repo = $this->makeForkedRepo(
            ['docs/CHANGELOG.md' => $forked, 'app/X.php' => 'a'],
            ['app/Y.php' => 'b'],
            ['docs/CHANGELOG.md' => $corrected, 'app/X.php' => 'b'],
        );

        // The fixture is only worth its verdict if the merge really left the
        // branch's own new line inside the released section.
        $this->assertStringContainsString(
            "## [0.79.0] - 2026-01-01\n\n- the shipped behaviour, described correctly (card#1000)\n",
            (string) file_get_contents($repo['dir'].'/docs/CHANGELOG.md'),
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            [
                'HEAD' => $repo['head'],
                'TITLE' => 'docs(changelog): correct a shipped line (card#1234)',
                'HEAD_REF' => 'fix/1234-x',
            ],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
        $this->assertStringNotContainsString('predates the fold', $out);
        $this->assertStringNotContainsString('0.79.0', $out);
        $this->assertStringContainsString("Fix: add an [Unreleased] entry citing 'card#1234'", $out);
    }

    public function test_a_pre_fold_branch_that_has_already_merged_the_base_still_gets_the_fold_remedy(): void
    {
        // THE REMEDY'S OWN FIRST STEP, TAKEN. The message tells the author to
        // merge the base branch and then move the entry; an author who merges
        // and pushes before moving lands here, with the entry still inside
        // [1.1.0]. Read from the MERGE-BASE this shape is invisible — the merge
        // absorbed the fold into the branch's own history, so the fold heading
        // stands at the merge-base and the predicate goes false — and the gate
        // answers the author, at the exact moment they did what it asked, with
        // "add an [Unreleased] entry": the move docs/CHANGELOG.md itself calls
        // the exact opposite of the right one.
        //
        // THE FORK POINT DOES NOT MOVE WHEN THE BRANCH MERGES. That is the whole
        // reason the predicate reads it: "was the fold cut while this branch was
        // open" is a question about where the branch STARTED, and a merge is not
        // a re-fork.
        [$rc, $out] = $this->runFeatureStepForked(
            ['docs/CHANGELOG.md' => self::BRANCH_CHANGELOG, 'app/X.php' => 'b'],
            'fix(x): the new behaviour (card#1234)',
            'fix/1234-x',
            "## [1.1.0] - 2026-01-02\n\n- old\n- the new behaviour (card#1234)\n",
            branchMergesBase: true,
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('Your entry is under [1.1.0] because the branch predates the fold', $out);
        $this->assertStringContainsString('move it under [Unreleased]', $out);
        $this->assertStringNotContainsString('add an [Unreleased] entry', $out);
    }

    public function test_a_branch_that_corrects_a_shipped_line_after_merging_the_fold_is_not_told_to_move_it(): void
    {
        // LEG 3'S OWN DISCRIMINATOR, and the shape no reading of the HISTORY can
        // exclude. Identical fork, identical fold, identical base to the two
        // legs above — the branch merges the folded base exactly as the remedy's
        // first line instructs, and then corrects a line the fold had ALREADY
        // SHIPPED into [1.1.0]. Both history legs hold (the section stands on
        // the base, and was absent at the fork point), and the three trees the
        // predicate can read are byte-identical to the pre-fold case: the
        // corrected line is a line the branch adds, sitting under a released
        // heading. Telling this author to "MOVE your entry out of [1.1.0]" is
        // telling them to lift a SHIPPED line out of a release — the very thing
        // the fork-point leg was added to stop, arriving by another route.
        //
        // The `-` side is what separates them, and only it: a correction DELETES
        // the shipped line, while a fold carries lines in and deletes none.
        [$rc, $out] = $this->runFeatureStepForked(
            ['app/X.php' => 'b'],
            'docs(changelog): correct a shipped line (card#1234)',
            'fix/1234-x',
            "## [1.1.0] - 2026-01-02\n\n- old, described correctly\n",
            branchMergesBase: true,
            afterMerge: ['docs/CHANGELOG.md' => self::CORRECTED_SHIPPED_CHANGELOG],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
        $this->assertStringNotContainsString('predates the fold', $out);
        $this->assertStringNotContainsString('1.1.0', $out);
        $this->assertStringContainsString("Fix: add an [Unreleased] entry citing 'card#1234'", $out);
    }

    public function test_a_tokenless_correction_of_a_shipped_line_after_the_fold_is_not_told_to_move_it(): void
    {
        // The same shape on the other arm. The two arms reach the failure by
        // different routes and share ONE diagnosis helper, so a leg on only one
        // of them would leave the class half-measured — the same reason the
        // pre-fold pair above is a pair.
        [$rc, $out] = $this->runFeatureStepForked(
            ['app/X.php' => 'b'],
            'chore: tidy the thing',
            'chore/tidy',
            "## [1.1.0] - 2026-01-02\n\n- old, described correctly\n",
            branchMergesBase: true,
            afterMerge: ['docs/CHANGELOG.md' => self::CORRECTED_SHIPPED_CHANGELOG],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString("leaves docs/CHANGELOG.md's [Unreleased] section untouched", $out);
        $this->assertStringNotContainsString('predates the fold', $out);
        $this->assertStringNotContainsString('1.1.0', $out);
        $this->assertStringContainsString('Fix: add an [Unreleased] entry (and, if the work has a card', $out);
    }

    public function test_a_deleted_line_spelled_like_a_diff_header_is_still_read_as_a_deletion(): void
    {
        // WHY THE `-` SIDE IS READ THROUGH `--output-indicator-old`. Under the
        // default indicator a deleted line whose own text starts with `-- ` is
        // printed as `--- text`, which is exactly the shape of the `--- a/path`
        // file header the awk has to skip — so the header filter would eat a
        // real deletion, leg 3 would see none, and the gate would tell this
        // author to lift a SHIPPED line out of a release. That is the UNSAFE
        // direction, which is why the old side is disambiguated at the source
        // and the `+` side (where a lost line only drops the diagnosis) is not.
        //
        // Same shape as the correction fixture above; the one variable is how
        // the shipped line is spelled.
        $fork = "# Changelog\n\n## [Unreleased]\n\n-- old\n";
        $folded = "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n-- old\n";
        $corrected = "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n-- old, described correctly\n";

        $repo = $this->makeForkedRepo(
            ['docs/CHANGELOG.md' => $fork, 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $folded],
            ['app/X.php' => 'b'],
            branchMergesBase: true,
            afterMerge: ['docs/CHANGELOG.md' => $corrected],
        );

        // The fixture is only worth its verdict if git really prints that
        // deletion the way this leg is about.
        $this->assertStringContainsString(
            "\n--- old\n",
            (string) shell_exec(
                $this->git($repo['dir'])."diff --no-color {$repo['base']}...{$repo['head']} -- docs/CHANGELOG.md"
            ),
            'the deleted line must be indistinguishable from a --- file header under the default indicator',
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            [
                'HEAD' => $repo['head'],
                'TITLE' => 'docs(changelog): correct a shipped line (card#1234)',
                'HEAD_REF' => 'fix/1234-x',
            ],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringNotContainsString('predates the fold', $out);
        $this->assertStringContainsString("Fix: add an [Unreleased] entry citing 'card#1234'", $out);
    }

    public function test_a_pre_fold_branch_that_also_deletes_a_blank_line_is_still_diagnosed(): void
    {
        // LEG 3'S BLANK FILTER, pinned. `grep -x -f` reads an EMPTY pattern line
        // as matching every empty line, and every section body has blank lines
        // in it, so an unfiltered `-` side turns "this branch deleted one blank
        // line anywhere in the changelog" into "this branch deleted a line of
        // [1.1.0]" and throws the diagnosis away. This branch is the ordinary
        // pre-fold one — it adds its entry and, in the same edit, drops the
        // blank line at the end of the file, which is what reflowing prose does.
        // It must still be told to move its entry.
        $fork = "# Changelog\n\n## [Unreleased]\n\n- old\n\n## [0.9.0] - 2025-12-01\n\n- ancient\n\n";
        $folded = "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n- old\n\n## [0.9.0] - 2025-12-01\n\n- ancient\n\n";
        $branch = "# Changelog\n\n## [Unreleased]\n\n- old\n- the new behaviour (card#1234)\n\n## [0.9.0] - 2025-12-01\n\n- ancient\n";

        $repo = $this->makeForkedRepo(
            ['docs/CHANGELOG.md' => $fork, 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $folded],
            ['docs/CHANGELOG.md' => $branch, 'app/X.php' => 'b'],
        );

        // The fixture is only worth its verdict if the branch really did delete
        // a blank line and nothing else from the shared text.
        $deleted = (string) shell_exec(
            $this->git($repo['dir'])."diff --no-color {$repo['base']}...{$repo['head']} -- docs/CHANGELOG.md"
        );
        $removed = array_values(array_filter(
            explode("\n", $deleted),
            static fn (string $l): bool => str_starts_with($l, '-') && ! str_starts_with($l, '--- '),
        ));
        $this->assertSame(['-'], $removed, "the branch must delete exactly one BLANK line:\n".$deleted);

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            [
                'HEAD' => $repo['head'],
                'TITLE' => 'fix(x): the new behaviour (card#1234)',
                'HEAD_REF' => 'fix/1234-x',
            ],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('Your entry is under [1.1.0] because the branch predates the fold', $out);
        $this->assertStringNotContainsString('add an [Unreleased] entry', $out);
    }

    public function test_a_branch_forked_after_the_fold_that_edits_the_just_released_section_is_not_told_a_fold_happened(): void
    {
        // THE FORK-POINT LEG'S OWN DISCRIMINATOR, and the one the leg above
        // cannot supply. This branch forked AFTER [1.1.0] was cut and edits that
        // just-released section, so the base-presence leg is satisfied by the
        // very heading the leg above matches on: [1.1.0] stands on the base in
        // BOTH shapes, and only the fork point tells them apart. Without the
        // fork-point read the gate would tell this author that a fold they
        // forked after happened while they were open, and send them to lift a
        // SHIPPED line out of a release.
        //
        // The case-E fixture above uses a section released LONG before the fork
        // and is not this leg: [0.79.0] is old enough that any reading of the
        // history separates it. This one is adjacent to the fold by one commit.
        $edited = "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n- old\n- the new behaviour (card#1234)\n";

        $repo = $this->makeForkedRepo(
            ['docs/CHANGELOG.md' => self::FOLDED_CHANGELOG, 'app/X.php' => 'a'],
            ['app/Y.php' => 'b'],
            ['docs/CHANGELOG.md' => $edited, 'app/X.php' => 'b'],
        );

        $this->assertStringContainsString(
            "## [1.1.0] - 2026-01-02\n\n- old\n- the new behaviour (card#1234)\n",
            (string) file_get_contents($repo['dir'].'/docs/CHANGELOG.md'),
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            [
                'HEAD' => $repo['head'],
                'TITLE' => 'fix(x): the new behaviour (card#1234)',
                'HEAD_REF' => 'fix/1234-x',
            ],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
        $this->assertStringNotContainsString('predates the fold', $out);
        $this->assertStringContainsString("Fix: add an [Unreleased] entry citing 'card#1234'", $out);
    }

    public function test_a_post_fold_branch_whose_first_commit_merges_a_pre_fold_sibling_is_not_told_a_fold_happened(): void
    {
        // THE SAME MISCLASSIFICATION AS THE LEG ABOVE, REACHED THROUGH THE FORK
        // POINT ITSELF rather than through the predicate (review round 4). This
        // branch forked AFTER [1.1.0] was cut, so "the branch predates the fold"
        // is false of it — but its own FIRST commit merges a sibling branch cut
        // BEFORE the fold and not yet on the base, which puts that sibling's
        // commits inside `$HEAD ^$BASE`. The oldest commit of that RANGE is then
        // the sibling's, not this branch's, and it has exactly one parent, so
        // the ≠1-parent refusal does not fire: without `--first-parent` the
        // fork-point read answers about the SIBLING and hands back a PRE-fold
        // tree, which satisfies the fork-point leg and asserts the fold.
        //
        // The range has exactly ONE root, so this is not the multi-root shape
        // DL-329 discloses — nothing about root ORDERING is involved.
        $edited = "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n- old\n- the new behaviour (card#1234)\n";
        $repo = $this->makeSiblingMergeRepo($edited);
        $git = $this->git($repo['dir']);

        // THE FIXTURE'S OWN DISCRIMINATING PROPERTY, asserted rather than
        // assumed: the plain `--topo-order` tail must be the SIBLING's first
        // commit AND must have exactly one parent. If a future git orders the
        // range differently, or the shape stops producing a single-parent tail,
        // this fixture would silently stop being the cell it is named for and
        // would pass for a reason that has nothing to do with the leg.
        $topoTail = trim((string) shell_exec(
            $git.'rev-list --topo-order '.escapeshellarg($repo['head']).' ^'.escapeshellarg($repo['base']).' | tail -1'
        ));
        $this->assertSame(
            $repo['siblingFirst'],
            $topoTail,
            "the range's oldest commit must be the SIBLING's first commit, or this fixture is not the cell",
        );
        $this->assertSame(
            2,
            count(preg_split('/\s+/', trim((string) shell_exec($git.'rev-list --parents -n 1 '.escapeshellarg($topoTail))))),
            'that commit must have exactly ONE parent, or the old spelling would have refused it anyway',
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            [
                'HEAD' => $repo['head'],
                'TITLE' => 'fix(x): the new behaviour (card#1234)',
                'HEAD_REF' => 'fix/1234-x',
            ],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
        $this->assertStringNotContainsString('predates the fold', $out);
        $this->assertStringContainsString("Fix: add an [Unreleased] entry citing 'card#1234'", $out);
    }

    public function test_an_unreadable_fork_point_changelog_is_not_read_as_the_fold_being_absent(): void
    {
        // THE THIRD STATE. The fork-point read is the one the predicate INVERTS,
        // so "absent" and "could not tell" have to be different answers: under a
        // `! changelog_has_section` collapse, an extractor failure comes out as
        // "the heading was not there" and the gate names a fold that did not
        // happen. Here it did not: the branch forked AFTER [1.1.0] was cut and
        // edits it, exactly like the leg above — the one variable is that the
        // changelog AT THE FORK POINT is not valid UTF-8, which the extractor
        // leaves on its usage code rather than its no-such-section code.
        //
        // The base fixes that byte and the branch does not touch it, so the
        // BASE read and the MERGED read both succeed: this fixture breaks the
        // one read it is about.
        $forked = "# Changelog\n\n> notes: caf\xe9\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n- old\n";
        $based = "# Changelog\n\n> notes: cafe\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n- old\n";
        $head = "# Changelog\n\n> notes: caf\xe9\n\n## [Unreleased]\n\n## [1.1.0] - 2026-01-02\n\n- old\n- the new behaviour (card#1234)\n";

        $repo = $this->makeForkedRepo(
            ['docs/CHANGELOG.md' => $forked, 'app/X.php' => 'a'],
            ['docs/CHANGELOG.md' => $based],
            ['docs/CHANGELOG.md' => $head, 'app/X.php' => 'b'],
        );

        // The fixture is only worth its verdict if the merge really left the
        // MERGED file readable and the FORK POINT unreadable.
        $merged = (string) file_get_contents($repo['dir'].'/docs/CHANGELOG.md');
        $this->assertStringContainsString("- old\n- the new behaviour (card#1234)\n", $merged);
        $this->assertTrue(mb_check_encoding($merged, 'UTF-8'), 'the merged changelog must be readable');
        $this->assertFalse(
            mb_check_encoding($forked, 'UTF-8'),
            'the fork-point changelog must be the unreadable one, or this leg tests nothing',
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            [
                'HEAD' => $repo['head'],
                'TITLE' => 'fix(x): the new behaviour (card#1234)',
                'HEAD_REF' => 'fix/1234-x',
            ],
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringNotContainsString('predates the fold', $out);
        $this->assertStringContainsString("Fix: add an [Unreleased] entry citing 'card#1234'", $out);
        // `|| heading=''` is silent by construction, so the tooling failure has
        // to announce itself or it is indistinguishable from "not this shape".
        $this->assertStringContainsString('::notice::changelog pre-fold diagnosis unavailable', $out);
    }

    public function test_the_pre_fold_remedy_has_exactly_one_spelling_in_the_step(): void
    {
        // A guard's remediation string is a doc surface. Both arms fail for the
        // same cause and must give the same instruction, so the text is ONE
        // shell function and each arm calls it — the same rule `SCOPE` above is
        // held to, and pinned the same way, because a second copy drifts
        // silently and the two arms are rarely read together.
        $script = $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP);

        $this->assertSame(
            1,
            substr_count($script, 'because the branch predates the fold'),
            'the pre-fold remedy must appear once, in its function: re-inlining it into an arm mints the drift',
        );
        $this->assertSame(
            2,
            substr_count($script, 'prefold_remedy "$heading"'),
            'both failure arms must reach the remedy through that one function',
        );
    }

    public function test_the_remedy_the_gate_asks_for_is_the_one_that_turns_it_green(): void
    {
        // The GREEN control, and the assertion that the new text is actionable:
        // the branch has merged the folded base and moved its entry up, which is
        // literally what the message tells the author to do. A remedy nobody can
        // execute into a pass is a worse message than the one it replaced.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => self::FOLDED_CHANGELOG, 'app/X.php' => 'a'],
            [
                'docs/CHANGELOG.md' => "# Changelog\n\n## [Unreleased]\n\n- the new behaviour (card#1234)\n\n## [1.1.0] - 2026-01-02\n\n- old\n",
                'app/X.php' => 'b',
            ],
            'fix(x): the new behaviour (card#1234)',
            'fix/1234-x',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('names this PR\'s card token 1234', $out);
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
            ['HEAD' => $repo['head'], 'TITLE' => 'fix: x (card#1234)', 'HEAD_REF' => 'fix/1234-x'],
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
        $this->assertStringContainsString(self::IN_SCOPE_MESSAGE, $out);
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

    public function test_a_workflow_change_is_in_scope_exactly_like_an_app_change(): void
    {
        // card#6056: what CI accepts or rejects is shipped behaviour for a
        // contributor. The DL-279 gate adoption changed what CI accepts on
        // every release PR and owed no entry under the app/-and-bin/ scope.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), '.github/workflows/some-gate.yml' => 'a'],
            ['.github/workflows/some-gate.yml' => 'b'],
            'fix(ci): tighten the gate (card#1234)',
            'fix/1234-gate',
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
    }

    /**
     * The root CI-config members, one leg each: the five card#6100 (DL-282)
     * added, then the three card#6137 moved across from DL-282's disclosed
     * accepted gaps. Each is a file a CI step actually reads, so each changes
     * what CI accepts or rejects — the criterion the scope IS a proper subset
     * of, still: the members below are the ones ruled on, not the closure of
     * that criterion, and `changelog-gate.yml`'s PATH SCOPE block names what is
     * known to be outside them. One provider and one leg body for both rulings
     * on purpose: the
     * legs differ only in the path, and a second copy of the method would be
     * the enumeration drifting again.
     *
     * @return iterable<string,array{0:string}>
     */
    public static function rootCiConfigMemberProvider(): iterable
    {
        // Pins PHP 8.5, the extension set and composer install for both
        // laravel-tests.yml jobs.
        yield 'composite action' => ['.github/actions/setup-app/action.yml'];
        // VERSIONING.md calls its `artifacts` array the authority for the
        // member list release-artifacts-gate.yml enforces.
        yield 'declared release artifacts' => ['.release-pr.json'];
        // The analyser's own level and paths.
        yield 'phpstan config' => ['phpstan-laravel.neon'];
        // The style preset and excludes.
        yield 'pint config' => ['pint.json'];
        // <testsuites> decides which tests run at all: deleting an entry drops
        // a whole directory from CI without touching app/ or bin/.
        yield 'phpunit config' => ['phpunit.xml'];
        // card#6137, discharging DL-282's three disclosed CI-half gaps.
        // require-dev pins the pint/larastan/phpunit binaries the SQLite job
        // runs out of vendor/bin, and require pins the platform php.
        yield 'composer manifest' => ['composer.json'];
        // security.yml's `composer audit --locked` reads it as that gate's
        // rule input: changing it flips the gate red or green.
        yield 'composer lockfile' => ['composer.lock'];
        // The setup-app composite copies it to .env, so it is the environment
        // both test jobs run under.
        yield 'env template' => ['.env.example'];
    }

    #[DataProvider('rootCiConfigMemberProvider')]
    public function test_a_ci_config_member_is_in_scope_exactly_like_an_app_change(string $path): void
    {
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), $path => 'a'],
            [$path => 'b'],
            'fix(ci): retune it (card#1234)',
            'fix/1234-ci',
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
    }

    /**
     * The root-file members are whole paths, not prefixes. Without these three
     * legs a naive prefix predicate passes every positive leg above and the
     * anchoring is decoration. Each anchor is pinned by a different leg: the
     * trailing `$` excludes the suffixed case, the leading `^` the nested ones.
     * Three legs cover all of them because the root files share ONE anchored
     * alternation, so a mutation to either anchor moves every member of it —
     * these are legs about the two anchors, not one per member.
     *
     * @return iterable<string,array{0:string}>
     */
    public static function rootFileLookalikeProvider(): iterable
    {
        // Nested same-named files: no CI step reads either one.
        yield 'nested pint config' => ['config/pint.json'];
        yield 'nested phpunit config' => ['docs/phpunit.xml'];
        // Suffixed: the distributed template, not the file phpunit reads.
        yield 'suffixed phpunit config' => ['phpunit.xml.dist'];
    }

    #[DataProvider('rootFileLookalikeProvider')]
    public function test_a_root_file_lookalike_stays_out_of_scope(string $path): void
    {
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), $path => 'a'],
            [$path => 'b'],
            'chore: adjust it (card#1234)',
            'chore/1234-adjust',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString(self::OUT_OF_SCOPE_MESSAGE, $out);
    }

    public function test_a_dependabot_lockfile_bump_stays_exempt_after_the_lockfile_joined_the_scope(): void
    {
        // card#6137's cost argument, pinned rather than asserted in prose: over
        // v0.70.0..dev 12 first-parent commits touched composer.lock and 11 are
        // dependabot's, so the widening is cheap only while the branch
        // exemption keeps covering them. Narrowing that set would make each of
        // those bumps owe an entry — none of the 11 touches docs/CHANGELOG.md.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'composer.lock' => 'a'],
            ['composer.lock' => 'b'],
            'build(deps): Bump laravel/framework from 13.24.0 to 13.25.0',
            'dependabot/composer/laravel/framework-13.25.0',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('is exempt', $out);
    }

    /**
     * Three of the four hand-maintained copies are checked against the regex
     * here: the two in this workflow by set equality, `VERSIONING.md`'s scope
     * row by presence. The fourth, `SCOPE_ENUMERATION`, is pinned instead by
     * the message-content assertions. Members are parsed from the predicate as
     * extracted from the YAML — never typed here.
     */
    public function test_every_scope_enumeration_is_derived_from_the_predicate(): void
    {
        $script = $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP);

        // Assumes the FIRST single-quoted `grep -qE` is the scope predicate; a
        // hoisted or double-quoted one reds the group count below rather than
        // silently asserting about a different regex.
        $this->assertSame(1, preg_match("/grep -qE '([^']+)'/", $script, $m), 'the scope predicate must be extractable from the step');
        $this->assertSame(2, preg_match_all('/\(([^)]*)\)/', $m[1], $groups), 'this PARSER expects two anchored alternations — if the predicate was deliberately collapsed to the equally-valid single-group form (DL-282 Decision 3), update this parser, not the predicate');

        $members = [];
        foreach ($groups[1] as $group) {
            foreach (explode('|', $group) as $alternative) {
                $members[] = str_replace('\\', '', $alternative);
            }
        }
        // Positive control on the parser: a mangled parse must not make the
        // comparisons below vacuous.
        $this->assertContains('app/', $members);
        $this->assertContains('bin/', $members);

        // The two regimes are stated in prose on three surfaces as a RULE —
        // "the members with no trailing `/` are the whole-path ones" — where a
        // COUNT used to stand. A count goes false on any widening and no leg
        // covered it; this derives the rule instead, so the prose cannot drift
        // from the predicate the way the numerals silently could.
        foreach (explode('|', $groups[1][0]) as $prefix) {
            $this->assertStringEndsWith('/', str_replace('\\', '', $prefix), 'a member of the PREFIX alternation must end in `/` — the scope prose on three surfaces identifies the anchored root files by that one property');
        }
        foreach (explode('|', $groups[1][1]) as $rootFile) {
            $this->assertStringEndsNotWith('/', str_replace('\\', '', $rootFile), 'a member of the anchored ROOT-FILE alternation must not end in `/` — the scope prose on three surfaces identifies it by that one property');
        }

        $this->assertSame(1, preg_match("/^\s*SCOPE='([^']+)'/m", $script, $s), 'the operator-facing member list must be ONE shell variable, not a per-message copy');
        $scopeString = $s[1];
        // Exactly once in the whole step — its own assignment. Re-inlining a
        // copy into any message restores the N-copies drift this removed.
        $this->assertSame(1, substr_count($script, $scopeString), 'the member list must appear exactly once in the step: interpolate ${SCOPE} rather than re-inlining it');
        $this->assertSame($members, explode(', ', trim($scopeString, '()')), 'the SCOPE string and the predicate must name the same members, in the same order');

        // The enumeration alone, not the whole PATH SCOPE block: bounding it at
        // `CHANGED=` swept in the `SCOPE='…'` assignment, which made this a
        // restatement of the assertion above and left the comment unguarded.
        $this->assertSame(1, preg_match('/# The members:\n(.*?)\n\s*#\s*\n/s', $script, $b), 'the PATH SCOPE block must carry a member enumeration');
        // `^#   ` is the row column; continuation lines are indented further.
        preg_match_all('/^#   (\S+)/m', $b[1], $rows);
        $this->assertSame($members, $rows[1], 'the PATH SCOPE table and the predicate must name the same members, in the same order');

        // VERSIONING.md's scope table row — presence only: its condition cell
        // deliberately also names the lookalikes the anchors exclude.
        $condition = '';
        foreach (file(base_path('VERSIONING.md')) as $line) {
            if (str_contains($line, 'DL-282')) {
                $condition = explode(' | ', $line)[0];
            }
        }
        $this->assertNotSame('', $condition, 'VERSIONING.md must carry the scope table row');
        foreach ($members as $member) {
            $this->assertStringContainsString('`'.$member.'`', $condition, "VERSIONING.md's scope row does not name '{$member}'");
        }
    }

    public function test_a_non_ascii_in_scope_path_is_seen_by_the_scope_predicate(): void
    {
        // card#6101: `core.quotepath` defaults on, so git prints a path holding
        // a non-ASCII byte as a C-quoted string with a LEADING `"` — which
        // defeats the `^` anchor, silently exempting a PR whose in-scope paths
        // are ALL non-ASCII-named. The fixture is that PR: one in-scope file,
        // non-ASCII name, and a token the section does not carry, so the gate
        // must reach its verdict rather than skip.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'app/café.php' => 'a'],
            ['docs/CHANGELOG.md' => $this->changelog('- old
- an entry for some OTHER work (card#9999)'), 'app/café.php' => 'b'],
            'fix(x): repair the accented one (card#1234)',
            'fix/1234-x',
        );

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('does not name card 1234', $out);
    }

    public function test_a_github_path_outside_workflows_and_actions_stays_out_of_scope(): void
    {
        // The scope inside `.github/` is `workflows/` + `actions/`, not
        // `.github/`. Pinned because `^\.github/` is the easy over-reach.
        // `.github/dependabot.yml` configures PR CREATION and runs as no
        // check, so it changes nothing CI accepts or rejects.
        [$rc, $out] = $this->runFeatureStep(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), '.github/dependabot.yml' => 'a'],
            ['.github/dependabot.yml' => 'b'],
            'chore(deps): widen the ecosystem list (card#1234)',
            'chore/1234-deps',
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString(self::OUT_OF_SCOPE_MESSAGE, $out);
    }

    // -------------------------------------------- the pairing card#8527 fixed

    // ------------------------------- the scope set is the MERGE's delta (card#8527 / card#8441)

    /**
     * The fixture's own discriminating property, asserted rather than assumed:
     * `$path` must be inside the RETIRED pairing — the payload base against the
     * branch tip, two commits neither of which contains the other — and outside
     * the merge's own delta (`HEAD^1` -> the work tree, which is what the step
     * reads now). Every branch that is up to date with its base makes the two
     * agree, which is why the linear `makeRepo` shape cannot express any case
     * below and why each one asserts this first.
     *
     * @param  array{dir:string,base:string,head:string}  $repo
     */
    private function assertRetiredPairingOverCollects(array $repo, string $path): void
    {
        $git = $this->git($repo['dir']);
        $names = static fn (string $range): array => array_values(array_filter(
            explode("\n", trim((string) shell_exec($git.'diff --name-only '.$range)))
        ));

        $this->assertContains(
            $path,
            $names($repo['base'].' '.$repo['head']),
            "the retired pairing must report {$path} as this PR's, or the fixture is not the cell",
        );

        $derived = trim((string) shell_exec('cd '.escapeshellarg($repo['dir']).' && bin/pr-base-snapshot.sh '.escapeshellarg($repo['head'])));
        $this->assertSame($repo['base'], $derived, 'the derived base must be the base tip the merge records');
        $this->assertNotContains(
            $path,
            $names($derived.' HEAD'),
            "the merge's own delta must not report {$path}, or the fixture is not the cell",
        );
    }

    public function test_the_release_step_classifies_on_what_this_pr_lands_not_on_what_the_base_shipped(): void
    {
        // card#8441's release-step cell. The classification is "does THIS PR
        // move VERSION?", and the retired pairing could not answer it: on a
        // branch cut before a release fold, the BASE's own bump sat inside the
        // diff, so an innocent feature branch was classified as a release PR and
        // made to answer for somebody else's section — in the direction that
        // FAILS, when the folded section is over the release-body limit (the
        // v0.72.0 shape, recurrence tracked as card#7511).
        $huge = str_repeat("a padded changelog line that carries no meaning\n", 3_000);
        $repo = $this->makeForkedRepo(
            ['VERSION' => "1.0.0\n", 'docs/CHANGELOG.md' => $this->changelog('- old'), 'app/X.php' => 'a'],
            ['VERSION' => "1.1.0\n", 'docs/CHANGELOG.md' => $this->changelog('', "\n## [1.1.0] - 2026-01-02\n\n".$huge)],
            ['app/X.php' => 'b'],
        );

        $this->assertRetiredPairingOverCollects($repo, 'VERSION');

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::RELEASE_STEP),
            $repo['dir'],
            ['HEAD' => $repo['head']],
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('does not move VERSION', $out);
    }

    public function test_the_feature_step_classifies_on_what_this_pr_lands_not_on_what_the_base_shipped(): void
    {
        // card#8441's feature-step cell, and the PR #640 shape on this predicate:
        // a DOCS-ONLY branch, cut before the base shipped an `app/` change. Under
        // the retired pairing the base's file was reported as this PR's, so the
        // branch was pulled into the entry requirement on the strength of
        // somebody else's change — and told to write an entry naming a card
        // that shipped nothing here.
        $repo = $this->makeForkedRepo(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'docs/guide.md' => 'a', 'app/Y.php' => 'x'],
            ['app/Y.php' => 'y'],
            ['docs/guide.md' => 'b'],
        );

        $this->assertRetiredPairingOverCollects($repo, 'app/Y.php');

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            ['HEAD' => $repo['head'], 'TITLE' => 'docs(guide): reword the guide (card#1234)', 'HEAD_REF' => 'docs/1234-guide'],
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString(self::OUT_OF_SCOPE_MESSAGE, $out);
    }

    public function test_a_change_the_base_already_carries_identically_is_not_in_scope(): void
    {
        // The one cell where the merge's delta and a merge-base (three-dot)
        // range DISAGREE, and the merge's delta is the right answer: the branch
        // made the same `app/` edit the base shipped after the fork. The merge is
        // clean and lands NOTHING on the base for that file, so no entry is owed
        // for it. A `"$BASE...$HEAD"` spelling against the branch tip reports it
        // — the branch did change it since the fork — and would demand an entry
        // for a change this PR does not make. The retired two-dot pairing does
        // not even see it (identical content at both ends), so the discriminator
        // here is three-dot vs merge delta, asserted directly.
        $repo = $this->makeForkedRepo(
            ['docs/CHANGELOG.md' => $this->changelog('- old'), 'docs/guide.md' => 'a', 'app/Z.php' => 'x'],
            ['app/Z.php' => 'y'],
            ['app/Z.php' => 'y', 'docs/guide.md' => 'b'],
        );

        $git = $this->git($repo['dir']);
        $this->assertStringContainsString(
            'app/Z.php',
            (string) shell_exec($git.'diff --name-only '.$repo['base'].'...'.$repo['head']),
            'the three-dot range must report app/Z.php, or the fixture is not the cell',
        );
        $derived = trim((string) shell_exec('cd '.escapeshellarg($repo['dir']).' && bin/pr-base-snapshot.sh '.escapeshellarg($repo['head'])));
        $this->assertSame($repo['base'], $derived);
        $this->assertStringNotContainsString(
            'app/Z.php',
            (string) shell_exec($git.'diff --name-only '.$derived.' HEAD'),
            'the merge lands nothing for app/Z.php, so its delta must not carry it',
        );

        [$rc, $out] = $this->runStep(
            $this->stepScript('changelog-gate.yml', 'changelog-gate', self::FEATURE_STEP),
            $repo['dir'],
            ['HEAD' => $repo['head'], 'TITLE' => 'docs(guide): reword the guide (card#1234)', 'HEAD_REF' => 'docs/1234-guide'],
        );

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString(self::OUT_OF_SCOPE_MESSAGE, $out);
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
