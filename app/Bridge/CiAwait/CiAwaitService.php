<?php

namespace App\Bridge\CiAwait;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Exceptions\CiRunsReadException;
use App\Bridge\Scheduling\Handlers\CiAwaitSweepJob;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Support\AuthoredIntentPush;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\GitHubReadClient;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Models\CiAwait;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * The `ci_settled` wake (card#11200 / DL-452): a seat declares that it is waiting for CI on one
 * head SHA, and the bridge tells it ONCE when every workflow run GitHub lists for that head is
 * terminal — so the seat stops polling GitHub for it.
 *
 * ⭐ THE ONLY THING THE BRIDGE DECIDES IS "EVERY RUN IS `completed`". It does NOT decide green or
 * red: a verdict needs the base branch's required contexts and the latest run per workflow, which
 * is `ci-read`'s definition, and a second one here would drift from it. The payload carries each
 * run's conclusion as data; the seat runs `ci-read` once on the head for the verdict.
 *
 * ⭐ NO AWAIT, NO READ. {@see onWorkflowRunCompleted()} looks the head up in `ci_awaits` before
 * anything else, so a run completing on a head nobody awaits costs one indexed query and no GitHub
 * request. A head that IS awaited costs one paginated runs read per completed run on it.
 *
 * ⭐ ONCE PER AWAIT, BY CLAIM. Two `workflow_run.completed` deliveries for the last two runs of one
 * head can both read "all terminal". Each emit first DELETES its await row inside a transaction and
 * emits only when that delete removed it, so exactly one of them emits ({@see claimAndEmit()}). The
 * same claim serves the registration-time read and the expiry sweep, so an await is settled or
 * expired, never both.
 *
 * ⚠ A READ THAT FAILS EMITS NOTHING. The await is kept with the error recorded, logged by name, and
 * read again on the next completed run on that head or by {@see CiAwaitSweepJob}; if no read ever
 * answers, the seat gets `ci_await_expired` carrying the last error at expiry.
 *
 * Emitted intents are STAGED to the inbox and then pushed live: the await is gone once claimed, so
 * the inbox line is what carries the wake to a seat whose channel was down at the moment.
 */
final class CiAwaitService
{
    public const SETTLED = 'ci_settled';

    public const EXPIRED = 'ci_await_expired';

    /** Inside the webhook's after-response callback and a job pass, never a human's patience. */
    public const TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly HandlerRegistry $handlers,
        private readonly IntentLog $intents,
    ) {}

    /**
     * The configured spelling of `$repo` among this install's GitHub subscriptions (compared
     * case-insensitively, as GitHub compares repo names), or null when no agent here subscribes to
     * it — this install receives no GitHub events for that repo, so nothing would ever settle it.
     */
    public static function receivedRepo(string $repo): ?string
    {
        foreach ((new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs() as $cfg) {
            foreach ($cfg->subscriptions as $sub) {
                if ($sub->provider === 'github' && strcasecmp($sub->scopeId, $repo) === 0) {
                    return $sub->scopeId;
                }
            }
        }

        return null;
    }

    /**
     * Whether this install holds a stored `workflow_run` delivery from `$repo`. TRUE is evidence the
     * repo's webhook sends workflow runs here; FALSE is not evidence it does not — retention may have
     * pruned them, or no run has completed since the webhook was added.
     */
    public static function hasRecordedWorkflowRun(string $repo): bool
    {
        return WebhookEvent::query()
            ->where('provider', 'github')
            ->where('scope_id', $repo)
            ->where('event_type', 'like', 'workflow_run.%')
            ->exists();
    }

    /**
     * Store (or refresh) `$agent`'s await on the head, then read the head once — so CI that
     * finished before the seat registered settles it now.
     *
     * @return array{refreshed: bool, state: string, pr: ?int, expires_at: ?string, runs_total: ?int, runs_completed: ?int, read_error: ?string}
     */
    public function register(string $agent, string $repo, string $headSha, ?int $pr, int $ttlSeconds): array
    {
        $expiresAt = Carbon::now()->addSeconds($ttlSeconds);
        $refreshed = $this->upsert($agent, $repo, $headSha, $pr, $expiresAt);
        $this->declareSweep();

        $read = $this->evaluate($repo, $headSha);
        $mine = CiAwait::query()->where('agent', $agent)->where('repo', $repo)->where('head_sha', $headSha)->first();

        $state = match (true) {
            $read['error'] !== null => 'unmeasured',
            $mine === null && $read['all_terminal'] => 'settled',
            default => 'waiting',
        };

        return [
            'refreshed' => $refreshed,
            'state' => $state,
            'pr' => $mine === null ? $pr : $mine->pr,
            'expires_at' => $mine === null ? null : self::instant($mine->expires_at),
            'runs_total' => $read['runs'] === null ? null : count($read['runs']),
            'runs_completed' => $read['runs'] === null ? null : count(array_filter($read['runs'], static fn (array $r): bool => $r['status'] === 'completed')),
            'read_error' => $read['error'],
        ];
    }

    /** Remove `$agent`'s own await on the head. Returns whether there was one. */
    public function cancel(string $agent, string $repo, string $headSha): bool
    {
        return CiAwait::query()
            ->where('agent', $agent)
            ->whereRaw('lower(repo) = ?', [strtolower($repo)])
            ->where('head_sha', $headSha)
            ->delete() > 0;
    }

    /**
     * A `workflow_run.completed` arrived for `$repo` at `$headSha`. Never throws: it runs after the
     * response, where nothing would report a throw.
     */
    public function onWorkflowRunCompleted(string $repo, string $headSha): void
    {
        try {
            $this->evaluate($repo, $headSha);
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: evaluating a completed workflow run failed — any await on this head is kept and re-read on the next completed run or by the ci_await sweep', [
                'repo' => $repo, 'head_sha' => $headSha,
            ] + RedactedErrorText::logContext($e));
        }
    }

    /**
     * Emit `ci_await_expired` for up to `$limit` awaits past their expiry, oldest first. Returns how
     * many this call emitted (a concurrent claim of the same await emits there instead).
     */
    public function expireDue(int $limit): int
    {
        $emitted = 0;
        $due = CiAwait::query()->where('expires_at', '<=', Carbon::now())->orderBy('expires_at')->orderBy('id')->limit($limit)->get();
        foreach ($due as $await) {
            $emitted += $this->claimAndEmit($await, self::EXPIRED, self::expiredPayload($await), self::expiredSummary($await)) ? 1 : 0;
        }

        return $emitted;
    }

    /**
     * Re-read up to `$limit` heads whose last read FAILED and that have not expired, least recently
     * read first. Only failed reads are retried on the clock: a head whose read answered is
     * re-read when its next run completes, never on a timer. Returns how many heads were read.
     */
    public function retryUnmeasured(int $limit): int
    {
        $heads = CiAwait::query()
            ->whereNotNull('last_error')
            ->where('expires_at', '>', Carbon::now())
            ->select('repo', 'head_sha')
            ->selectRaw('min(last_read_at) as oldest_read')
            ->groupBy('repo', 'head_sha')
            ->orderBy('oldest_read')
            ->limit($limit)
            ->get();
        foreach ($heads as $head) {
            $this->evaluate((string) $head->repo, (string) $head->head_sha);
        }

        return $heads->count();
    }

    /**
     * Read the head once and settle every await on it when every run is terminal.
     *
     * ⚠ The awaits are loaded BEFORE the read, deliberately: the read is slow, and a concurrent
     * evaluation of the same head may claim them meanwhile — {@see claimAndEmit()} is what makes
     * that safe, not the order.
     *
     * @return array{runs: ?list<array{workflow: string, status: string, conclusion: ?string, html_url: string, event: string}>, error: ?string, all_terminal: bool}
     */
    private function evaluate(string $repo, string $headSha): array
    {
        $awaits = CiAwait::query()->where('repo', $repo)->where('head_sha', $headSha)->orderBy('id')->get();
        if ($awaits->isEmpty()) {
            return ['runs' => null, 'error' => null, 'all_terminal' => false];
        }

        $measuredAt = Carbon::now();
        $ids = $awaits->pluck('id')->all();
        try {
            $runs = $this->readRuns($repo, $headSha);
        } catch (CiRunsReadException $e) {
            // The column holds 1000 characters; a longer error would fail the write that records it.
            CiAwait::query()->whereKey($ids)->update(['last_read_at' => $measuredAt, 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::warning('bridge ci_await: the workflow-run read failed, so nothing was emitted — the await is kept and re-read on the next completed run on this head or by the ci_await sweep, and expires with this error if no read answers', [
                'repo' => $repo, 'head_sha' => $headSha, 'awaits' => count($ids), 'error' => $e->getMessage(),
            ]);

            return ['runs' => null, 'error' => $e->getMessage(), 'all_terminal' => false];
        }
        CiAwait::query()->whereKey($ids)->update(['last_read_at' => $measuredAt, 'last_error' => null]);

        // No run at all is NOT settled: CI that has not been queued yet looks exactly like this.
        $allTerminal = $runs !== [] && array_filter($runs, static fn (array $r): bool => $r['status'] !== 'completed') === [];
        if ($allTerminal) {
            foreach ($awaits as $await) {
                $this->claimAndEmit($await, self::SETTLED, self::settledPayload($await, $runs, $measuredAt), self::settledSummary($await, count($runs)));
            }
        }

        return ['runs' => $runs, 'error' => null, 'all_terminal' => $allTerminal];
    }

    /**
     * Claim the await by deleting it, and emit only if this call's delete removed it. The inbox
     * line is written inside the same transaction, so a staging failure rolls the claim back and
     * the await survives to be emitted later; the live push runs after the commit and is best-effort.
     *
     * @param  array<string, mixed>  $payload
     */
    private function claimAndEmit(CiAwait $await, string $kind, array $payload, string $summary): bool
    {
        $intent = new Intent(
            kind: $kind,
            subjectId: "ci:{$await->repo}@{$await->head_sha}",
            provider: 'bridge',
            actor: new Actor(id: null),
            summary: $summary,
            payload: $payload,
        );

        $claimed = DB::transaction(function () use ($await, $kind, $intent): bool {
            if (CiAwait::query()->whereKey($await->id)->delete() !== 1) {
                return false;
            }
            $this->intents->stageAuthored($await->agent, "{$kind}:{$await->id}", microtime(true), $intent);

            return true;
        });
        if (! $claimed) {
            return false;
        }

        Log::info("bridge ci_await: {$kind} emitted", ['agent' => $await->agent, 'repo' => $await->repo, 'head_sha' => $await->head_sha]);
        try {
            (new AuthoredIntentPush($this->handlers))->send($intent, $await->agent);
        } catch (Throwable $e) {
            Log::warning("bridge ci_await: the live push of {$kind} failed — the intent is staged in the agent's inbox, which bridge:inbox surfaces", [
                'agent' => $await->agent, 'repo' => $await->repo, 'head_sha' => $await->head_sha,
            ] + RedactedErrorText::logContext($e));
        }

        return true;
    }

    /**
     * @return list<array{workflow: string, status: string, conclusion: ?string, html_url: string, event: string}>
     *
     * @throws CiRunsReadException naming why no complete run list was read
     */
    private function readRuns(string $repo, string $headSha): array
    {
        $token = (new GitHubTokenResolver)->resolveFor($repo);
        if (! $token->ok()) {
            throw new CiRunsReadException('no GitHub read token: '.$token->problem);
        }

        try {
            return (new GitHubReadClient((string) $token->token, self::TIMEOUT_SECONDS))->workflowRunsForHead($repo, $headSha);
        } catch (RequestException $e) {
            $status = $e->response->status();
            $remaining = $e->response->header('X-RateLimit-Remaining');
            $limited = $status === 429 || ($status === 403 && $remaining === '0');

            throw new CiRunsReadException("GitHub answered HTTP {$status} to the workflow-run read".($limited ? ' (rate limited)' : ''));
        } catch (ConnectionException|UnexpectedValueException $e) {
            throw new CiRunsReadException(RedactedErrorText::of($e));
        }
    }

    private function upsert(string $agent, string $repo, string $headSha, ?int $pr, Carbon $expiresAt): bool
    {
        $mine = CiAwait::query()->where('agent', $agent)->where('repo', $repo)->where('head_sha', $headSha);
        // A re-registration WITHOUT a pr keeps the one already recorded; one with a pr replaces it.
        $refresh = ['expires_at' => $expiresAt] + ($pr === null ? [] : ['pr' => $pr]);
        if ($mine->clone()->update($refresh) > 0) {
            return true;
        }
        try {
            CiAwait::query()->create(['agent' => $agent, 'repo' => $repo, 'head_sha' => $headSha, 'pr' => $pr, 'expires_at' => $expiresAt]);

            return false;
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration of the same head by the same seat created it first.
            $mine->clone()->update($refresh);

            return true;
        }
    }

    /**
     * Make sure the sweep that emits `ci_await_expired` (and retries failed reads) has an instance
     * to run. Never fails the registration: the await is stored either way, and `bridge:check`'s
     * `ci_await.awaits` leg names a sweep that cannot run.
     */
    private function declareSweep(): void
    {
        try {
            app(JobRegistry::class)->declareIfAbsent(CiAwaitSweepJob::spec());
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: could not declare the ci_await sweep job — awaits will not expire, and failed reads will not be retried on a clock, until it exists', [
                'error' => RedactedErrorText::of($e),
                'remedy' => 'php artisan bridge:jobs add '.CiAwaitSweepJob::INSTANCE.' --handler='.CiAwaitSweepJob::NAME.' (docs/periodic-jobs.md)',
            ]);
        }
    }

    /**
     * @param  list<array{workflow: string, status: string, conclusion: ?string, html_url: string, event: string}>  $runs
     * @return array<string, mixed>
     */
    private static function settledPayload(CiAwait $await, array $runs, Carbon $measuredAt): array
    {
        return [
            'repo' => $await->repo,
            'head_sha' => $await->head_sha,
            'pr' => $await->pr,
            'runs' => array_map(static fn (array $r): array => ['workflow' => $r['workflow'], 'conclusion' => $r['conclusion'], 'html_url' => $r['html_url']], $runs),
            'all_terminal' => true,
            'measured_at' => self::instant($measuredAt),
        ];
    }

    private static function settledSummary(CiAwait $await, int $runs): string
    {
        return "CI settled on {$await->repo}@".substr($await->head_sha, 0, 12).($await->pr === null ? '' : " (PR #{$await->pr})")
            .": all {$runs} workflow run(s) are terminal. This is not a verdict — run ci-read once on this head for green/red.";
    }

    /** @return array<string, mixed> */
    private static function expiredPayload(CiAwait $await): array
    {
        return [
            'repo' => $await->repo,
            'head_sha' => $await->head_sha,
            'pr' => $await->pr,
            'registered_at' => self::instant($await->created_at),
            'expires_at' => self::instant($await->expires_at),
            'last_read_at' => $await->last_read_at === null ? null : self::instant($await->last_read_at),
            'last_error' => $await->last_error,
        ];
    }

    private static function expiredSummary(CiAwait $await): string
    {
        return "Stopped waiting for CI on {$await->repo}@".substr($await->head_sha, 0, 12).($await->pr === null ? '' : " (PR #{$await->pr})")
            .' — the await expired before every workflow run was seen terminal'
            .($await->last_error === null ? '' : " (last read failed: {$await->last_error})")
            .'. Run ci-read on this head, or re-register with ci_await.';
    }

    private static function instant(Carbon $at): string
    {
        return $at->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
