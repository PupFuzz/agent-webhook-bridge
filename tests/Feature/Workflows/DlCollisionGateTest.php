<?php

namespace Tests\Feature\Workflows;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Executes the REAL bash out of `.github/workflows/dl-collision-gate.yml`
 * against a throwaway pair of git repositories, so the gate cannot drift from
 * what CI runs the way a re-implementation of its predicate would. Same shape
 * as {@see ChangelogGateTest} and {@see PrTitleLintTest}.
 *
 * WHY A REAL ORIGIN AND NOT ONE REPOSITORY. The step's second assertion reads
 * the decision log at the LIVE tip of the target branch, which it FETCHES — the
 * whole reason it can catch a number the target branch took after the `BASE`
 * snapshot. A fixture that handed it a pre-computed file would be asserting
 * the fixture; a single repo with no `origin` would not exercise the fetch at
 * all. So the fixture is an upstream repository the work tree is cloned from,
 * and the "the target branch moved under the PR" cases advance it for real.
 *
 * EVERY FIXTURE'S WORK TREE IS A REAL MERGE COMMIT, because that is what the
 * step now reads its base OUT OF. CI checks out `github.sha` — the merge of the
 * PR head into the base branch — and the step derives its base snapshot from
 * that merge's FIRST PARENT through `bin/pr-base-snapshot.sh`, so a fixture whose
 * head is a plain branch tip would not exercise the step at all (it fails loud
 * on it, and one case pins that). The base is therefore no longer an input the
 * test hands in: `makeRepos()` builds the merge and the step reads it, which is
 * the whole of card#8527's fix.
 *
 * ⛔ WHAT THAT FIX RETIRED, pinned by `test_a_stale_base_sha_...`. The step used
 * to take `github.event.pull_request.base.sha`, and GitHub does not refresh that
 * field on every `synchronize`: measured 2026-09-02 on PR #640, run 33598959720,
 * `base.sha` was 549c894 while the merge commit the same event checked out had
 * first parent 7a11085. That case builds exactly that divergence and runs the
 * retired pairing as its control — red — beside the derived one — green.
 *
 * THE STEP CALLS THE REAL `bin/decision-log.py`, copied into the work tree at
 * the repo-relative path the step names, so a change to the predicate reds here
 * as well as in `bin/test_decision_log.py`.
 *
 * EVERY CASE IS A PLANTED DEFECT OR A PINNED NEGATIVE. The gate is vacuous on
 * this repo's actual tree — no duplicate DL exists — so a case that merely ran
 * it would witness nothing.
 */
class DlCollisionGateTest extends TestCase
{
    private const STEP = 'A DL number this PR mints is not already in use';

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

    /** Extract the step's `run:` script from the workflow, by name. */
    private function stepScript(): string
    {
        $wf = Yaml::parseFile(base_path('.github/workflows/dl-collision-gate.yml'));
        foreach ($wf['jobs']['dl-collision-gate']['steps'] as $step) {
            if (($step['name'] ?? null) === self::STEP) {
                $this->assertSame('bash', $step['shell'] ?? null, 'the step must pin bash — the script uses pipefail');

                return (string) $step['run'];
            }
        }
        $this->fail('no step named "'.self::STEP.'" in dl-collision-gate.yml');
    }

    /** A decision log carrying one H2 entry per number given. */
    private function log(int ...$numbers): string
    {
        $out = "# Decision log\n\n> Append-only.\n\n";
        foreach ($numbers as $n) {
            $out .= "## DL-{$n} — an entry\n\nMirrors kanban-board DL-999.\n\n";
        }

        return $out;
    }

    private function git(string $dir): string
    {
        return 'git -C '.escapeshellarg($dir).' -c user.email=t@example.invalid -c user.name=t ';
    }

