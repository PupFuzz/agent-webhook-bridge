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
use App\Bridge\Support\RefusalContext;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\GitHubReadClient;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Models\CiAwait;
use App\Models\WebhookEvent;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use UnexpectedValueException;

/**
 * The `ci_settled` wake (card#11200 / DL-452): a seat declares that it is waiting for CI on one
 * head SHA, and the bridge tells it when every workflow run GitHub lists for that head is terminal —
 * so the seat stops polling GitHub for it.
 *
 * ⭐ THE GUARANTEE. One terminal event per await (`ci_settled` or `ci_await_expired`), written to the
 * seat's inbox at least once and idempotent by its line id, then pushed live once — the push carries no
 * line id and is unconfirmed (DL-370). The id's form and why are defined once, in `docs/board-tools.md`
 * § `ci_await` and `ci_await_cancel`.
 * `ci_settled` follows CI finishing by at most the time until the sweep next reads the head
 * ({@see sweepUnsettled()}: a head none of whose awaits was read, or whose oldest read is one sweep interval old, up to
 * the per-pass cap), PROVIDED THE SWEEP RUNS — a pass needs a webhook or `bridge:tick`.
 *
 * ⭐ THE ONLY THING THE BRIDGE DECIDES IS "EVERY RUN IS `completed`". It does NOT decide green or
 * red: a verdict needs the base branch's required contexts and the latest run per workflow, which
 * is `ci-read`'s definition, and a second one here would drift from it. The payload carries each
 * run's conclusion as data; the seat runs `ci-read` once on the head for the verdict.
 *
 * ⭐ LEVEL-TRIGGERED: THE SWEEP CARRIES CORRECTNESS, DELIVERIES ARE ACCELERATORS. A delivery's read
 * settles only the awaits it loaded, a registration's read can see a list that lags, and a final
 * delivery can be lost; any of those leaves an await that no further event will touch. So nothing
 * here depends on an event: the sweep reads every unsettled, unexpired head whose last read is
 * stale. The delivered-run overlay, the registration cooldown, `retry_not_before` and the claim
 * make it sooner or cheaper; none of them is what wakes a seat.
 *
 * ⭐ NO AWAIT, NO READ. {@see onWorkflowRunCompleted()} looks the head up in `ci_awaits` before
 * anything else, so a run completing on a head nobody awaits costs one indexed query and no GitHub
 * request. A head that IS awaited costs one paginated runs read per completed run on it, plus the
 * sweep's reads.
 *
 * ⭐ ONE EVENT PER AWAIT, BY CLAIM. Two `workflow_run.completed` deliveries for the last two runs of
 * one head can both read "all terminal". Each emit first DELETES its await row inside a transaction
 * and emits only when that delete removed it, so exactly one of them emits ({@see claimAndEmit()}).
 * The same claim serves the registration-time read and the sweep, so an await is settled or
 * expired, never both. An inbox line that cannot be written rolls the claim back and marks THAT row
 * only; the other awaits on the head are still claimed, and the failed one is emitted on a later pass.
 *
 * ⚠ A READ THAT FAILS EMITS NOTHING. The await is kept with the error recorded and logged by name;
 * the sweep reads the head again like any other stale head, and if no read ever answers the seat
 * gets `ci_await_expired` carrying the last error at expiry.
 *
 * ⭐ THE DELIVERED RUN IS OVERLAID. The list API can lag the webhook: the run whose completion was
 * just delivered may still read `in_progress` (or be absent) in the list read for that delivery. A
 * last run lost that way would strand the wake until the TTL, so the read for a delivery treats the
 * delivered run, keyed by id, as completed with the delivery's conclusion — unless the list already
 * shows a LATER attempt of it (`run_attempt`): a re-run keeps the run's id, and a delivery for an
 * earlier attempt (a late one, or one an operator redelivered by hand) must not finish the new one.
 *
 * ⚠ A RATE-LIMITED HEAD IS NOT READ BEFORE ITS RESET. A read that GitHub refused as rate limited and
 * that named when the quota returns records it as `retry_not_before`; until then no read of that
 * head is made — not by a delivery, a registration or the sweep — and nothing is settled.
 *
 * ⚠ `repo` IS STORED LOWER-CASE and every lookup lower-cases its input, so SQLite (case-sensitive
 * `=`) and MariaDB (case-insensitive collation) find the same rows; `repo_name` keeps the configured
 * subscription spelling, which is what the token resolver, the API and the events use.
 *
 * Emitted intents are STAGED to the inbox and then pushed live: the await is gone once claimed, so
 * the inbox line is what carries the wake to a seat whose channel was down at the moment.
 */
