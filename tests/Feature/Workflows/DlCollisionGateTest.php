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
 * whole reason it can catch a number the target branch took after this PR
 * branched off. A fixture that handed it a pre-computed file would be asserting
 * the fixture; a single repo with no `origin` would not exercise the fetch at
 * all. So the fixture is an upstream repository the work tree is cloned from,
 * and the "the target branch moved under the PR" cases advance it for real.
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
     * $headLog, and — when $movedTargetLog is given — an upstream `dev` that has
     * moved on since.
     *
     * @return array{dir:string,base:string}
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

        if ($movedTargetLog !== null) {
            file_put_contents($upstream.'/CLAUDE_DECISIONS.md', $movedTargetLog);
            exec($up.'add -A && '.$up.'commit -q -m "dev moves on" 2>&1');
        }

        // The step invokes it by repo-relative path; give it the real one.
        mkdir($work.'/bin', 0o777, true);
        copy(base_path('bin/decision-log.py'), $work.'/bin/decision-log.py');

        return ['dir' => $work, 'base' => $baseSha];
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

        [$rc, $out] = $this->runStep($repo['dir'], ['BASE' => $repo['base'], 'BASE_REF' => 'dev']);

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

        [$rc, $out] = $this->runStep($repo['dir'], ['BASE' => $repo['base'], 'BASE_REF' => 'dev']);

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

        [, $out] = $this->runStep($repo['dir'], ['BASE' => $repo['base'], 'BASE_REF' => 'dev']);

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

        [$rc, $out] = $this->runStep($repo['dir'], ['BASE' => $repo['base'], 'BASE_REF' => 'dev']);

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

        [$rc, $out] = $this->runStep($repo['dir'], ['BASE' => $repo['base'], 'BASE_REF' => 'dev']);

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

        [$rc, $out] = $this->runStep($repo['dir'], ['BASE' => $repo['base'], 'BASE_REF' => 'dev']);

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

        [$rcFirst, $outFirst] = $this->runStep($first['dir'], ['BASE' => $first['base'], 'BASE_REF' => 'dev']);
        [$rcSecond, $outSecond] = $this->runStep($second['dir'], ['BASE' => $second['base'], 'BASE_REF' => 'dev']);

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

        [$rc, $out] = $this->runStep($repo['dir'], ['BASE' => $repo['base'], 'BASE_REF' => 'dev']);

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('DL-295', $out);
    }

    // ------------------------------------------------------------------ fail-loud

    public function test_an_unreadable_decision_log_is_a_gate_failure_not_a_clean_run(): void
    {
        $repo = $this->makeRepos($this->log(293), $this->log(293, 294));
        unlink($repo['dir'].'/CLAUDE_DECISIONS.md');

        [$rc, $out] = $this->runStep($repo['dir'], ['BASE' => $repo['base'], 'BASE_REF' => 'dev']);

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('must not be reported as a clean one', $out);
    }
}