    /**
     * Upstream on `dev` at $baseLog, a work clone branched there carrying
     * $headLog, and a work tree left where CI leaves it: ON THE MERGE of that PR
     * head into the base commit, which is what the step derives its base from.
     * When $movedTargetLog is given, upstream `dev` moves on AFTER that merge is
     * built — the only way the live-target assertion has anything to see, now
     * that the base snapshot is the merge's own parent.
     *
     * @return array{dir:string,upstream:string,base:string,head:string,merge:string}
     */
    private function makeRepos(string $baseLog, string $headLog, ?string $movedTargetLog = null): array
    {
        $root = sys_get_temp_dir().'/dl-gate-'.bin2hex(random_bytes(6));
        $this->trees[] = $root;
        $upstream = $root.'/upstream';
        $work = $root.'/work';
        mkdir($upstream, 0o777, true);

        $up = $this->git($upstream);
        exec($up.'init -q -b dev 2>&1');
        file_put_contents($upstream.'/CLAUDE_DECISIONS.md', $baseLog);
        exec($up.'add -A && '.$up.'commit -q -m base 2>&1');
        $baseSha = trim((string) shell_exec($up.'rev-parse HEAD'));

        exec('git clone -q '.escapeshellarg($upstream).' '.escapeshellarg($work).' 2>&1');
        $wk = $this->git($work);
        exec($wk.'checkout -q -b pr 2>&1');
        file_put_contents($work.'/CLAUDE_DECISIONS.md', $headLog);
        exec($wk.'add -A && '.$wk.'commit -q --allow-empty -m head 2>&1');
        $headSha = trim((string) shell_exec($wk.'rev-parse HEAD'));

        $mergeSha = $this->mergeOnto($work, $baseSha, $headSha);

        if ($movedTargetLog !== null) {
            file_put_contents($upstream.'/CLAUDE_DECISIONS.md', $movedTargetLog);
            exec($up.'add -A && '.$up.'commit -q -m "dev moves on" 2>&1');
        }

        $this->installScripts($work);

        return ['dir' => $work, 'upstream' => $upstream, 'base' => $baseSha, 'head' => $headSha, 'merge' => $mergeSha];
    }

    /**
     * Build the commit GitHub builds for a `pull_request` event and leave the
     * work tree on it: $headSha merged INTO $baseSha, so the first parent is the
     * base and the second is the PR head. $resolved, when given, is the merged
     * CLAUDE_DECISIONS.md — needed whenever both sides appended to it, which
     * conflicts by construction (DL-295 stated bound (d)) and is resolved here
     * the way the author would resolve it.
     */
    private function mergeOnto(string $work, string $baseSha, string $headSha, ?string $resolved = null): string
    {
        $wk = $this->git($work);
        exec($wk.'checkout -q --detach '.escapeshellarg($baseSha).' 2>&1');
        exec($wk.'merge -q --no-ff --no-commit '.escapeshellarg($headSha).' 2>&1');
        if ($resolved !== null) {
            file_put_contents($work.'/CLAUDE_DECISIONS.md', $resolved);
        }
        exec($wk.'add -A && '.$wk.'commit -q -m "Merge pull request" 2>&1');

        $parents = preg_split('/\s+/', trim((string) shell_exec($wk.'rev-list --parents -n 1 HEAD')));
        $this->assertCount(3, (array) $parents, 'the fixture work tree must BE a merge commit — two parents');
        $this->assertSame($baseSha, $parents[1], 'first parent must be the base the merge was built on');
        $this->assertSame($headSha, $parents[2], 'second parent must be the PR head');

        return $parents[0];
    }

    /** The step invokes both by repo-relative path; give it the real ones. */
    private function installScripts(string $work): void
    {
        if (! is_dir($work.'/bin')) {
            mkdir($work.'/bin', 0o777, true);
        }
        copy(base_path('bin/decision-log.py'), $work.'/bin/decision-log.py');
        copy(base_path('bin/pr-base-snapshot.sh'), $work.'/bin/pr-base-snapshot.sh');
        chmod($work.'/bin/pr-base-snapshot.sh', 0o755);
    }

    /**
     * @param  array<string,string>  $env
     * @return array{0:int,1:string} [exit code, combined output]
     */
    private function runStep(string $dir, array $env): array
    {
        $assignments = '';
        foreach ($env as $k => $v) {
            $assignments .= $k.'='.escapeshellarg($v).' ';
        }
        $cmd = 'cd '.escapeshellarg($dir).' && env '.$assignments.'bash -c '.escapeshellarg($this->stepScript()).' 2>&1';

        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    // ------------------------------------------------------------ planted positives

    public function test_a_number_the_target_branch_took_after_this_pr_branched_is_refused(): void
    {
        // The measured shape of the incident: both branches were cut at 293 and
        // both minted 294. This PR's own file looks perfectly consistent.
        $repo = $this->makeRepos(
            $this->log(293),
            $this->log(293, 294),
            $this->log(293, 294),
        );

        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('DL-294', $out);
        $this->assertStringContainsString('already in use on dev', $out);
    }

    public function test_a_number_this_pr_uses_twice_is_refused(): void
    {
        $repo = $this->makeRepos(
            $this->log(293, 294),
            $this->log(293, 294, 294),
        );

        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('DL-294 is used TWICE', $out);
        $this->assertStringContainsString('uses one DL number twice', $out);
    }

    public function test_the_refusal_tells_the_author_how_to_allocate_a_fresh_number(): void
    {
        // A guard's remediation string is a doc surface: it is the only place
        // most authors will ever meet the allocator.
        $repo = $this->makeRepos(
            $this->log(293),
            $this->log(293, 294),
            $this->log(293, 294),
        );

        [, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);

        $this->assertStringContainsString('bin/decision-log.py next', $out);
    }

    // -------------------------------------------------------------- pinned negatives

    public function test_a_genuinely_new_number_passes(): void
    {
        $repo = $this->makeRepos(
            $this->log(293),
            $this->log(293, 295),
            $this->log(293, 294),
        );

        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('DL-295', $out);
    }

    public function test_a_pr_that_mints_no_dl_passes(): void
    {
        $repo = $this->makeRepos(
            $this->log(293),
            $this->log(293),
            $this->log(293, 294),
        );

        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('adds 0 DL entries', $out);
    }

    public function test_an_entry_the_target_branch_already_carried_at_branch_time_passes(): void
    {
        // DL-293 is on the base, the head and the target. Refusing it would red
        // every PR opened after it landed — a gate that reds on every PR is
        // worse than none.
        $repo = $this->makeRepos(
            $this->log(293),
            $this->log(293, 296),
            $this->log(293),
        );

        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);

        $this->assertSame(0, $rc, $out);
    }

