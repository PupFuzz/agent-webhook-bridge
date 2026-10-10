<?php

namespace App\Bridge\CiAwait;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Exceptions\CiRunsReadException;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Scheduling\Handlers\CiAwaitSweepJob;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AuthoredIntentPush;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\PastedSecretShape;
use App\Bridge\Support\PathVisibility;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\GitHubReadClient;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\TokenFileFault;
use App\Bridge\Writeback\TokenResolution;
use App\Bridge\Writeback\TokenSource;
use App\Models\CiAwait;
use App\Models\WebhookEvent;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * ⭐ THE GUARANTEE. One terminal event per await (`ci_settled`, `ci_await_unreadable` or
 * `ci_await_expired`), written to the
 * seat's inbox at least once and idempotent by its line id, then pushed live once — the push carries no
 * line id and is unconfirmed (DL-370). The id's form and why are defined once, in `docs/board-tools.md`
 * § `ci_await` and `ci_await_cancel`.
 * `ci_settled` follows CI finishing by at most the time until the sweep next reads the head
 * ({@see sweepUnsettled()}: a head none of whose awaits was read, or whose oldest read is one sweep interval old, up to
 * the per-pass cap), PROVIDED THE SWEEP RUNS — a pass needs a webhook or `bridge:tick`.
 *
 * ⭐ SETTLED, AND HOW IT ENDED, ARE {@see HeadRuns}' ANSWERS — the same predicate the per-head
 * aggregate applies to tracked runs (card#11667): the latest run per workflow decides, and every
 * deciding run must be `completed`. The payload carries a RUN-LEVEL `runs_verdict`; the
 * authoritative verdict stays `ci-read`'s (jobs and the base branch's required contexts), which
 * the summary names.
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
 * The same claim serves the registration-time read and the sweep, so an await is settled, ended as
 * unreadable, or expired — exactly one. An inbox line that cannot be written rolls the claim back and marks THAT row
 * only; the other awaits on the head are still claimed, and the failed one is emitted on a later pass.
 *
 * ⚠ A READ THAT FAILS EMITS NOTHING — unless it says the repo cannot be read. The await is kept
 * with the error recorded and logged by name; the sweep reads the head again like any other stale
 * head, and if no read ever answers the seat gets `ci_await_expired` carrying the last error at
 * expiry. ⛔ A failure that reading again cannot get past (card#11600: no read token for any reader,
 * or GitHub answered 404 — {@see githubRead()}) ends every await on the head at once with
 * `ci_await_unreadable`; a 401 or a non-rate-limited 403 does the same only when a read at least
 * {@see CONFIRM_AFTER_SECONDS} later answers the same status ({@see evaluate()}). A registration
 * whose own read ends them is refused as `repo_unreadable`: a seat must not wait six hours on a repo this install's token cannot see.
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
 *
 * ⭐ OVERDUE IS NOT TERMINAL (card#11674). Each await carries a deadline — the repo's normal CI
 * time ({@see OverdueDeadline}) or the seat's own — and an await still stored past it gets ONE
 * `ci_await_overdue` ({@see emitOverdue()}), claimed on `overdue_sent_at` rather than by deleting
 * the row: the await stays, and its terminal event still follows.
 */
final class CiAwaitService
{
    public const SETTLED = 'ci_settled';

    public const EXPIRED = 'ci_await_expired';

    /**
     * Sent ONCE per await when it passes its overdue deadline still unsettled (card#11674). NOT
     * terminal: the await stays stored, and `ci_settled`, `ci_await_unreadable` or
     * `ci_await_expired` still follows. {@see emitOverdue()}.
     */
    public const OVERDUE = 'ci_await_overdue';

    /** How many due awaits a pass may LOOK at per send it may make: skipped ones are not sends. */
    private const OVERDUE_SCAN_FACTOR = 10;

    /** Sent instead of waiting out the expiry when GitHub will not let this install read the repo (card#11600). */
    public const UNREADABLE = 'ci_await_unreadable';

    /**
     * How long a 401 or a non-rate-limited 403 waits for the read that confirms it (card#11600). One
     * minute is what GitHub asks of a secondary rate limit that names no reset.
     */
    public const CONFIRM_AFTER_SECONDS = 60;

    /** `read_skipped` when the head is waiting for the read that confirms a 401 or 403 (card#11600). */
    public const UNREADABLE_UNCONFIRMED = 'unreadable_unconfirmed';

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
        foreach (self::receivedRepos() as $received) {
            if (self::key($received) === self::key($repo)) {
                return $received;
            }
        }

        return null;
    }

    /**
     * Every GitHub repo this install receives events for — the repos `ci_await` accepts — once
     * each, in the first configured spelling. `$configs` are the agent configs to read (null: load
     * them from the config dir, which throws {@see ConfigException} on one that will not load).
     *
     * @param  ?list<AgentConfig>  $configs
     * @return list<string>
     */
    public static function receivedRepos(?array $configs = null): array
    {
        $repos = [];
        foreach ($configs ?? (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs() as $cfg) {
            foreach ($cfg->subscriptions as $sub) {
                if ($sub->provider === 'github') {
                    $repos[self::key($sub->scopeId)] ??= $sub->scopeId;
                }
            }
        }

        return array_values($repos);
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
     * The overdue deadline (card#11674) is `$overdue` from now on a NEW await. A refresh keeps the
     * deadline it has unless `$overdue` is the caller's own ({@see OverdueDeadline::override()}) or
     * it has none (a row stored before the column), and never moves it once its
     * `ci_await_overdue` was sent: one per await.
     *
     * @return bool whether an existing await was refreshed
     */
    public function store(string $agent, string $repoName, string $headSha, ?int $pr, int $ttlSeconds, OverdueDeadline $overdue): bool
    {
        $refreshed = $this->upsert($agent, $repoName, $headSha, $pr, Carbon::now()->addSeconds($ttlSeconds), $overdue);
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
     * ⛔ A READ THAT SAYS THE REPO CANNOT BE READ (card#11600) answers `unreadable`, carrying the
     * failure, and the await is GONE: this seat's own is removed with no event, because the caller
     * refuses the registration (`repo_unreadable`); any other seat's await on the head gets
     * `ci_await_unreadable`. A read this call skipped (cooldown, rate limit, seat budget) changes
     * nothing — the await waits, and the sweep's read of the head ends it that way instead.
     *
     * @return array{state: string, pr: ?int, expires_at: ?string, overdue_at: ?string, overdue_basis: ?string, overdue_sent_at: ?string, runs_total: ?int, runs_completed: ?int, read_error: ?string, read_skipped: ?string, retry_not_before: ?string, unreadable: ?CiRunsReadException}
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
                $mineId = CiAwait::query()->where('agent', $agent)->where('repo', $key)->where('head_sha', $headSha)->value('id');
                $read = $this->evaluate($key, $headSha, null, $mineId === null ? null : (int) $mineId);
                $skipped = ! $read['read'] && $read['retry_not_before'] !== null ? ($read['unconfirmed'] ? self::UNREADABLE_UNCONFIRMED : 'rate_limited') : null;
            }
            $mine = CiAwait::query()->where('agent', $agent)->where('repo', $key)->where('head_sha', $headSha)->first();
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: the await is stored, but evaluating it at registration failed — the ci_await sweep reads its head again', [
                'agent' => $agent, 'repo' => $repoName, 'head_sha' => $headSha,
            ] + RedactedErrorText::logContext($e));

            return ['state' => 'unmeasured', 'pr' => $pr, 'expires_at' => null, 'overdue_at' => null, 'overdue_basis' => null, 'overdue_sent_at' => null, 'runs_total' => null, 'runs_completed' => null,
                'read_error' => 'the await is stored, but evaluating it failed: '.RedactedErrorText::of($e), 'read_skipped' => null, 'retry_not_before' => null, 'unreadable' => null];
        }
        if ($read['unreadable'] !== null) {
            return ['state' => 'unreadable', 'pr' => $pr, 'expires_at' => null, 'overdue_at' => null, 'overdue_basis' => null, 'overdue_sent_at' => null, 'runs_total' => null, 'runs_completed' => null,
                'read_error' => $read['error'], 'read_skipped' => null, 'retry_not_before' => null, 'unreadable' => $read['unreadable']];
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
            'overdue_at' => $mine?->overdue_at === null ? null : self::instant($mine->overdue_at),
            'overdue_basis' => $mine?->overdue_basis,
            'overdue_sent_at' => $mine?->overdue_sent_at === null ? null : self::instant($mine->overdue_sent_at),
            'runs_total' => $read['runs'] === null ? null : count($read['runs']),
            'runs_completed' => $read['runs'] === null ? null : count(array_filter($read['runs'], static fn (array $r): bool => $r['status'] === 'completed')),
            'read_error' => $emitFailed
                ? 'the await is stored and every run is terminal, but ci_settled could not be written to your inbox — the await is kept and the ci_await sweep emits it again'
                : $read['error'],
            'read_skipped' => $skipped,
            'retry_not_before' => $seatRetryAt !== null ? self::instant($seatRetryAt) : ($read['retry_not_before'] === null ? null : self::instant($read['retry_not_before'])),
            'unreadable' => null,
        ];
    }

    /**
     * Whether `$agent`'s registration may read GitHub now, under its per-agent budget of
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
            Log::warning('bridge ci_await: the per-agent read limiter could not be read, so this registration skips its own runs read — the sweep and a workflow_run delivery still settle the await', [
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
     * @param  ?array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, conclusion: ?string, html_url: string, run_attempt: ?int}  $deliveredRun
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
     * Send ONE `ci_await_overdue` (card#11674) to each of up to `$limit` unexpired awaits past their
     * overdue deadline that were not sent one yet, deadline first. The await is KEPT: it still ends
     * in `ci_settled`, `ci_await_unreadable` or `ci_await_expired`.
     *
     * ⭐ ONE PER AWAIT, BY CLAIM. The send stamps `overdue_sent_at` with an UPDATE that matches only
     * a row where it is still null, in the transaction that stages the inbox line, and stages only
     * when that update changed the row — so two concurrent passes send one, and an await settled or
     * cancelled meanwhile (its row gone) sends none.
     *
     * An inbox that cannot be written rolls the stamp back and marks `emit_failed_at`, as any emit
     * does: that await is tried again after `$retryAfterSeconds`, and only after every never-failed
     * due one, so one seat's broken inbox never fills the pass ahead of anyone else's. A send that
     * reached the inbox clears the mark — that inbox takes lines again.
     *
     * The event carries what the bridge last saw: the await's own last runs read, and the runs the
     * head's `workflow_run` deliveries reported ({@see CiHeadRunTracker}) — no GitHub read is made.
     *
     * @return array{emitted: int, failed: int}
     */
    public function emitOverdue(int $limit, int $retryAfterSeconds): array
    {
        $now = Carbon::now();
        $due = CiAwait::query()->where('overdue_at', '<=', $now)->whereNull('overdue_sent_at')->where('expires_at', '>', $now)
            ->where(fn ($q) => $q->whereNull('emit_failed_at')->orWhere('emit_failed_at', '<', $now->copy()->subSeconds($retryAfterSeconds)))
            ->orderByRaw('emit_failed_at is not null')->orderBy('overdue_at')->orderBy('id')
            ->limit($limit * self::OVERDUE_SCAN_FACTOR)->get();
        $emitted = 0;
        $failed = 0;
        $attempted = 0;
        foreach ($due as $await) {
            $result = $this->claimOverdue($await, $now);
            $emitted += $result === EmitResult::Emitted ? 1 : 0;
            $failed += $result === EmitResult::Failed ? 1 : 0;
            // A skipped await (its head's current state was already sent) costs a read, not a send.
            $attempted += $result === EmitResult::ClaimedElsewhere ? 0 : 1;
            if ($attempted >= $limit) {
                break;
            }
        }

        return ['emitted' => $emitted, 'failed' => $failed];
    }

    /**
     * The fingerprint of the head's CURRENT settled state, from the runs its `workflow_run`
     * deliveries reported, when that state was already sent to `$await`'s seat (`ci_head_settlements`,
     * card#11667); null otherwise. The per-head aggregate leaves the await registered, so it can still
     * be overdue after the seat was told the head settled; the event would send it to check CI it has
     * just been told about. ⛔ The CURRENT state only: a head that settled once and was then re-run
     * or gained a late run has a new fingerprint, and a hung re-run is exactly what the event is for.
     *
     * @param  ?list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>  $runs  {@see CiHeadRunTracker::runsOf()}, null when unreadable
     */
    private static function sentSettledState(CiAwait $await, ?array $runs): ?string
    {
        if ($runs === null) {
            return null;
        }
        $head = HeadRuns::of($runs);
        if (! $head->settled()) {
            return null;
        }
        $fingerprint = $head->fingerprint();

        return DB::table(CiHeadSettlementLedger::TABLE)->where('agent', $await->agent)->where('repo', $await->repo)->where('head_sha', $await->head_sha)
            ->where('fingerprint', $fingerprint)->exists() ? $fingerprint : null;
    }

    /** {@see emitOverdue()}'s claim and send for one await. Never throws. */
    private function claimOverdue(CiAwait $await, Carbon $now): EmitResult
    {
        try {
            $runs = CiHeadRunTracker::runsOf($await->repo_name, $await->head_sha);
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: the head\'s tracked runs could not be read, so ci_await_overdue carries none', [
                'agent' => $await->agent, 'repo' => $await->repo_name, 'head_sha' => $await->head_sha,
            ] + RedactedErrorText::logContext($e));
            $runs = null;
        }
        if (self::sentSettledState($await, $runs) !== null) {
            return EmitResult::ClaimedElsewhere;
        }
        $intent = new Intent(
            kind: self::OVERDUE,
            subjectId: "ci:{$await->repo_name}@{$await->head_sha}",
            provider: 'bridge',
            actor: new Actor(id: null),
            summary: self::overdueSummary($await, $runs),
            payload: self::overduePayload($await, $runs),
        );

        try {
            $claimed = DB::transaction(function () use ($await, $intent, $now, $runs): bool {
                if (self::sentSettledState($await, $runs) !== null
                    || CiAwait::query()->whereKey($await->id)->whereNull('overdue_sent_at')->update(['overdue_sent_at' => $now, 'emit_failed_at' => null]) !== 1) {
                    return false;
                }
                $this->intents->stageAuthored($await->agent, self::OVERDUE.":{$await->uuid}", microtime(true), $intent);

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: ci_await_overdue could not be written to the seat\'s inbox — it is sent again on a later pass', [
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

        Log::info('bridge ci_await: ci_await_overdue emitted', ['agent' => $await->agent, 'repo' => $await->repo_name, 'head_sha' => $await->head_sha]);
        try {
            (new AuthoredIntentPush($this->handlers))->send($intent, $await->agent);
        } catch (Throwable $e) {
            Log::warning('bridge ci_await: the live push of ci_await_overdue failed — the intent is staged in the agent\'s inbox, which bridge:inbox surfaces', [
                'agent' => $await->agent, 'repo' => $await->repo_name, 'head_sha' => $await->head_sha,
            ] + RedactedErrorText::logContext($e));
        }

        return EmitResult::Emitted;
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
     * ⛔ A READ THAT SAYS THE REPO CANNOT BE READ ENDS EVERY AWAIT IT LOADED (card#11600): reading
     * again would answer the same, so each gets `ci_await_unreadable` now instead of
     * `ci_await_expired` hours later ({@see forgetUnreadable()}). A 404 or a missing token says so
     * at once; a 401 or a non-rate-limited 403 is recorded as `unconfirmed_status` with
     * `retry_not_before` {@see CONFIRM_AFTER_SECONDS} out, and says so only when a read made at
     * least that long after one recorded on a loaded await answers the same status. Any other
     * outcome between them clears the record. `$silentAwaitId` is the await of a
     * registration making this read: it is removed WITHOUT an event, because that seat is answered
     * by the `repo_unreadable` refusal instead.
     *
     * @param  ?array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, conclusion: ?string, html_url: string, run_attempt: ?int}  $deliveredRun
     * @return array{runs: ?list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>, error: ?string, all_terminal: bool, retry_not_before: ?Carbon, read: bool, emit_failed: list<int>, unreadable: ?CiRunsReadException, unconfirmed: bool}
     */
    private function evaluate(string $key, string $headSha, ?array $deliveredRun, ?int $silentAwaitId = null): array
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
            CiAwait::query()->whereKey($ids)->update(['retry_not_before' => $limiting->retry_not_before, 'last_error' => $limiting->last_error, 'unconfirmed_status' => $limiting->unconfirmed_status]);
            Log::info('bridge ci_await: the head is rate limited, so it was not read — the sweep reads it after the reset', [
                'repo' => $repoName, 'head_sha' => $headSha, 'retry_not_before' => self::instant($limiting->retry_not_before),
            ]);

            return ['retry_not_before' => Carbon::instance($limiting->retry_not_before), 'unconfirmed' => $limiting->unconfirmed_status !== null] + self::noRead();
        }
        try {
            $runs = $this->readRuns($repoName, $headSha);
        } catch (CiRunsReadException $e) {
            // The column holds 1000 characters; a longer error would fail the write that records it.
            $error = mb_substr($e->getMessage(), 0, 1000);
            $confirmed = $e->needsConfirmation && $awaits->contains(static fn (CiAwait $a): bool => $a->unconfirmed_status === $e->status
                && $a->last_read_at !== null && $a->last_read_at->lte($measuredAt->copy()->subSeconds(self::CONFIRM_AFTER_SECONDS)));
            CiAwait::query()->whereKey($ids)->update(['last_read_at' => $measuredAt, 'last_error' => $error, 'retry_not_before' => $e->retryNotBefore,
                'unconfirmed_status' => $e->needsConfirmation ? $e->status : null]);
            if ($e->repoUnreadable || $confirmed) {
                return ['error' => $error, 'read' => true, 'unreadable' => $e, 'emit_failed' => $this->forgetUnreadable($awaits, $e, $error, $silentAwaitId)] + self::noRead();
            }
            Log::warning('bridge ci_await: the workflow-run read failed, so nothing was emitted — the await is kept, the ci_await sweep reads the head again, and it expires with this error if no read answers', [
                'repo' => $repoName, 'head_sha' => $headSha, 'awaits' => count($ids), 'error' => $error,
            ]);

            return ['error' => $error, 'retry_not_before' => $e->retryNotBefore === null ? null : Carbon::instance($e->retryNotBefore), 'read' => true] + self::noRead();
        }
        CiAwait::query()->whereKey($ids)->update(['last_read_at' => $measuredAt, 'last_error' => null, 'retry_not_before' => null, 'unconfirmed_status' => null]);

        if ($deliveredRun !== null) {
            $runs = self::overlay($runs, $deliveredRun);
        }

        // Settled is HeadRuns' answer — the one the per-head aggregate gives on tracked runs
        // (card#11667): no run at all is NOT settled, and a run a newer run of its workflow
        // supersedes does not hold the head open.
        $head = HeadRuns::of($runs);
        $allTerminal = $head->settled();
        $emitFailed = [];
        if ($allTerminal) {
            foreach ($awaits as $await) {
                $settled = $head->settledPayload($await->repo_name, $await->head_sha, $await->pr, $measuredAt);
                if ($this->claimAndEmit($await, self::SETTLED, $settled, $head->settledSummary($await->repo_name, $await->head_sha, $await->pr), $head->fingerprint()) === EmitResult::Failed) {
                    $emitFailed[] = $await->id;
                }
            }
        }

        return ['runs' => $runs, 'error' => null, 'all_terminal' => $allTerminal, 'retry_not_before' => null, 'read' => true, 'emit_failed' => $emitFailed, 'unreadable' => null, 'unconfirmed' => false];
    }

    /** @return array{runs: null, error: null, all_terminal: false, retry_not_before: null, read: false, emit_failed: list<int>, unreadable: null, unconfirmed: false} */
    private static function noRead(): array
    {
        return ['runs' => null, 'error' => null, 'all_terminal' => false, 'retry_not_before' => null, 'read' => false, 'emit_failed' => [], 'unreadable' => null, 'unconfirmed' => false];
    }

    /**
     * End every await in `$awaits` because GitHub will not let this install read the repo: each is
     * claimed and gets ONE `ci_await_unreadable` — by {@see claimAndEmit()}, so a concurrent
     * reader of the same head cannot emit a second — except `$silentAwaitId`, which is claimed with
     * no event (its seat is being answered by a refusal). An await whose event could not be written
     * is kept, as for any emit, and the next read of its head tries again.
     *
     * @param  Collection<int, CiAwait>  $awaits
     * @return list<int> the awaits whose event could not be written
     */
    private function forgetUnreadable(Collection $awaits, CiRunsReadException $e, string $error, ?int $silentAwaitId): array
    {
        $first = $awaits->first();
        Log::warning('bridge ci_await: GitHub will not let this install read the repo\'s workflow runs, so every await on this head is ended now with ci_await_unreadable instead of being left to expire', [
            'repo' => $first?->repo_name, 'head_sha' => $first?->head_sha, 'awaits' => $awaits->count(), 'error' => $error,
        ]);
        $failed = [];
        foreach ($awaits as $await) {
            if ($await->id === $silentAwaitId) {
                CiAwait::query()->whereKey($await->id)->delete();

                continue;
            }
            if ($this->claimAndEmit($await, self::UNREADABLE, self::unreadablePayload($await, $e, $error), self::unreadableSummary($await, $error)) === EmitResult::Failed) {
                $failed[] = $await->id;
            }
        }

        return $failed;
    }

    /**
     * The list as it stands once the delivered run is known to be completed: its row is marked
     * completed (taking the delivery's conclusion where the list has none), or appended when the
     * list does not carry it yet. A listed row whose `run_attempt` is LATER than the delivered one,
     * or where either attempt is unknown, is left as listed: the delivery may be for an earlier
     * attempt of a run that has since been re-run.
     *
     * @param  list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>  $runs
     * @param  array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, conclusion: ?string, html_url: string, run_attempt: ?int}  $delivered
     * @return list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>
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
     * `$settledState` is a `ci_settled`'s {@see HeadRuns::fingerprint()}: it is recorded in
     * {@see CiHeadSettlementLedger} in the same transaction, so the per-head aggregate does not send
     * this seat the same settled state again (card#11667). Where the ledger ALREADY holds it — the
     * aggregate sent this seat that state before the await was stored — the await is claimed with
     * no event and answered {@see EmitResult::ClaimedElsewhere}: the seat has the event, and a
     * registration says `settled`.
     *
     * @param  array<string, mixed>  $payload
     */
    private function claimAndEmit(CiAwait $await, string $kind, array $payload, string $summary, ?string $settledState = null): EmitResult
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
            $alreadySent = false;
            $claimed = DB::transaction(function () use ($await, $intent, $settledState, &$alreadySent): bool {
                if (CiAwait::query()->whereKey($await->id)->delete() !== 1) {
                    return false;
                }
                if ($settledState !== null && ! CiHeadSettlementLedger::claim($await->agent, $await->repo, $await->head_sha, $settledState)) {
                    $alreadySent = true;

                    return true;
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
        if ($alreadySent) {
            Log::info('bridge ci_await: the await is answered by the ci_settled already sent to this seat for the same settled state — nothing is sent twice', ['agent' => $await->agent, 'repo' => $await->repo_name, 'head_sha' => $await->head_sha]);

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
     * @return list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>
     *
     * @throws CiRunsReadException naming why no complete run list was read
     */
    private function readRuns(string $repoName, string $headSha): array
    {
        return self::githubRead($repoName, static fn (GitHubReadClient $client): array => $client->workflowRunsForHead($repoName, $headSha));
    }

    /**
     * One workflow-runs read of `$repoName` for any head, made exactly as an await's read is made —
     * the same token resolution and the same failure classes — so `bridge:check` can say whether
     * `ci_await` on that repo would be answered (card#11600). Null when GitHub answered 2xx.
     */
    public static function probeRunsRead(string $repoName): ?CiRunsReadException
    {
        try {
            self::githubRead($repoName, static function (GitHubReadClient $client) use ($repoName): void {
                $client->probeWorkflowRuns($repoName);
            });
        } catch (CiRunsReadException $e) {
            return $e;
        }

        return null;
    }

    /**
     * Resolve `$repoName`'s read token as the receiver does and make `$read` with it, turning every
     * way it can fail into a {@see CiRunsReadException} of its class (card#11600):
     *  - NOT READABLE — reading again cannot answer: no token resolved for any reader
     *    ({@see noTokenForAnyReader()}), or GitHub answered 404 (its answer to a token that cannot
     *    see a private repo). Its message names the token's source and file, never the token.
     *  - NOT READABLE IF CONFIRMED — GitHub answered 401, or a 403 that is not a rate limit by its
     *    headers or body. A secondary rate limit can answer 403 with no header and no reset
     *    ("wait at least one minute"), and a token rotated outside the bridge can answer 401
     *    briefly, so it is retryable with `retryNotBefore` {@see CONFIRM_AFTER_SECONDS} out, and
     *    final only when that later read answers the same status ({@see evaluate()}).
     *  - RETRYABLE — a rate limit (a 429, or a 403 that says so), any other status, no answer, or
     *    a 200 whose body is not a run list.
     *
     * @template T
     *
     * @param  callable(GitHubReadClient): T  $read
     * @return T
     *
     * @throws CiRunsReadException
     */
    private static function githubRead(string $repoName, callable $read): mixed
    {
        $token = (new GitHubTokenResolver)->resolveFor($repoName);
        if (! $token->ok()) {
            throw new CiRunsReadException('no GitHub read token: '.$token->problem.' ('.self::tokenOrigin($token).')', repoUnreadable: self::noTokenForAnyReader($token));
        }

        try {
            return $read(new GitHubReadClient((string) $token->token, self::TIMEOUT_SECONDS));
        } catch (RequestException $e) {
            $status = $e->response->status();
            // A primary limit says X-RateLimit-Remaining: 0; a secondary limit is a 403 that may
            // leave Remaining above zero but carries Retry-After — or carries neither, and says so
            // only in its body's message (GitHub Docs, *Rate limits for the REST API*). The body is
            // read for that one test and never printed.
            $headerLimited = $status === 429 || ($status === 403 && ($e->response->header('X-RateLimit-Remaining') === '0' || $e->response->header('Retry-After') !== ''));
            $bodyLimited = ! $headerLimited && $status === 403 && self::bodySaysRateLimit($e);
            $limited = $headerLimited || $bodyLimited;
            // A limit only the body names carries no reset; GitHub asks for at least a minute.
            $resetAt = $headerLimited ? self::rateLimitReset($e) : ($bodyLimited ? Carbon::now()->addSeconds(self::CONFIRM_AFTER_SECONDS) : null);
            $unreadable = $status === 404;
            $confirm = ! $limited && in_array($status, [401, 403], true);

            throw new CiRunsReadException(
                "GitHub answered HTTP {$status} to the workflow-run read"
                    .($limited ? ' (rate limited'.($resetAt === null ? '' : ' until '.self::instant($resetAt)).')' : '')
                    .($unreadable || $confirm ? ' ('.self::tokenOrigin($token).')' : ''),
                $confirm ? Carbon::now()->addSeconds(self::CONFIRM_AFTER_SECONDS) : $resetAt,
                repoUnreadable: $unreadable,
                status: $status,
                needsConfirmation: $confirm,
            );
        } catch (ConnectionException|UnexpectedValueException $e) {
            throw new CiRunsReadException(RedactedErrorText::of($e));
        }
    }

    /** Whether a refused read's body says it is a rate limit. Only this test reads the body; nothing prints it. */
    private static function bodySaysRateLimit(RequestException $e): bool
    {
        $message = $e->response->json('message');

        return is_string($message) && stripos($message, 'rate limit') !== false;
    }

    /**
     * Whether a token that did not resolve is missing for EVERY process, not only this one. The
     * sweep also runs from `bridge:tick`, whose OS user may not be the receiver's: a file THIS
     * process cannot read ({@see TokenFileFault::Unreadable}), a source it could not determine
     * ({@see TokenFileFault::Undetermined} — `writeback.json` did not load, say), or an "absent"
     * file under a directory it cannot traverse says nothing about the receiver's read, so ending
     * every await on it would end awaits the receiver can still settle. Those stay retryable.
     */
    private static function noTokenForAnyReader(TokenResolution $token): bool
    {
        if ($token->fileFault === TokenFileFault::Absent) {
            return $token->path !== null && PathVisibility::ancestorIsTraversable($token->path);
        }

        return in_array($token->fileFault, [TokenFileFault::Empty, TokenFileFault::NotAFile, TokenFileFault::InsecurePermissions, TokenFileFault::Misconfigured], true);
    }

    /**
     * Where a repo's read token came from — or was looked for — as a message may print it: the
     * {@see TokenSource} and the file, never the token.
     */
    private static function tokenOrigin(TokenResolution $token): string
    {
        $source = match ($token->sourceKind) {
            TokenSource::WriteTokenPath => "the repo's write_token_path in writeback.json",
            TokenSource::Store => 'the coord credential store',
            TokenSource::TokenFile => 'the single GitHub token file',
            TokenSource::Ambient => 'GH_TOKEN',
            null => 'no token source',
        };

        return 'token source: '.$source.($token->path === null ? '' : ', file '.PastedSecretShape::displayPathSetting($token->path));
    }

    /**
     * What an operator does about a repo this install's token cannot read: one sentence, carried by
     * the `repo_unreadable` refusal, the `ci_await_unreadable` event and `bridge:check` alike.
     */
    public static function unreadableRemedy(string $repoName): string
    {
        return "map {$repoName} in the coord credential store's [git-credential-map] to a key whose token can read its workflow runs, or set {$repoName}'s write_token_path in writeback.json to a file holding such a token";
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

    private function upsert(string $agent, string $repoName, string $headSha, ?int $pr, Carbon $expiresAt, OverdueDeadline $overdue): bool
    {
        return DB::transaction(fn (): bool => $this->upsertRow($agent, $repoName, $headSha, $pr, $expiresAt, $overdue));
    }

    private function upsertRow(string $agent, string $repoName, string $headSha, ?int $pr, Carbon $expiresAt, OverdueDeadline $overdue): bool
    {
        $mine = CiAwait::query()->where('agent', $agent)->where('repo', self::key($repoName))->where('head_sha', $headSha);
        // A re-registration WITHOUT a pr keeps the one already recorded; one with a pr replaces it.
        $refresh = ['expires_at' => $expiresAt] + ($pr === null ? [] : ['pr' => $pr]);
        $deadline = ['overdue_at' => Carbon::now()->addSeconds($overdue->seconds), 'overdue_basis' => $overdue->basis];
        if ($mine->clone()->update($refresh) > 0) {
            $this->refreshDeadline($mine, $deadline, $overdue);

            return true;
        }
        try {
            CiAwait::query()->create(['agent' => $agent, 'repo' => self::key($repoName), 'repo_name' => $repoName, 'head_sha' => $headSha, 'pr' => $pr, 'expires_at' => $expiresAt] + $deadline);

            return false;
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration of the same head by the same seat created it first.
            $mine->clone()->update($refresh);
            $this->refreshDeadline($mine, $deadline, $overdue);

            return true;
        }
    }

    /**
     * On a refresh, the caller's own deadline replaces the stored one, and a row with none gets
     * one; a derived or default deadline never moves a stored one. ⛔ Never once the await's
     * `ci_await_overdue` was sent — the `whereNull` is the guard, in the same statement.
     *
     * @param  Builder<CiAwait>  $mine
     * @param  array{overdue_at: Carbon, overdue_basis: string}  $deadline
     */
    private function refreshDeadline(Builder $mine, array $deadline, OverdueDeadline $overdue): void
    {
        $q = $mine->clone()->whereNull('overdue_sent_at');
        if ($overdue->basis !== OverdueDeadline::BASIS_OVERRIDE) {
            $q->whereNull('overdue_at');
        }
        $q->update($deadline);
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

    /**
     * @param  ?list<array{id: int, workflow: string, workflow_id: ?int, run_number: ?int, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>  $runs
     * @return array<string, mixed>
     */
    private static function overduePayload(CiAwait $await, ?array $runs): array
    {
        return [
            'repo' => $await->repo_name,
            'head_sha' => $await->head_sha,
            'pr' => $await->pr,
            'registered_at' => self::instant($await->created_at),
            'overdue_at' => $await->overdue_at === null ? null : self::instant($await->overdue_at),
            'overdue_basis' => $await->overdue_basis,
            'expires_at' => self::instant($await->expires_at),
            'last_read_at' => $await->last_read_at === null ? null : self::instant($await->last_read_at),
            'last_error' => $await->last_error,
            'runs_seen' => $runs === null ? null : array_map(static fn (array $r): array => [
                'workflow' => $r['workflow'], 'status' => $r['status'], 'conclusion' => $r['conclusion'], 'html_url' => $r['html_url'],
            ], $runs),
        ];
    }

    /** @param  ?list<array{status: string, workflow: string, html_url: string}>  $runs */
    private static function overdueSummary(CiAwait $await, ?array $runs): string
    {
        $open = $runs === null ? [] : array_values(array_filter($runs, static fn (array $r): bool => $r['status'] !== 'completed'));
        $seen = match (true) {
            $runs === null => 'the runs its workflow_run deliveries reported could not be read',
            $runs === [] => 'no workflow_run delivery for this head has reached the bridge',
            $open === [] => 'every run its workflow_run deliveries reported is completed, but no read has settled the head yet',
            default => 'by the workflow_run deliveries the bridge received, still open: '.implode(', ', array_map(static fn (array $r): string => "{$r['workflow']} ({$r['status']}, {$r['html_url']})", $open)),
        };

        return "CI on {$await->repo_name}@".substr($await->head_sha, 0, 12).($await->pr === null ? '' : " (PR #{$await->pr})")
            .' is OVERDUE — past '.($await->overdue_basis === OverdueDeadline::BASIS_OVERRIDE ? 'the deadline you set' : "this repo's normal CI time")
            .' and not yet seen finished; '.$seen
            .($await->last_error === null ? '' : " (last runs read failed: {$await->last_error})")
            .'. Check it once now with ci-read. The wait stays registered, and its ending event (ci_settled, ci_await_unreadable or ci_await_expired) still follows.';
    }

    private static function expiredSummary(CiAwait $await): string
    {
        return "Stopped waiting for CI on {$await->repo_name}@".substr($await->head_sha, 0, 12).($await->pr === null ? '' : " (PR #{$await->pr})")
            .' — the await expired before every workflow run was seen terminal'
            .($await->last_error === null ? '' : " (last read failed: {$await->last_error})")
            .'. Run ci-read on this head, or re-register with ci_await.';
    }

    /** @return array<string, mixed> */
    private static function unreadablePayload(CiAwait $await, CiRunsReadException $e, string $error): array
    {
        return [
            'repo' => $await->repo_name,
            'head_sha' => $await->head_sha,
            'pr' => $await->pr,
            'registered_at' => self::instant($await->created_at),
            'status' => $e->status,
            'error' => $error,
            'remedy' => self::unreadableRemedy($await->repo_name),
        ];
    }

    private static function unreadableSummary(CiAwait $await, string $error): string
    {
        return "Stopped waiting for CI on {$await->repo_name}@".substr($await->head_sha, 0, 12).($await->pr === null ? '' : " (PR #{$await->pr})")
            ." — this bridge's GitHub token cannot read that repo's workflow runs ({$error}), so no read would ever settle the wait. Tell your operator, who can "
            .self::unreadableRemedy($await->repo_name).'.';
    }

    private static function instant(DateTimeInterface $at): string
    {
        return Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
