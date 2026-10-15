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
 * `workflow_run` delivery's run ({@see CiHeadRunTracker}). A head's CI time is from the first
 * run the bridge heard of on it (`min(created_at)`) to the last run's last update
 * (`max(updated_at)`), over the runs a commit started (below) of the {@see SAMPLE_HEADS} heads of
 * the repo that finished most recently with every such run `completed` and none re-run. The
 * deadline is the {@see PERCENTILE}th percentile of those times (nearest rank) plus a margin of a
 * quarter of it, at least {@see MARGIN_MIN_SECONDS}.
 *
 * ⛔ ONLY RUNS A COMMIT STARTED ARE TIMED — {@see COMMIT_EVENTS}, an allow-list (card#11952). A SHA is
 * not one CI session: a `schedule` or `workflow_dispatch` run fires on the default branch's unchanged
 * tip hours or days after its commit's CI, and on the same `head_sha`. Counted, it stretched a head's time to
 * the next nightly scan, and the deadline past the await's own expiry, so `ci_await_overdue` could
 * never fire. Such a run is neither timed nor waited for: one still open does not take its head out
 * of the sample. An allow-list, not a deny-list, because an event GitHub adds later then leaves the
 * sample smaller — the {@see MIN_HEADS} fallback to the default — instead of stretching it. ⚠ It
 * cannot tell two arrivals on one SHA apart: a `pull_request` run that a PR edit or reopen starts
 * later, or a PR opened on a head long after its `push` runs, still joins that head's time.
 *
 * ⚠ A RE-RUN HEAD IS NOT A SAMPLE: its span includes however long a person took to press re-run.
 * A run whose `requested` delivery was lost starts its head's span late, and a redelivered
 * `completed` ends it late; neither is detected. The percentile keeps one such head out of the
 * answer once there are twenty samples.
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
     * finished heads, over the runs of {@see COMMIT_EVENTS} only: each of them `completed`, none on
     * an attempt past the first.
     *
     * @return list<int>
     */
    private static function recentHeadSpans(string $repoName): array
    {
        $rows = CiHeadRun::query()
            ->where('repo', CiAwaitService::key($repoName))
            ->whereIn('event', self::COMMIT_EVENTS)
            ->selectRaw('head_sha, min(created_at) as started_at, max(coalesce(updated_at, created_at)) as finished_at')
            ->groupBy('head_sha')
            ->havingRaw("sum(case when status = 'completed' then 0 else 1 end) = 0")
            ->havingRaw('max(coalesce(run_attempt, 1)) <= 1')
            ->orderByRaw('max(coalesce(updated_at, created_at)) desc')
            ->limit(self::SAMPLE_HEADS)
            ->toBase()
            ->get();

        $spans = [];
        foreach ($rows as $row) {
            $spans[] = max(0, (int) Carbon::parse((string) $row->started_at, 'UTC')->diffInSeconds(Carbon::parse((string) $row->finished_at, 'UTC'), false));
        }

        return $spans;
    }
}
