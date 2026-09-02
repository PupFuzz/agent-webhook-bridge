<?php

namespace Tests\Feature\Workflows;

use Tests\TestCase;

/**
 * Executes the REAL `bin/pr-base-snapshot.sh` against throwaway git
 * repositories. The script is the single derivation four PR gates now share —
 * `dl-collision-gate.yml`, both steps of `changelog-gate.yml`,
 * `channel-server-supply-chain.yml`'s version-bump guard and
 * `release-artifacts-gate.yml`'s base-sha step — so one wrong answer here is
 * four wrong verdicts, and the callers' own harnesses exercise only the arm
 * their fixtures happen to take.
 *
 * WHY IT EXISTS AT ALL (card#8527). The gates used to take
 * `github.event.pull_request.base.sha` as the commit their work tree was merged
 * onto. GitHub does not refresh that field on every `synchronize`: measured
 * 2026-09-02 on PR #640, run 33598959720, it carried 549c894 while the merge
 * commit that same event checked out had first parent 7a11085. Pairing the two
 * made `dl-collision-gate.yml` read the base branch's own DL-335 as a number the
 * PR had minted and refuse an innocent PR.
 *
 * THE REFUSAL ARMS ARE THE POINT, not an edge case. The script's whole value is
 * that it says "I cannot tell you what the base is" instead of substituting
 * either the payload field or a `git merge-base` fork point, both of which
 * return a plausible sha and a wrong verdict. Every arm is exercised here, with
 * its exit code, because the callers propagate that code under `set -e`.
 */
class PrBaseSnapshotTest extends TestCase
{
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

    private function git(string $dir): string
    {
        return 'git -C '.escapeshellarg($dir).' -c user.email=t@example.invalid -c user.name=t ';
    }

    /** A repo with a base commit, a branch commit, and (by default) the merge of the second into the first. */
    private function makeRepo(bool $merge = true): array
    {
        $dir = sys_get_temp_dir().'/pr-base-'.bin2hex(random_bytes(6));
        $this->trees[] = $dir;
        mkdir($dir, 0o777, true);
        $git = $this->git($dir);

        exec($git.'init -q -b main 2>&1');
        file_put_contents($dir.'/f', "base\n");
        exec($git.'add -A && '.$git.'commit -q -m base 2>&1');
        $base = trim((string) shell_exec($git.'rev-parse HEAD'));

        exec($git.'checkout -q -b pr 2>&1');
        file_put_contents($dir.'/g', "head\n");
        exec($git.'add -A && '.$git.'commit -q -m head 2>&1');
        $head = trim((string) shell_exec($git.'rev-parse HEAD'));

        if ($merge) {
            exec($git.'checkout -q --detach '.escapeshellarg($base).' 2>&1');
            exec($git.'merge -q --no-ff --no-edit '.escapeshellarg($head).' 2>&1');
        }

        return ['dir' => $dir, 'base' => $base, 'head' => $head];
    }

    /** @return array{0:int,1:string,2:string} [exit code, stdout, stderr] */
    private function runScript(string $dir, ?string $arg): array
    {
        $errFile = $dir.'/.stderr';
        $cmd = 'cd '.escapeshellarg($dir).' && '.base_path('bin/pr-base-snapshot.sh')
            .($arg === null ? '' : ' '.escapeshellarg($arg))
            .' 2>'.escapeshellarg($errFile);

        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);

