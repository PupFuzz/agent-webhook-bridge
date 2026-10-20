<?php

namespace App\Bridge\CiAwait;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The ONE answer to "is this head settled, and how did it end?" over a list of its workflow runs
 * (card#11667) — used by `ci_await` on the list it reads from GitHub and by the per-head aggregate
 * on the runs the bridge tracked from `workflow_run` deliveries, so the two apply one predicate.
 * They judge different lists: the await only the runs a commit started (below), the aggregate
 * every run delivered.
 *
 * ⭐ THE DECIDING RUNS ARE THE LATEST RUN PER WORKFLOW — keyed on `workflow_id` (the name where
 * that is absent) and ranked by (`run_number`, `id`), the rule `ci-read`'s
 * `_latest_run_per_workflow()` applies. An older run of a workflow that has a newer run on the
 * same head is SUPERSEDED: it is neither terminal-blocking nor red — the newer run decides (a
 * `cancel-in-progress` group's cancelled predecessor is the case this exists for). A re-run keeps
 * its id and bumps `run_attempt` in place, so it is never a second row.
 *
 * ⭐ SETTLED = at least one run, and every deciding run `completed`. No run at all is not settled:
 * CI that has not been queued yet looks exactly like that.
 *
 * ⚠ THE VERDICT IS RUN-LEVEL, AND SAYS SO. `green` when every deciding run concluded one of
 * {@see GREEN_CONCLUSIONS} (`ci-read`'s `BENIGN_CONCLUSIONS`), `red` otherwise — fail-loud, so a
 * conclusion GitHub adds later is red until someone decides it is not. A deciding run that is
 * `cancelled` (nothing newer of its workflow on the head) is red. It is NOT `ci-read`'s verdict:
 * that one reads JOBS and the base branch's required contexts, neither of which a run list
 * carries; the summary says so and names `ci-read` as the authoritative one.
 *
 * ⛔ SETTLED IS ONLY AS COMPLETE AS THE LIST IT JUDGES (card#11938). Over GitHub's own run list
 * (`ci_await`) it says every run a commit started on the head is terminal — and `ci_await` reads
 * each deciding run's jobs before it believes that (card#12072); over the runs the bridge was told of
 * (the per-head aggregate) it says only that those are — a workflow still queued that no delivery
 * has reported is not in that list. The payload's `basis` names which list it judged, and only a
 * GitHub read carries `all_terminal: true`.
 *
 * ⛔ AN AWAIT JUDGES ONLY THE RUNS A COMMIT STARTED (card#11958) — {@see ofCommit()}, over
 * {@see COMMIT_EVENTS}. A `schedule` or `workflow_dispatch` run fires on the default branch's
 * unchanged tip: a nightly run still going held the await open, and a nightly red (a CVE published
 * overnight) turned the wait red though the commit changed nothing. Those runs are dropped BEFORE
 * the latest run per workflow is picked, so a workflow that runs on `push` and on `schedule` is
 * decided by its push run, not by the newer nightly one. ⚠ Any event outside the list is dropped,
 * even one a commit caused (`pull_request_target`, `merge_group`, an `on: workflow_run` follow-on):
 * its run neither holds the head open nor turns it red. The per-head aggregate still uses
 * {@see of()} — every run delivered, whatever started it.
 */
final class HeadRuns
{
    /** The run conclusions read as passing — `ci-read`'s `BENIGN_CONCLUSIONS`. */
    public const GREEN_CONCLUSIONS = ['success', 'neutral', 'skipped'];

    /**
     * The conclusions a red aggregate does NOT list (card#11667, sola-pm on rt#614): every other
     * deciding run — `neutral` included, though it reads green — is named with its url, so a seat
     * can act without reading CI.
     */
    public const UNLISTED_CONCLUSIONS = ['success', 'skipped'];

    public const GREEN = 'green';

    public const RED = 'red';

    /**
     * The `workflow_run.event` values that mean a commit arrived: pushed, or a PR's head moved. An
     * allow-list — a deny-list would let the next event GitHub adds back in. The ONE definition of
     * "this commit's CI": the await's settle ({@see ofCommit()}) and its overdue deadline
     * ({@see OverdueDeadline}) both read it.
     */
    public const COMMIT_EVENTS = ['push', 'pull_request'];

    /** `basis` of a `ci_settled` judged on GitHub's run list for the head: every run on it is terminal, and every job of each deciding run (card#12072). */
    public const BASIS_GITHUB_READ = 'github_read';

    /** `basis` of a `ci_settled` judged on the runs `workflow_run` deliveries reported: those are complete, nothing more. */
    public const BASIS_DELIVERED_RUNS = 'delivered_runs';

    /**
     * @param  list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>  $runs
     * @param  array<int, true>  $deciding  the ids of the latest run per workflow
     */
    private function __construct(public readonly array $runs, private readonly array $deciding, private readonly int $notCommitStarted = 0) {}

    /**
     * @param  list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>  $runs
     */
    public static function of(array $runs): self
    {
        $latest = [];
        foreach ($runs as $run) {
            $key = $run['workflow_id'] !== null ? 'id:'.$run['workflow_id'] : 'name:'.$run['workflow'];
            if (! isset($latest[$key]) || self::rank($run) > self::rank($latest[$key])) {
                $latest[$key] = $run;
            }
        }
        $deciding = [];
        foreach ($latest as $run) {
            $deciding[$run['id']] = true;
        }

        return new self($runs, $deciding);
    }

    /**
     * {@see of()} over only the runs whose `event` is one of {@see COMMIT_EVENTS}. The rest are
     * not in {@see $runs}, so they are neither judged nor listed in the payload; the summary
     * counts them.
     *
     * @param  list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>  $runs
     */
    public static function ofCommit(array $runs): self
    {
        $commit = array_values(array_filter($runs, static fn (array $r): bool => in_array($r['event'], self::COMMIT_EVENTS, true)));
        $head = self::of($commit);

        return new self($head->runs, $head->deciding, count($runs) - count($commit));
    }

    public function settled(): bool
    {
        if ($this->deciding === []) {
            return false;
        }
        foreach ($this->decidingRuns() as $run) {
            if ($run['status'] !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `$runId` is a run of this list that a newer run of its workflow decides instead. A run
     * the list does not hold is not superseded: nothing known says so.
     */
    public function supersedes(int $runId): bool
    {
        foreach ($this->runs as $run) {
            if ($run['id'] === $runId) {
                return ! isset($this->deciding[$runId]);
            }
        }

        return false;
    }

    /** `green` or `red` — meaningful only once {@see settled()}. */
    public function verdict(): string
    {
        foreach ($this->decidingRuns() as $run) {
            if (! in_array(strtolower((string) $run['conclusion']), self::GREEN_CONCLUSIONS, true)) {
                return self::RED;
            }
        }

        return self::GREEN;
    }

    /**
     * Names the settled STATE: the deciding runs by id, attempt and conclusion. Two reads that
     * agree on it describe one state; a re-run, a new workflow's run or a changed conclusion
     * makes a different one.
     */
    public function fingerprint(): string
    {
        $parts = array_map(
            static fn (array $r): string => $r['id'].':'.($r['run_attempt'] ?? 0).':'.strtolower((string) $r['conclusion']),
            $this->decidingRuns(),
        );
        sort($parts);

        return sha1(implode(',', $parts));
    }

    /**
     * Every run, each `{workflow, conclusion, html_url, superseded}` — `superseded` true for a run
     * a newer run of its workflow decides instead.
     *
     * @return list<array{workflow: string, conclusion: ?string, html_url: string, superseded: bool}>
     */
    public function payloadRuns(): array
    {
        return array_map(fn (array $r): array => [
            'workflow' => $r['workflow'],
            'conclusion' => $r['conclusion'],
            'html_url' => $r['html_url'],
            'superseded' => ! isset($this->deciding[$r['id']]),
        ], $this->runs);
    }

    /**
     * The deciding runs whose conclusion is not one of {@see UNLISTED_CONCLUSIONS}, each
     * `{workflow, conclusion, html_url}`. A superseded run is never listed: a newer run decides.
     *
     * @return list<array{workflow: string, conclusion: ?string, html_url: string}>
     */
    public function nonSuccessRuns(): array
    {
        return array_values(array_map(
            static fn (array $r): array => ['workflow' => $r['workflow'], 'conclusion' => $r['conclusion'], 'html_url' => $r['html_url']],
            array_filter($this->decidingRuns(), static fn (array $r): bool => ! in_array(strtolower((string) $r['conclusion']), self::UNLISTED_CONCLUSIONS, true)),
        ));
    }

    /**
     * The `ci_settled` payload both senders carry, judged on the list `$basis` names
     * ({@see BASIS_GITHUB_READ} or {@see BASIS_DELIVERED_RUNS}). `all_terminal` is `true` only on a
     * GitHub read; on delivered runs it is null — not known, because no list of every run was read.
     *
     * @return array<string, mixed>
     */
    public function settledPayload(string $repoName, string $headSha, ?int $pr, DateTimeInterface $measuredAt, string $basis): array
    {
        return [
            'repo' => $repoName,
            'head_sha' => $headSha,
            'pr' => $pr,
            'basis' => $basis,
            'runs' => $this->payloadRuns(),
            'all_terminal' => $basis === self::BASIS_GITHUB_READ ? true : null,
            'runs_verdict' => $this->verdict(),
            'non_success_runs' => $this->nonSuccessRuns(),
            'measured_at' => Carbon::instance($measuredAt)->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    public function settledSummary(string $repoName, string $headSha, ?int $pr, string $basis): string
    {
        $deciding = $this->decidingRuns();
        $superseded = count($this->runs) - count($deciding);
        $where = "{$repoName}@".substr($headSha, 0, 12).($pr === null ? '' : " (PR #{$pr})");
        $delivered = $basis === self::BASIS_DELIVERED_RUNS;
        $head = $delivered ? "On {$where}, every workflow run delivered so far is complete" : "CI settled on {$where}";
        if ($this->verdict() === self::GREEN) {
            $detail = 'GREEN — the latest run of each of '.count($deciding).' workflow(s)'.($delivered ? ' delivered so far' : '').' concluded success, neutral or skipped';
        } else {
            $detail = 'RED — '.implode(', ', array_map(static fn (array $r): string => $r['workflow'].' → '.($r['conclusion'] ?? 'no conclusion').' ('.$r['html_url'].')', $this->nonSuccessRuns()));
        }

        return "{$head}: {$detail}".($superseded > 0 ? " ({$superseded} superseded run(s) not counted)" : '')
            .($this->notCommitStarted > 0 ? " ({$this->notCommitStarted} run(s) no commit started (not ".implode(' or ', self::COMMIT_EVENTS).') not counted — ci-read still reports them)' : '')
            .($delivered ? '. Decided from the workflow_run deliveries this bridge received, not GitHub\'s run list: a run no delivery has reported yet (one still queued) is not counted, and ci_await reads the full list' : '')
            .'. Run-level only: ci-read stays the authoritative verdict (it reads jobs and the branch\'s required checks).';
    }

    /**
     * The latest run per workflow — the runs that decide {@see settled()} and {@see verdict()}.
     *
     * @return list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>
     */
    public function decidingRuns(): array
    {
        return array_values(array_filter($this->runs, fn (array $r): bool => isset($this->deciding[$r['id']])));
    }

    /**
     * @param  array{id: int, run_number: ?int}  $run
     * @return array{0: int, 1: int}
     */
    private static function rank(array $run): array
    {
        return [$run['run_number'] ?? 0, $run['id']];
    }
}
