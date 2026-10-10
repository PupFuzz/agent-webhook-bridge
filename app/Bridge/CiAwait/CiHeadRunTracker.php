<?php

namespace App\Bridge\CiAwait;

use App\Bridge\Support\RedactedErrorText;
use App\Models\CiHeadRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records every `workflow_run` delivery — `requested`, `in_progress`, `completed` — as the run's
 * row in `ci_head_runs` (card#11667), so whether a head is settled is decided from what GitHub
 * already TOLD the bridge, with no GitHub read.
 *
 * ⭐ RUN BEFORE DISPATCH, IN THE REQUEST. The aggregate is decided by a handler inside the dispatch
 * loop, which must see the run this delivery reports; and the write is one indexed statement.
 *
 * ⭐ A ROW NEVER MOVES BACKWARDS. Deliveries are not ordered: a `requested` can arrive after its
 * `completed`, and an operator can redeliver an old one. A delivery is applied only when its
 * `run_attempt` is later than the row's, or equal with a status at least as far along
 * (`requested`/`queued`/`waiting` < `in_progress` < `completed`). The test is the UPDATE's own
 * WHERE clause, so two concurrent deliveries for one run cannot interleave a read and a write.
 */
final class CiHeadRunTracker
{
    public const EVENT_PREFIX = 'workflow_run.';

    private const FULL_SHA = '/\A[0-9a-f]{40}\z/';

    /** @param  array<mixed>  $payload */
    public function record(string $provider, string $eventType, string $scopeId, array $payload): void
    {
        if ($provider !== 'github' || ! str_starts_with($eventType, self::EVENT_PREFIX)) {
            return;
        }
        $row = self::row($scopeId, $payload);
        if ($row === null) {
            return;
        }

        try {
            if (CiHeadRun::query()->insertOrIgnore([$row + ['created_at' => now(), 'updated_at' => now()]]) === 1) {
                return;
            }

            $attempt = $row['run_attempt'] ?? 0;
            $rank = self::statusRank($row['status']);
            $update = $row;
            if ($update['pr'] === null) {
                unset($update['pr']);
            }
            CiHeadRun::query()->where('run_id', $row['run_id'])
                ->where(fn ($q) => $q->whereRaw('coalesce(run_attempt, 0) < ?', [$attempt])
                    ->orWhere(fn ($q) => $q->whereRaw('coalesce(run_attempt, 0) = ?', [$attempt])
                        ->whereRaw("(case status when 'completed' then 2 when 'in_progress' then 1 else 0 end) <= ?", [$rank])))
                ->update($update + ['updated_at' => now()]);
        } catch (Throwable $e) {
            // Never the delivery's 5xx: the dispatch that follows answers for the database. A run
            // missed here is a run the aggregate does not count, and this line is what says so.
            Log::warning('bridge ci_head: the workflow run could not be recorded — the per-head ci_settled for its head cannot count it', [
                'repo' => $scopeId, 'head_sha' => $row['head_sha'], 'run_id' => $row['run_id'],
            ] + RedactedErrorText::logContext($e));
        }
    }

    /**
     * The tracked runs of one head, in the shape {@see HeadRuns::of()} takes.
     *
     * @return list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>
     */
    public static function runsOf(string $repo, string $headSha): array
    {
        return array_values(CiHeadRun::query()->where('repo', CiAwaitService::key($repo))->where('head_sha', $headSha)->orderBy('run_id')->get()
            ->map(static fn (CiHeadRun $r): array => [
                'id' => $r->run_id,
                'workflow' => $r->workflow,
                'workflow_id' => $r->workflow_id,
                'run_number' => $r->run_number,
                'status' => $r->status,
                'conclusion' => $r->conclusion,
                'html_url' => $r->html_url,
                'event' => $r->event,
                'run_attempt' => $r->run_attempt,
            ])->all());
    }

    /** Whether a tracked run of the head is superseded by a newer run of its workflow. */
    public static function isSuperseded(string $repo, string $headSha, int $runId): bool
    {
        return HeadRuns::of(self::runsOf($repo, $headSha))->supersedes($runId);
    }

    /** The PR a tracked run of the head named, or null when none did. */
    public static function prOf(string $repo, string $headSha): ?int
    {
        $pr = CiHeadRun::query()->where('repo', CiAwaitService::key($repo))->where('head_sha', $headSha)->whereNotNull('pr')->orderByDesc('run_id')->value('pr');

        return $pr === null ? null : (int) $pr;
    }

    /**
     * @param  array<mixed>  $payload
     * @return ?array{run_id: int, repo: string, repo_name: string, head_sha: string, workflow_id: ?int, workflow: string, run_number: ?int, run_attempt: ?int, event: string, status: string, conclusion: ?string, html_url: string, pr: ?int}
     */
    private static function row(string $scopeId, array $payload): ?array
    {
        $run = $payload['workflow_run'] ?? null;
        if (! is_array($run) || ! is_int($run['id'] ?? null) || ! is_string($run['status'] ?? null)) {
            return null;
        }
        $headSha = $run['head_sha'] ?? null;
        if (! is_string($headSha) || preg_match(self::FULL_SHA, $headSha) !== 1) {
            return null;
        }
        $prs = is_array($run['pull_requests'] ?? null) ? $run['pull_requests'] : [];
        $first = is_array($prs[0] ?? null) ? $prs[0] : [];

        return [
            'run_id' => $run['id'],
            'repo' => CiAwaitService::key($scopeId),
            'repo_name' => $scopeId,
            'head_sha' => $headSha,
            'workflow_id' => is_int($run['workflow_id'] ?? null) ? $run['workflow_id'] : null,
            'workflow' => mb_substr(is_string($run['name'] ?? null) ? $run['name'] : '', 0, 255),
            'run_number' => is_int($run['run_number'] ?? null) ? $run['run_number'] : null,
            'run_attempt' => is_int($run['run_attempt'] ?? null) ? $run['run_attempt'] : null,
            'event' => mb_substr(is_string($run['event'] ?? null) ? $run['event'] : '', 0, 64),
            'status' => mb_substr($run['status'], 0, 32),
            'conclusion' => is_string($run['conclusion'] ?? null) ? mb_substr($run['conclusion'], 0, 32) : null,
            'html_url' => mb_substr(is_string($run['html_url'] ?? null) ? $run['html_url'] : '', 0, 512),
            'pr' => is_int($first['number'] ?? null) ? $first['number'] : null,
        ];
    }

    private static function statusRank(string $status): int
    {
        return match ($status) {
            'completed' => 2,
            'in_progress' => 1,
            default => 0,
        };
    }
}