    // ------------------------------------------------------------------ stated bound

    public function test_two_concurrently_open_prs_minting_one_number_both_pass(): void
    {
        // NOT a defect in the fixture — this is the gate's stated bound, pinned
        // so the claim cannot quietly widen. Neither PR's number is on `dev`
        // yet and neither file contains the other's entry, so both are green.
        // The second reds only once it is RE-CHECKED after the first merges,
        // which is a re-run, not a consequence of the merge.
        $first = $this->makeRepos($this->log(293), $this->log(293, 295));
        $second = $this->makeRepos($this->log(293), $this->log(293, 295));

        [$rcFirst, $outFirst] = $this->runStep($first['dir'], ['HEAD_SHA' => $first['head'], 'BASE_REF' => 'dev']);
        [$rcSecond, $outSecond] = $this->runStep($second['dir'], ['HEAD_SHA' => $second['head'], 'BASE_REF' => 'dev']);

        $this->assertSame(0, $rcFirst, $outFirst);
        $this->assertSame(0, $rcSecond, $outSecond);
    }

    public function test_the_second_pr_reds_once_the_first_has_merged_and_it_re_runs(): void
    {
        // The other half of the bound: the catch is real, but it is bought by a
        // re-run. Same repo as above with `dev` advanced to carry DL-295.
        $repo = $this->makeRepos(
            $this->log(293),
            $this->log(293, 295),
            $this->log(293, 295),
        );

        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('DL-295', $out);
    }

    // ------------------------------------------------- the pairing card#8527 fixed

    /**
     * The divergence PR #640 hit, built for real. `dev` gains its OWN DL-294
     * after this branch is cut, GitHub rebuilds the merge onto that newer tip,
     * and the event payload still carries the OLD tip as `base.sha`.
     *
     * @return array{dir:string,staleBaseSha:string,movedTip:string,head:string,baseLog:string,targetLog:string}
     */
    private function makeStaleBaseShaPairing(): array
    {
        // The PR mints DL-295 and nothing else; every DL-294 in this fixture is
        // the BASE BRANCH's own, which is what makes a refusal here false.
        $repo = $this->makeRepos($this->log(293), $this->log(293, 295));
        $work = $repo['dir'];
        $wk = $this->git($work);

        $upstream = $repo['upstream'];
        $up = $this->git($upstream);
        file_put_contents($upstream.'/CLAUDE_DECISIONS.md', $this->log(293, 294));
        exec($up.'add -A && '.$up.'commit -q -m "dev mints its own 294" 2>&1');

        exec($wk.'fetch -q origin dev 2>&1');
        $movedTip = trim((string) shell_exec($wk.'rev-parse FETCH_HEAD'));

        // GitHub rebuilds refs/pull/N/merge onto the tip that exists NOW. Both
        // sides appended, so the merge conflicts and is resolved the way the
        // author would resolve it — what is under test is which commit the step
        // reads as its base, not git's three-way merge.
        $this->mergeOnto($work, $movedTip, $repo['head'], $this->log(293, 294, 295));

        return [
            'dir' => $work,
            'staleBaseSha' => $repo['base'],
            'movedTip' => $movedTip,
            'head' => $repo['head'],
            'baseLog' => $this->log(293, 294),
            'targetLog' => $this->log(293, 294),
        ];
    }