final class CiAwaitService
{
    public const SETTLED = 'ci_settled';

    public const EXPIRED = 'ci_await_expired';

    /** `read_skipped` when the calling seat's registration read budget is spent (card#11283). */
    public const SEAT_READ_LIMITED = 'seat_read_limited';

    /** The rate-limiter key prefix of a seat's registration reads; `bridge:check` probes the same store. */
    public const SEAT_READ_LIMITER_PREFIX = 'ci-await-seat-reads:';

    /** Inside the webhook's after-response callback and a job pass, never a human's patience. */
    public const TIMEOUT_SECONDS = 8;

    /**
     * How long past `expires_at` an await whose event keeps failing to reach its seat's inbox is
     * kept before it is dropped, logged as an error. Without it one seat's broken inbox would hold
     * its rows, and the expiry pass's work on them, forever.
     */
    public const EMIT_GIVE_UP_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly HandlerRegistry $handlers,
        private readonly IntentLog $intents,
    ) {}

    /** The stored lookup key of a repo: lower-case, as GitHub compares repo names. */
    public static function key(string $repo): string
    {
        return strtolower($repo);
    }

    /**
     * The configured spelling of `$repo` among this install's GitHub subscriptions (compared
     * case-insensitively, as GitHub compares repo names), or null when no agent here subscribes to
     * it — this install receives no GitHub events for that repo, so nothing would ever settle it.
     */
    public static function receivedRepo(string $repo): ?string
    {
        foreach ((new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs() as $cfg) {
            foreach ($cfg->subscriptions as $sub) {
                if ($sub->provider === 'github' && self::key($sub->scopeId) === self::key($repo)) {
                    return $sub->scopeId;
                }
            }
        }

        return null;
    }

    /**
     * Whether this install holds a stored `workflow_run` delivery from `$repoName` (the configured
     * spelling, which is the delivered one). TRUE is evidence the repo's webhook sends workflow runs
     * here; FALSE is not evidence it does not — retention may have pruned them, or no run has
     * completed since the webhook was added.
     */
    public static function hasRecordedWorkflowRun(string $repoName): bool
    {
        return WebhookEvent::query()
            ->where('provider', 'github')
            ->where('scope_id', $repoName)
            ->where('event_type', 'like', 'workflow_run.%')
            ->exists();
    }

    /**
     * How many OTHER heads `$agent` is awaiting, and whether it already awaits this one — what the
     * per-seat cap is judged on. A refresh of an existing await is never capped.
     *
     * @return array{others: int, this_head: bool}
     */
    public function seatLoad(string $agent, string $repoName, string $headSha): array
    {
        $mine = CiAwait::query()->where('agent', $agent);
        $thisHead = $mine->clone()->where('repo', self::key($repoName))->where('head_sha', $headSha)->exists();

        return ['others' => $mine->clone()->count() - ($thisHead ? 1 : 0), 'this_head' => $thisHead];
    }

    /**
     * Store (or refresh) `$agent`'s await on the head and make sure the sweep exists. A database
     * failure propagates: until this returns, nothing is stored and the caller may say so.
     *
     * @return bool whether an existing await was refreshed
     */
    public function store(string $agent, string $repoName, string $headSha, ?int $pr, int $ttlSeconds): bool
    {
        $refreshed = $this->upsert($agent, $repoName, $headSha, $pr, Carbon::now()->addSeconds($ttlSeconds));
        $this->declareSweep();

        return $refreshed;
    }

    /**
     * Read the head once for a just-stored await, so CI that finished before the seat registered
     * settles it now. NEVER THROWS: the await is already stored, so a failure here — the read, the
     * claim, the database — is reported as `unmeasured`, never as "nothing was stored".
     *
     * A head whose read ANSWERED within `$cooldownSeconds` is not read again: the answer is
     * `waiting` with `read_skipped: 'cooldown'`. That is a cost knob, nothing more — the cooldown
     * says the head was read recently, not that this await was in that read, and the sweep reads
     * the head like any other ({@see sweepUnsettled()}). A head rate limited until a known instant
     * is not read either: `waiting`, `read_skipped: 'rate_limited'`, with `retry_not_before`. A seat
     * past its own registration read budget (`$seatReadsPerHour`, card#11283) is not read either:
     * `waiting`, `read_skipped: 'seat_read_limited'`, with `retry_not_before` naming when the budget
     * frees (null when the limiter could not be read). A read
     * this call made that GitHub rate limited answers `unmeasured` with its `retry_not_before`. An
     * await that is gone by the time the answer is built was claimed — by this call's read or a
     * concurrent one — so it answers `settled`.
     *
     * @return array{state: string, pr: ?int, expires_at: ?string, runs_total: ?int, runs_completed: ?int, read_error: ?string, read_skipped: ?string, retry_not_before: ?string}
     */
    public function evaluateRegistration(string $agent, string $repoName, string $headSha, ?int $pr, int $cooldownSeconds, int $seatReadsPerHour): array
    {
        $key = self::key($repoName);
        $read = self::noRead();
        $skipped = null;
        $seatRetryAt = null;
        try {
            $cooling = $cooldownSeconds > 0 && CiAwait::query()->where('repo', $key)->where('head_sha', $headSha)
                ->whereNull('last_error')->where('last_read_at', '>=', Carbon::now()->subSeconds($cooldownSeconds))->exists();
            if ($cooling) {
                $skipped = 'cooldown';
            } elseif (! self::seatMayRead($agent, $seatReadsPerHour, $seatRetryAt)) {
                // card#11283: the await is stored; only THIS read is skipped. The instant goes in
                // the answer and never in the `retry_not_before` column, which is the head's
                // GitHub rate-limit record and would stop delivery and sweep reads too.
                $skipped = self::SEAT_READ_LIMITED;
            } else {
                $read = $this->evaluate($key, $headSha, null);
                $skipped = ! $read['read'] && $read['retry_not_before'] !== null ? 'rate_limited' : null;
            }
            $mine = CiAwait::query()->where('agent', $agent)->where('repo', $key)->where('head_sha', $headSha)->first();
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: the await is stored, but evaluating it at registration failed — the ci_await sweep reads its head again', [
                'agent' => $agent, 'repo' => $repoName, 'head_sha' => $headSha,
            ] + RedactedErrorText::logContext($e));

            return ['state' => 'unmeasured', 'pr' => $pr, 'expires_at' => null, 'runs_total' => null, 'runs_completed' => null,
                'read_error' => 'the await is stored, but evaluating it failed: '.RedactedErrorText::of($e), 'read_skipped' => null, 'retry_not_before' => null];
        }

        $emitFailed = $mine !== null && in_array($mine->id, $read['emit_failed'], true);
        $state = match (true) {
            $mine === null => 'settled',
            $emitFailed, $read['error'] !== null => 'unmeasured',
            default => 'waiting',
        };

        return [
            'state' => $state,
            'pr' => $mine === null ? $pr : $mine->pr,
            'expires_at' => $mine === null ? null : self::instant($mine->expires_at),
            'runs_total' => $read['runs'] === null ? null : count($read['runs']),
            'runs_completed' => $read['runs'] === null ? null : count(array_filter($read['runs'], static fn (array $r): bool => $r['status'] === 'completed')),
            'read_error' => $emitFailed
                ? 'the await is stored and every run is terminal, but ci_settled could not be written to your inbox — the await is kept and the ci_await sweep emits it again'
                : $read['error'],
            'read_skipped' => $skipped,
            'retry_not_before' => $seatRetryAt !== null ? self::instant($seatRetryAt) : ($read['retry_not_before'] === null ? null : self::instant($read['retry_not_before'])),
        ];
    }

    /**
     * Whether `$agent`'s registration may read GitHub now, under its per-seat budget of
     * `$perHour` reads (card#11283), counting this read when it may. When it may not,
     * `$retryAt` is when the budget frees — or stays null when the limiter itself failed, which
     * is logged and also skips the read: an unmeasured budget is not read as an unbounded one.
     */
    private static function seatMayRead(string $agent, int $perHour, ?Carbon &$retryAt): bool
    {
        $limiterKey = self::SEAT_READ_LIMITER_PREFIX.$agent;
        try {
            if (RateLimiter::tooManyAttempts($limiterKey, $perHour)) {
                $retryAt = Carbon::now()->addSeconds(RateLimiter::availableIn($limiterKey));

                return false;
            }
            RateLimiter::hit($limiterKey, 3600);

            return true;
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: the per-seat read limiter could not be read, so this registration skips its own runs read — the sweep and a workflow_run delivery still settle the await', [
                'agent' => $agent,
            ] + RedactedErrorText::logContext($e));

            return false;
        }
    }

    /** Remove `$agent`'s own await on the head. Returns whether there was one. */
    public function cancel(string $agent, string $repo, string $headSha): bool
    {
        return CiAwait::query()
            ->where('agent', $agent)
            ->where('repo', self::key($repo))
            ->where('head_sha', $headSha)
            ->delete() > 0;
    }

    /**
     * A `workflow_run.completed` arrived for `$repo` at `$headSha`, delivering `$deliveredRun`
     * (null when the payload named no readable run id). Never throws: it runs after the response,
     * where nothing would report a throw.
     *
     * @param  ?array{id: int, workflow: string, conclusion: ?string, html_url: string, run_attempt: ?int}  $deliveredRun
     */
    public function onWorkflowRunCompleted(string $repo, string $headSha, ?array $deliveredRun = null): void
    {
        try {
            $this->evaluate(self::key($repo), $headSha, $deliveredRun);
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: evaluating a completed workflow run failed — any await on this head is kept, and the ci_await sweep reads it again', [
                'repo' => $repo, 'head_sha' => $headSha,
            ] + RedactedErrorText::logContext($e));
        }
    }

    /**
     * Emit `ci_await_expired` for up to `$limit` awaits past their expiry, then drop the awaits whose
     * `ci_await_expired` could NOT be written on this attempt and that are
     * {@see EMIT_GIVE_UP_AFTER_SECONDS} past their expiry. An await is never dropped without an
     * attempt in the same pass: an inbox that was fixed since the last failure takes the line.
     *
     * An await whose emit failed within the last `$retryAfterSeconds` is not tried again on this
     * pass, and one that has failed before is tried only after every never-failed due await — so a
     * seat whose inbox cannot be written, however many awaits it holds, never fills the pass ahead
     * of anyone else's expiry, whatever the jitter between passes. One whose settle failed before
     * it expired is tried as an expiry like any other.
     *
     * @return array{emitted: int, failed: int, dropped: int}
     */
    public function expireDue(int $limit, int $retryAfterSeconds): array
    {
        $now = Carbon::now();
        $due = CiAwait::query()->where('expires_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('emit_failed_at')->orWhere('emit_failed_at', '<', $now->copy()->subSeconds($retryAfterSeconds)))
            ->orderByRaw('emit_failed_at is not null')->orderBy('expires_at')->orderBy('id')
            ->limit($limit)->get();
        $emitted = 0;
        $failed = 0;
        $dropped = 0;
        $giveUpBefore = $now->copy()->subSeconds(self::EMIT_GIVE_UP_AFTER_SECONDS);
        foreach ($due as $await) {
            $result = $this->claimAndEmit($await, self::EXPIRED, self::expiredPayload($await), self::expiredSummary($await));
            $emitted += $result === EmitResult::Emitted ? 1 : 0;
            if ($result !== EmitResult::Failed) {
                continue;
            }
            $failed++;
            if ($await->expires_at->lessThanOrEqualTo($giveUpBefore) && CiAwait::query()->whereKey($await->id)->delete() === 1) {
                $dropped++;
                Log::error('bridge ci_await: gave up on an await whose ci_await_expired could not be written to its seat\'s inbox — it is deleted UNDELIVERED; fix that inbox (bridge:check names it)', [
                    'agent' => $await->agent, 'repo' => $await->repo_name, 'head_sha' => $await->head_sha,
                    'expires_at' => self::instant($await->expires_at),
                ]);
            }
        }

        return ['emitted' => $emitted, 'failed' => $failed, 'dropped' => $dropped];
    }

    /**
     * Read up to `$limit` unexpired heads whose oldest READ is at least `$staleSeconds` old, or none
     * of whose awaits was ever read (a head with one unread await beside read ones counts by its
     * oldest read: the aggregate ignores the unread row), those never read first and then oldest
     * read first; a head any of whose awaits is rate limited past now is left out. Each read is
     * {@see evaluate()}, so an all-terminal head settles every await on it. This is what makes every
     * await settle without depending on any event.
     *
     * ⚑ ONE HEAD THAT THROWS DOES NOT STARVE THE REST. A head whose read raises anything the read
     * itself does not already catch is logged, its awaits are stamped as read now with the error
     * (so it takes its turn behind the others instead of heading the next pass), and the pass goes
     * on — the way {@see claimAndEmit()} isolates one await.
     *
     * @return array{read: int, failed: int} the heads actually read, and the heads whose read threw
     */
    public function sweepUnsettled(int $limit, int $staleSeconds): array
    {
        $now = Carbon::now();
        $heads = CiAwait::query()
            ->where('expires_at', '>', $now)
            ->select('repo', 'head_sha')
            ->selectRaw('min(last_read_at) as oldest_read')
            ->groupBy('repo', 'head_sha')
            ->havingRaw('(max(retry_not_before) is null or max(retry_not_before) <= ?)', [$now])
            ->havingRaw('(min(last_read_at) is null or min(last_read_at) <= ?)', [$now->copy()->subSeconds($staleSeconds)])
            ->orderByRaw('min(last_read_at) is not null')->orderBy('oldest_read')->orderBy('repo')->orderBy('head_sha')
            ->limit($limit)
            ->get();
        $read = 0;
        $failed = 0;
        foreach ($heads as $head) {
            try {
                $read += $this->evaluate((string) $head->repo, (string) $head->head_sha, null)['read'] ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('bridge ci_await: the sweep\'s read of a head threw — it is stamped as read and the pass goes on to the next head', [
                    'repo' => $head->repo, 'head_sha' => $head->head_sha,
                ] + RedactedErrorText::logContext($e));
                try {
                    CiAwait::query()->where('repo', $head->repo)->where('head_sha', $head->head_sha)
                        ->update(['last_read_at' => Carbon::now(), 'last_error' => mb_substr(RedactedErrorText::of($e), 0, 1000)]);
                } catch (Throwable $stamp) {
                    Log::warning('bridge ci_await: the failed read of a head could not be stamped, so it stays first in line', [
                        'repo' => $head->repo, 'head_sha' => $head->head_sha,
                    ] + RedactedErrorText::logContext($stamp));
                }
            }
        }

        return ['read' => $read, 'failed' => $failed];
    }

    /**
     * Read the head once and settle every await on it when every run is terminal.
     *
     * ⚠ The awaits are loaded BEFORE the read, deliberately: the read is slow, and a concurrent
     * evaluation of the same head may claim them meanwhile — {@see claimAndEmit()} is what makes
     * that safe, not the order. An await stored while the read runs is NOT in that set and is not
     * settled by it; the sweep reads its head later ({@see sweepUnsettled()}).
     *
     * ⚠ A head any of whose awaits carries a `retry_not_before` still in the future is not read
     * (`read: false`): every await on the head is given that instant and the limiting error.
     *
     * @param  ?array{id: int, workflow: string, conclusion: ?string, html_url: string, run_attempt: ?int}  $deliveredRun
     * @return array{runs: ?list<array{id: int, workflow: string, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>, error: ?string, all_terminal: bool, retry_not_before: ?Carbon, read: bool, emit_failed: list<int>}
     */
    private function evaluate(string $key, string $headSha, ?array $deliveredRun): array
    {
        $awaits = CiAwait::query()->where('repo', $key)->where('head_sha', $headSha)->orderBy('id')->get();
        if ($awaits->isEmpty()) {
            return self::noRead();
        }

        $repoName = $awaits->first()->repo_name;
        $measuredAt = Carbon::now();
        $ids = $awaits->pluck('id')->all();

        $limiting = $awaits->filter(static fn (CiAwait $a): bool => $a->retry_not_before !== null && $a->retry_not_before->isAfter($measuredAt))
            ->sortByDesc(static fn (CiAwait $a): int => $a->retry_not_before->getTimestamp())->first();
        if ($limiting !== null) {
            CiAwait::query()->whereKey($ids)->update(['retry_not_before' => $limiting->retry_not_before, 'last_error' => $limiting->last_error]);
            Log::info('bridge ci_await: the head is rate limited, so it was not read — the sweep reads it after the reset', [
                'repo' => $repoName, 'head_sha' => $headSha, 'retry_not_before' => self::instant($limiting->retry_not_before),
            ]);

            return ['retry_not_before' => Carbon::instance($limiting->retry_not_before)] + self::noRead();
        }
        try {
            $runs = $this->readRuns($repoName, $headSha);
        } catch (CiRunsReadException $e) {
            // The column holds 1000 characters; a longer error would fail the write that records it.
            $error = mb_substr($e->getMessage(), 0, 1000);
            CiAwait::query()->whereKey($ids)->update(['last_read_at' => $measuredAt, 'last_error' => $error, 'retry_not_before' => $e->retryNotBefore]);
            Log::warning('bridge ci_await: the workflow-run read failed, so nothing was emitted — the await is kept, the ci_await sweep reads the head again, and it expires with this error if no read answers', [
                'repo' => $repoName, 'head_sha' => $headSha, 'awaits' => count($ids), 'error' => $error,
            ]);

            return ['error' => $error, 'retry_not_before' => $e->retryNotBefore === null ? null : Carbon::instance($e->retryNotBefore), 'read' => true] + self::noRead();
        }
        CiAwait::query()->whereKey($ids)->update(['last_read_at' => $measuredAt, 'last_error' => null, 'retry_not_before' => null]);

        if ($deliveredRun !== null) {
            $runs = self::overlay($runs, $deliveredRun);
        }

        // No run at all is NOT settled: CI that has not been queued yet looks exactly like this.
        $allTerminal = $runs !== [] && array_filter($runs, static fn (array $r): bool => $r['status'] !== 'completed') === [];
        $emitFailed = [];
        if ($allTerminal) {
            foreach ($awaits as $await) {
                if ($this->claimAndEmit($await, self::SETTLED, self::settledPayload($await, $runs, $measuredAt), self::settledSummary($await, count($runs))) === EmitResult::Failed) {
                    $emitFailed[] = $await->id;
                }
            }
        }

        return ['runs' => $runs, 'error' => null, 'all_terminal' => $allTerminal, 'retry_not_before' => null, 'read' => true, 'emit_failed' => $emitFailed];
    }

    /** @return array{runs: null, error: null, all_terminal: false, retry_not_before: null, read: false, emit_failed: list<int>} */
    private static function noRead(): array
    {
        return ['runs' => null, 'error' => null, 'all_terminal' => false, 'retry_not_before' => null, 'read' => false, 'emit_failed' => []];
    }

    /**
     * The list as it stands once the delivered run is known to be completed: its row is marked
     * completed (taking the delivery's conclusion where the list has none), or appended when the
     * list does not carry it yet. A listed row whose `run_attempt` is LATER than the delivered one,
     * or where either attempt is unknown, is left as listed: the delivery may be for an earlier
     * attempt of a run that has since been re-run.
     *
     * @param  list<array{id: int, workflow: string, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>  $runs
     * @param  array{id: int, workflow: string, conclusion: ?string, html_url: string, run_attempt: ?int}  $delivered
     * @return list<array{id: int, workflow: string, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>
     */
    private static function overlay(array $runs, array $delivered): array
    {
        foreach ($runs as $i => $run) {
            if ($run['id'] === $delivered['id']) {
                if ($run['run_attempt'] === null || $delivered['run_attempt'] === null || $run['run_attempt'] > $delivered['run_attempt']) {
                    return $runs;
                }
                $runs[$i]['status'] = 'completed';
                $runs[$i]['conclusion'] = $delivered['conclusion'] ?? $run['conclusion'];

                return $runs;
            }
        }
        $runs[] = $delivered + ['status' => 'completed', 'event' => ''];

        return $runs;
    }

    /**
     * Claim the await by deleting it, and emit only if this call's delete removed it. The inbox
     * line is written inside the same transaction, so a staging failure rolls the claim back and
     * the await survives; the live push runs after the commit and is best-effort.
     *
     * ⭐ NEVER THROWS. A failure to claim or stage is THIS await's: it is logged naming the seat,
     * recorded on this row only (`emit_failed_at`) and answered {@see EmitResult::Failed}, so the
     * caller's loop goes on to the next await — one seat's unwritable inbox holds back no one
     * else's. The row is emitted again on a later pass: the sweep reads its head again, and expiry
     * retries it ({@see expireDue()}).
     *
     * @param  array<string, mixed>  $payload
     */
    private function claimAndEmit(CiAwait $await, string $kind, array $payload, string $summary): EmitResult
    {
        $intent = new Intent(
            kind: $kind,
            subjectId: "ci:{$await->repo_name}@{$await->head_sha}",
            provider: 'bridge',
            actor: new Actor(id: null),
            summary: $summary,
            payload: $payload,
        );

        try {
            $claimed = DB::transaction(function () use ($await, $intent): bool {
                if (CiAwait::query()->whereKey($await->id)->delete() !== 1) {
                    return false;
                }
                $this->intents->stageAuthored($await->agent, "ci_await:{$await->uuid}", microtime(true), $intent);

                return true;
            });
        } catch (Throwable $e) {
            Log::warning("bridge ci_await: {$kind} could not be written to the seat's inbox — the await is kept and emitted again on a later pass", [
                'agent' => $await->agent, 'repo' => $await->repo_name, 'head_sha' => $await->head_sha,
            ] + RedactedErrorText::logContext($e));
            try {
                CiAwait::query()->whereKey($await->id)->update(['emit_failed_at' => Carbon::now()]);
            } catch (Throwable $mark) {
                Log::warning('bridge ci_await: the failed emit could not be recorded on its await either', [
                    'agent' => $await->agent, 'repo' => $await->repo_name, 'head_sha' => $await->head_sha,
                ] + RedactedErrorText::logContext($mark));
            }

            return EmitResult::Failed;
        }
        if (! $claimed) {
            return EmitResult::ClaimedElsewhere;
        }

        Log::info("bridge ci_await: {$kind} emitted", ['agent' => $await->agent, 'repo' => $await->repo_name, 'head_sha' => $await->head_sha]);
        try {
            (new AuthoredIntentPush($this->handlers))->send($intent, $await->agent);
        } catch (Throwable $e) {
            Log::warning("bridge ci_await: the live push of {$kind} failed — the intent is staged in the agent's inbox, which bridge:inbox surfaces", [
                'agent' => $await->agent, 'repo' => $await->repo_name, 'head_sha' => $await->head_sha,
            ] + RedactedErrorText::logContext($e));
        }

        return EmitResult::Emitted;
    }

    /**
     * @return list<array{id: int, workflow: string, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>
     *
     * @throws CiRunsReadException naming why no complete run list was read
     */
    private function readRuns(string $repoName, string $headSha): array
    {
        $token = (new GitHubTokenResolver)->resolveFor($repoName);
        if (! $token->ok()) {
            throw new CiRunsReadException('no GitHub read token: '.$token->problem);
        }

        try {
            return (new GitHubReadClient((string) $token->token, self::TIMEOUT_SECONDS))->workflowRunsForHead($repoName, $headSha);
        } catch (RequestException $e) {
            $status = $e->response->status();
            // A primary limit says X-RateLimit-Remaining: 0; a secondary limit is a 403 that may
            // leave Remaining above zero but carries Retry-After.
            $limited = $status === 429 || ($status === 403 && ($e->response->header('X-RateLimit-Remaining') === '0' || $e->response->header('Retry-After') !== ''));
            $resetAt = $limited ? self::rateLimitReset($e) : null;

            throw new CiRunsReadException(
                "GitHub answered HTTP {$status} to the workflow-run read".($limited ? ' (rate limited'.($resetAt === null ? '' : ' until '.self::instant($resetAt)).')' : ''),
                $resetAt,
            );
        } catch (ConnectionException|UnexpectedValueException $e) {
            throw new CiRunsReadException(RedactedErrorText::of($e));
        }
    }

    /**
     * When a rate-limited read says the quota comes back: `Retry-After` when present (what GitHub
     * names for a secondary limit, and the more specific of the two — a secondary-limit 403 can
     * also carry a primary `X-RateLimit-Reset` an hour out), read by
     * {@see RefusalContext::retryAfterSeconds()} so an HTTP-date is understood too; else
     * `X-RateLimit-Reset` (epoch seconds), but only when `X-RateLimit-Remaining` is `0` — a reset
     * beside a quota that is not spent says nothing about this refusal. Null when neither applies.
     */
    private static function rateLimitReset(RequestException $e): ?Carbon
    {
        $after = RefusalContext::retryAfterSeconds($e);
        if ($after !== null) {
            return Carbon::now()->addSeconds($after);
        }
        $reset = $e->response->header('X-RateLimit-Reset');
        if ($e->response->header('X-RateLimit-Remaining') === '0' && preg_match('/\A[0-9]{1,12}\z/', $reset) === 1) {
            return Carbon::createFromTimestamp((int) $reset, 'UTC');
        }

        return null;
    }

    private function upsert(string $agent, string $repoName, string $headSha, ?int $pr, Carbon $expiresAt): bool
    {
        $mine = CiAwait::query()->where('agent', $agent)->where('repo', self::key($repoName))->where('head_sha', $headSha);
        // A re-registration WITHOUT a pr keeps the one already recorded; one with a pr replaces it.
        $refresh = ['expires_at' => $expiresAt] + ($pr === null ? [] : ['pr' => $pr]);
        if ($mine->clone()->update($refresh) > 0) {
            return true;
        }
        try {
            CiAwait::query()->create(['agent' => $agent, 'repo' => self::key($repoName), 'repo_name' => $repoName, 'head_sha' => $headSha, 'pr' => $pr, 'expires_at' => $expiresAt]);

            return false;
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration of the same head by the same seat created it first.
            $mine->clone()->update($refresh);

            return true;
        }
    }

    /**
     * Make sure the sweep that emits `ci_await_expired` and reads unsettled heads has an instance
     * to run. Never fails the registration: the await is stored either way, and `bridge:check`'s
     * `ci_await.awaits` leg names a sweep that cannot run.
     */
    private function declareSweep(): void
    {
        try {
            app(JobRegistry::class)->declareIfAbsent(CiAwaitSweepJob::spec());
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: could not declare the ci_await sweep job — awaits will not expire, and no head is read on a clock, until it exists', [
                'error' => RedactedErrorText::of($e),
                'remedy' => 'php artisan bridge:jobs add '.CiAwaitSweepJob::INSTANCE.' --handler='.CiAwaitSweepJob::NAME.' (docs/periodic-jobs.md)',
            ]);
        }
    }

    /**
     * @param  list<array{id: int, workflow: string, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>  $runs
     * @return array<string, mixed>
     */
    private static function settledPayload(CiAwait $await, array $runs, Carbon $measuredAt): array
    {
        return [
            'repo' => $await->repo_name,
            'head_sha' => $await->head_sha,
            'pr' => $await->pr,
            'runs' => array_map(static fn (array $r): array => ['workflow' => $r['workflow'], 'conclusion' => $r['conclusion'], 'html_url' => $r['html_url']], $runs),
            'all_terminal' => true,
            'measured_at' => self::instant($measuredAt),
        ];
    }

    private static function settledSummary(CiAwait $await, int $runs): string
    {
        return "CI settled on {$await->repo_name}@".substr($await->head_sha, 0, 12).($await->pr === null ? '' : " (PR #{$await->pr})")
            .": all {$runs} workflow run(s) are terminal. This is not a verdict — run ci-read once on this head for green/red.";
    }

    /** @return array<string, mixed> */
    private static function expiredPayload(CiAwait $await): array
    {
        return [
            'repo' => $await->repo_name,
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
        return "Stopped waiting for CI on {$await->repo_name}@".substr($await->head_sha, 0, 12).($await->pr === null ? '' : " (PR #{$await->pr})")
            .' — the await expired before every workflow run was seen terminal'
            .($await->last_error === null ? '' : " (last read failed: {$await->last_error})")
            .'. Run ci-read on this head, or re-register with ci_await.';
    }

    private static function instant(DateTimeInterface $at): string
    {
        return Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