        return [$rc, implode("\n", $out), (string) @file_get_contents($errFile)];
    }

    public function test_it_prints_the_merge_commits_first_parent_and_nothing_else(): void
    {
        $repo = $this->makeRepo();

        [$rc, $stdout, $stderr] = $this->runScript($repo['dir'], $repo['head']);

        $this->assertSame(0, $rc, $stderr);
        // Exactly the sha: callers capture stdout with `$( )` and hand the result
        // straight to `git show`, so a stray diagnostic line on this stream is a
        // corrupt revision, not a cosmetic defect.
        $this->assertSame($repo['base'], $stdout);
        $this->assertSame('', $stderr);
    }

    public function test_it_reads_the_recorded_parent_rather_than_re_deriving_a_fork_point(): void
    {
        // The one shape that separates `HEAD^1` from `git merge-base HEAD^1
        // HEAD^2` — the rewrite this script exists NOT to be, and the shape PR
        // #640 actually was: the base branch moves after the fork, so the merge's
        // recorded parent is the MOVED TIP while the fork point is where the
        // branch was cut. Every other fixture in this class is linear, where the
        // two coincide and the substitution is invisible; without this case the
        // class is inert to it and the discrimination lives only in the callers'
        // harnesses, which is how a derivation defect reaches four gates.
        $repo = $this->makeRepo(merge: false);
        $git = $this->git($repo['dir']);

        exec($git.'checkout -q --detach '.escapeshellarg($repo['base']).' 2>&1');
        file_put_contents($repo['dir'].'/f', "base moved\n");
        exec($git.'add -A && '.$git.'commit -q -m "base moves after the fork" 2>&1');
        $movedTip = trim((string) shell_exec($git.'rev-parse HEAD'));
        exec($git.'merge -q --no-ff --no-edit '.escapeshellarg($repo['head']).' 2>&1');

        $forkPoint = trim((string) shell_exec($git.'merge-base '.escapeshellarg($movedTip).' '.escapeshellarg($repo['head'])));
        $this->assertSame($repo['base'], $forkPoint, 'the fixture must make the fork point differ from the recorded parent');
        $this->assertNotSame($movedTip, $forkPoint);

        [$rc, $stdout, $stderr] = $this->runScript($repo['dir'], $repo['head']);

        $this->assertSame(0, $rc, $stderr);
        $this->assertSame($movedTip, $stdout, 'the base is the parent the merge RECORDS, not a fork point re-derived from it');
    }

    public function test_a_non_merge_head_is_refused_rather_than_guessed_at(): void
    {
        // The `push`-triggered run, the local invocation, the re-pointed fixture:
        // every caller whose work tree is not the PR merge. There is no base to
        // read, so there is no verdict to give.
        $repo = $this->makeRepo(merge: false);

        [$rc, $stdout, $stderr] = $this->runScript($repo['dir'], $repo['head']);

        $this->assertSame(4, $rc, $stderr);
        $this->assertSame('', $stdout, 'a refusal must print no sha — a caller capturing stdout would treat it as one');
        $this->assertStringContainsString('not a two-parent merge commit', $stderr);
        // The remediation string is a doc surface: it is where a maintainer
        // staring at a red gate learns which input is missing.
        $this->assertStringContainsString('refs/pull/N/merge', $stderr);
    }

    public function test_a_merge_of_some_other_head_is_refused(): void
    {
        // A stale `refs/pull/N/merge`. HEAD *is* a two-parent merge, so the shape
        // leg passes and only the identity leg can catch it — which is why the
        // script takes the head sha at all.
        $repo = $this->makeRepo();

        [$rc, $stdout, $stderr] = $this->runScript($repo['dir'], $repo['base']);

        $this->assertSame(5, $rc, $stderr);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString("is not this PR's head sha", $stderr);
        $this->assertStringContainsString($repo['head'], $stderr, 'the message must name the sha it actually found');
    }

    public function test_an_octopus_merge_is_refused_too(): void
    {
        // "Two parents" is asserted, not "is a merge": on a three-parent commit
        // `HEAD^1` is still readable and still wrong, and it would answer
        // silently. Pinned because the shape check is a `-ne 3`, which a later
        // edit could easily relax to `-lt 3`.
        $repo = $this->makeRepo(merge: false);
        $git = $this->git($repo['dir']);
        exec($git.'checkout -q -b other '.escapeshellarg($repo['base']).' 2>&1');
        file_put_contents($repo['dir'].'/h', "other\n");
        exec($git.'add -A && '.$git.'commit -q -m other 2>&1');
        exec($git.'checkout -q --detach '.escapeshellarg($repo['base']).' 2>&1');
        exec($git.'merge -q --no-ff --no-edit pr other 2>&1');

        [$rc, $stdout, $stderr] = $this->runScript($repo['dir'], $repo['head']);

        $this->assertSame(4, $rc, $stderr);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('not a two-parent merge commit', $stderr);
    }

    public function test_a_missing_head_sha_argument_is_refused(): void
    {
        // Not defensive: a caller that forgets the argument would otherwise get a
        // sha off an UNVERIFIED merge, which is the class of answer this script
        // exists to stop giving.
        $repo = $this->makeRepo();

        [$rc, $stdout, $stderr] = $this->runScript($repo['dir'], null);

        $this->assertSame(2, $rc, $stderr);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('needs the PR head sha', $stderr);
    }

    public function test_a_repository_with_no_commits_is_refused_as_unreadable(): void
    {
        $dir = sys_get_temp_dir().'/pr-base-'.bin2hex(random_bytes(6));
        $this->trees[] = $dir;
        mkdir($dir, 0o777, true);
        exec($this->git($dir).'init -q -b main 2>&1');

        [$rc, $stdout, $stderr] = $this->runScript($dir, str_repeat('a', 40));

        $this->assertSame(3, $rc, $stderr);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('could not read HEAD', $stderr);
    }
}