    public function test_a_stale_base_sha_no_longer_makes_the_base_branchs_own_entry_read_as_this_prs_mint(): void
    {
        $repo = $this->makeStaleBaseShaPairing();

        // LEG 1 — the step, as CI runs it. It derives its base from the merge it
        // is standing on, so DL-294 is present at the base and only DL-295 is
        // this PR's. Green, which is the correct verdict.
        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('adds 1 DL entries (DL-295)', $out);

        // LEG 2 — the derivation itself, so leg 1's green cannot be an accident
        // of the two commits happening to carry the same log. The base the step
        // used IS the moved tip and is NOT the sha the event would have carried.
        $derived = trim((string) shell_exec(
            'cd '.escapeshellarg($repo['dir']).' && bin/pr-base-snapshot.sh '.escapeshellarg($repo['head'])
        ));
        $this->assertSame($repo['movedTip'], $derived);
        $this->assertNotSame($repo['staleBaseSha'], $derived);

        // LEG 3 — THE CONTROL, and the reason this case exists: hand the SAME
        // head the sha the event payload carries and the predicate refuses an
        // innocent PR, naming the base branch's own DL-294. This is PR #640 run
        // 33598959720 in miniature, and it is the red the fix removes. Run
        // against the predicate directly because the step no longer HAS an input
        // that could carry a stale sha — which is the point of the change.
        $rcStale = 0;
        $out = [];
        exec($this->predicateAgainst($repo['dir'], $repo['staleBaseSha'], $repo['targetLog']), $out, $rcStale);
        $this->assertSame(6, $rcStale, implode("\n", $out));
        $this->assertStringContainsString('DL-294', implode("\n", $out));
        $this->assertStringContainsString('ALREADY IN USE', implode("\n", $out));
    }

    /**
     * `bin/decision-log.py check` run on the fixture's head tree against an
     * arbitrary base commit — the shape the workflow ran before card#8527, when
     * the base came from the event payload rather than from the head.
     */
    private function predicateAgainst(string $work, string $baseSha, string $targetLog): string
    {
        $tmp = $work.'/.control-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0o777, true);
        file_put_contents($tmp.'/target.md', $targetLog);
        exec($this->git($work).'show '.escapeshellarg($baseSha.':CLAUDE_DECISIONS.md').' > '.escapeshellarg($tmp.'/base.md').' 2>&1');

        return 'cd '.escapeshellarg($work).' && python3 bin/decision-log.py check'
            .' --head CLAUDE_DECISIONS.md'
            .' --base '.escapeshellarg($tmp.'/base.md')
            .' --target '.escapeshellarg($tmp.'/target.md').' 2>&1';
    }

    // --------------------------------------- the pairing is CHECKED, not assumed

    public function test_a_work_tree_that_is_not_this_prs_merge_fails_loud_instead_of_guessing_a_base(): void
    {
        // The step's base has exactly one source. Standing anywhere other than
        // the merge GitHub built for this event, it must refuse — never fall back
        // to a payload field and never re-derive a fork point, which are the two
        // ways this gate has been wrong.
        $repo = $this->makeRepos($this->log(293), $this->log(293, 295), $this->log(293, 295));
        exec($this->git($repo['dir']).'checkout -q '.escapeshellarg($repo['head']).' 2>&1');

        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);

        // 4 is bin/pr-base-snapshot.sh's documented "HEAD is not a merge" code,
        // propagated by `set -e` through the assignment. Asserted rather than
        // "non-zero" so the step is pinned to SURFACE the helper's refusal rather
        // than catch it and re-report it as one of its own arms.
        $this->assertSame(4, $rc, $out);
        $this->assertStringContainsString('not a two-parent merge commit', $out);
        // Without this leg the case would pass on any failure, including the
        // refusal it is supposed to prove did NOT happen: on this fixture the
        // colliding-number arm would also exit 1.
        $this->assertStringNotContainsString('already in use', $out);
    }

    public function test_a_merge_of_a_different_head_fails_loud(): void
    {
        // A stale refs/pull/N/merge — GitHub rebuilds it per event, so a merge
        // whose second parent is not the head this event names is a merge of a
        // tree nobody pushed. Reporting a verdict on it is worse than refusing.
        $repo = $this->makeRepos($this->log(293), $this->log(293, 295), $this->log(293, 295));

        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['base'], 'BASE_REF' => 'dev']);

        // 5 — the helper's "second parent is not this PR's head" code. See above.
        $this->assertSame(5, $rc, $out);
        $this->assertStringContainsString('is not this PR\'s head sha', $out);
        $this->assertStringNotContainsString('already in use', $out);
    }

    // ------------------------------------------------------------------ fail-loud

    public function test_an_unreadable_decision_log_is_a_gate_failure_not_a_clean_run(): void
    {
        $repo = $this->makeRepos($this->log(293), $this->log(293, 294));
        unlink($repo['dir'].'/CLAUDE_DECISIONS.md');

        [$rc, $out] = $this->runStep($repo['dir'], ['HEAD_SHA' => $repo['head'], 'BASE_REF' => 'dev']);

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('must not be reported as a clean one', $out);
    }
}
