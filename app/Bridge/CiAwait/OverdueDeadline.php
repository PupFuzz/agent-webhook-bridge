<?php

namespace App\Bridge\CiAwait;

use App\Bridge\Support\RedactedErrorText;
use App\Models\CiHeadRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How long a `ci_await` waits before it is OVERDUE — the repo's normal CI time (card#11674). The
 * seat is told once, by `ci_await_overdue`, and checks CI by hand only then.
 *
 * ⭐ DERIVED FROM WHAT THE BRIDGE ALREADY STORES — NO GITHUB READ. `ci_head_runs` holds every
 * `workflow_run` delivery's run ({@see CiHeadRunTracker}). A head's CI time is its LONGEST RUN: one
 * run's time from the bridge's first delivery for it (`created_at`, so queueing counts) to its last
 * update (`updated_at`), over the runs a commit started (below), on the {@see SAMPLE_HEADS} heads of
 * the repo that finished most recently with every such run `completed` and none re-run. The
 * deadline is the {@see PERCENTILE}th percentile of those times (nearest rank) plus a margin of a
 * quarter of it, at least {@see MARGIN_MIN_SECONDS}.
 *
 * ⛔ A SHA IS NOT ONE CI SESSION, SO A HEAD IS NEVER TIMED FROM ITS FIRST RUN TO ITS LAST
 * (card#11952). One SHA collects runs from arrivals hours apart: a PR edit or reopen runs the
 * `pull_request` workflows again as attempt 1, a `dev` → `main` release PR is opened long after
 * the head's `push` runs, and a `schedule` or `workflow_dispatch` run fires on the default branch's
 * unchanged tip. Timed first-to-last, those gaps became CI time and pushed the deadline past the
 * await's own expiry, so `ci_await_overdue` could never fire. Every workflow one arrival starts is
 * created at that arrival, so its longest run is how long the arrival's CI took, and a later
 * arrival cannot stretch it. ⚠ The cost: when one wait spans two arrivals minutes apart (a push,
 * then the PR opened on it), the gap between them is not counted — the margin is what covers it.
 *
 * ⛔ ONLY RUNS A COMMIT STARTED ARE SAMPLED — {@see COMMIT_EVENTS}, an allow-list. A `schedule` or
 * `workflow_dispatch` run is not timed, and need not be complete for its head to be a sample
 * (`ci_await` itself still waits for every run GitHub lists). An event outside the list cuts both
 * ways: a head with ONLY such runs is no sample (fewer samples, then the {@see MIN_HEADS} default),
 * and on a head that also has listed runs a longer unlisted run is left out, so its time is SHORTER
 * and the cue can come early. A deny-list instead would let the next event GitHub adds stretch it.
 *
 * ⚠ A RE-RUN HEAD IS NOT A SAMPLE: a re-run keeps its run's id, so its time includes however long a
 * person took to press re-run. A run whose `requested` delivery was lost starts late and reads
 * short; a redelivered `completed` ends it late. Neither is detected. The percentile keeps one
 * such head out of the answer once there are twenty samples.
 *
 * ⚠ FEWER THAN {@see MIN_HEADS} SAMPLES IS NO HISTORY: the deadline is
 * `BRIDGE_CI_AWAIT_OVERDUE_DEFAULT`, and its basis says `default`. So is a history that could not be
 * read (logged) — a wait is never left without a deadline because the estimate failed.
 *
 * The deadline is counted from REGISTRATION, not from the head's first run: a seat that registers
 * some minutes after pushing is told that much later, never earlier than the repo's normal time.
 */
final class OverdueDeadline
{
    public const SAMPLE_HEADS = 50;

    /** The `workflow_run.event` values that mean a commit arrived: pushed, or a PR's head moved. */
    public const COMMIT_EVENTS = ['push', 'pull_request'];

    public const MIN_HEADS = 5;

    public const PERCENTILE = 95;

    public const MARGIN_MIN_SECONDS = 300;

    public const BASIS_HISTORY = 'history';

    public const BASIS_DEFAULT = 'default';

    public const BASIS_OVERRIDE = 'override';

    /**
     * @param  int  $seconds  how long after registration the wait is overdue
     * @param  string  $basis  {@see BASIS_HISTORY}, {@see BASIS_DEFAULT} or {@see BASIS_OVERRIDE}
     */
    private function __construct(public readonly int $seconds, public readonly string $basis) {}

    /** The caller's own deadline. */
    public static function override(int $seconds): self
    {
        return new self($seconds, self::BASIS_OVERRIDE);
    }

    /** The repo's normal CI time from its tracked heads, else `$defaultSeconds`. Never throws. */
    public static function forRepo(string $repoName, int $defaultSeconds): self
    {
        try {
            $spans = self::recentHeadSpans($repoName);
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: the repo\'s CI history could not be read, so the wait is overdue after the default', [
                'repo' => $repoName,
            ] + RedactedErrorText::logContext($e));

            return new self($defaultSeconds, self::BASIS_DEFAULT);
        }
        if (count($spans) < self::MIN_HEADS) {
            return new self($defaultSeconds, self::BASIS_DEFAULT);
        }
        $p = self::percentile($spans, self::PERCENTILE);

        return new self($p + max(self::MARGIN_MIN_SECONDS, intdiv($p + 3, 4)), self::BASIS_HISTORY);
    }

    /**
     * Nearest-rank percentile of `$values` (not empty).
     *
     * @param  non-empty-list<int>  $values
     */
    public static function percentile(array $values, int $pct): int
    {
        sort($values);

        return $values[max(0, (int) ceil($pct / 100 * count($values)) - 1)];
    }

    /**
     * The CI time, in whole seconds, of each of the repo's {@see SAMPLE_HEADS} most recently
     * finished heads — its longest run of {@see COMMIT_EVENTS} — where every such run is
     * `completed` and none on an attempt past the first.
     *
     * @return list<int>
     */
    private static function recentHeadSpans(string $repoName): array
    {
        $runs = CiHeadRun::query()
            ->where('repo', CiAwaitService::key($repoName))
            ->whereIn('event', self::COMMIT_EVENTS);
        $heads = $runs->clone()
            ->select('head_sha')
            ->groupBy('head_sha')
            ->havingRaw("sum(case when status = 'completed' then 0 else 1 end) = 0")
            ->havingRaw('max(coalesce(run_attempt, 1)) <= 1')
            ->orderByRaw('max(coalesce(updated_at, created_at)) desc')
            ->limit(self::SAMPLE_HEADS)
            ->toBase()
            ->pluck('head_sha')
            ->all();

        $longest = [];
        foreach ($runs->clone()->whereIn('head_sha', $heads)->toBase()->get(['head_sha', 'created_at', 'updated_at']) as $run) {
            $span = max(0, (int) Carbon::parse((string) $run->created_at, 'UTC')->diffInSeconds(Carbon::parse((string) ($run->updated_at ?? $run->created_at), 'UTC'), false));
            $longest[(string) $run->head_sha] = max($longest[(string) $run->head_sha] ?? 0, $span);
        }

        return array_values($longest);
    }
}
