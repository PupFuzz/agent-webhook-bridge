<?php

namespace App\Bridge\Writeback;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Scheduling\Handlers\OwedWriteRetryJob;
use App\Bridge\Scheduling\Handlers\OwedWriteWatchdogJob;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Support\SubscriptionRegistry;
use App\Models\WebhookEvent;
use App\Models\WritebackOwedWrite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The durable writebacks the bridge still OWES, and the ONE path that applies any of them
 * (card#10849 / DL-440).
 *
 * ⭐ INSERT, THEN DRAIN — EVERY DURABLE WRITE, LIVE OR RETRIED. `DispatchService` inserts each
 * durable target it is about to run ({@see enqueue}) and then calls {@see drain} on its
 * subject; the scheduled sweep calls the same {@see drain}. drain() applies the OLDEST due row
 * of the subject, deletes it, and moves to the next — so on the healthy path the row it applies
 * IS the one just inserted, with no added latency, and a subject that owes an older write
 * applies that one first. There is no "is anything pending?" branch and no second apply path:
 * the ordering guarantee is a property of the table (FIFO by autoincrement `id` within a
 * subject), not of which caller got there first.
 *
 * ⭐ A RATE LIMIT (408/429) STOPS THE SUBJECT, NOTHING ELSE. drain() catches a rate-limited
 * `RequestException`, records the attempt ({@see MAX_ATTEMPTS}, exponential from
 * {@see BASE_BACKOFF_S}, never sooner than the refusal's `Retry-After`) and returns. It never
 * throws a rate limit back to its caller, so the delivery answers 200 and every other target,
 * push and agent of the dispatch runs. ⛔ ANY OTHER FAILURE PROPAGATES UNCHANGED, and leaves
 * its row queued: the dispatch 5xxs exactly as it did before, and the next drain of the subject
 * — a redelivery, the next event, or the sweep — retries it first. Such a row is stamped
 * `last_failed_at`, which is what keeps it from monopolising the sweep ({@see sweep}).
 *
 * ⭐ EVERY ENQUEUE DECLARES THE PERIODIC JOBS ({@see declareJobs}) BEFORE IT INSERTS. A row can
 * be left owed by a rate limit, by any other throw, by a drain that lost the subject's lock, or
 * by a process that died mid-apply — and only the first of those passes through a catch arm.
 * Declaring at the one place every row is born makes "an owed row has the watchdog and the
 * retry sweep behind it" a property of the insert, not of which way the apply failed.
 *
 * ⭐ ONE CONCURRENCY PRIMITIVE: a NON-BLOCKING per-subject `Cache::lock` around drain(), the
 * shape `App\Bridge\Support\AfterResponseGate` takes for the job registry. A caller that loses
 * it returns at once (its row is already inserted), and every holder RE-CHECKS the subject
 * after releasing — without that re-check a row inserted by the loser after the holder's last
 * read would sit until the next event (the lost-wakeup race). Belt and braces: a lock outlived
 * by a still-running handle() ({@see LEASE_S}) can let a second caller apply the same row
 * concurrently, and every durable handler is idempotent (the DurableReaction contract), so that
 * rare case is a no-op on the remote side, never a wrong write.
 *
 * ⚑ THE BOUNDS LIVE HERE AND NOWHERE ELSE — `docs/writeback.md` § *Failure behaviour* names
 * these constants and deliberately quotes no figure.
 */
final class OwedWriteQueue
{
    /** Attempts a rate-limited row gets before it is given up (`reason: rate_limited`). */
    public const MAX_ATTEMPTS = 5;

    /** The first backoff, doubled per attempt: attempt n waits `BASE_BACKOFF_S * 2^(n-1)`, or the refusal's Retry-After if longer. */
    public const BASE_BACKOFF_S = 60;

    /**
     * The per-subject lock's TTL. It must outlive the slowest handle() — the promote scan, at
     * its candidate cap, two GitHub reads and one kanban write per candidate at their own
     * timeouts — or two drains could apply one subject at once. `OwedWriteQueueTest` holds it
     * above that product, read off the handler's and the clients' own constants.
     */
    public const LEASE_S = 1800;

    /**
     * How long a row may sit owed before the watchdog gives it up (`reason: expired`). It is
     * the bound for an install that DISARMED the retry sweep, where nothing but the subject's
     * next event retries a row — so it is sized in hours, well past the whole retry schedule
     * (`BASE_BACKOFF_S * 2^MAX_ATTEMPTS`, held by a test), and unrelated to `GitHubWriteDebt`.
     */
    public const MAX_AGE_S = 6 * 3600;

    /**
     * Rows one subject may owe before the next insert gives up ALL of them (`reason: overflow`).
     * Unbounded growth on one subject is itself the defect signal, so this is the one case
     * where more than the head is dropped.
     */
    public const MAX_QUEUE_PER_SUBJECT = 25;

    /** The handlers whose subject is a card id parsed out of author-controlled text (DL-314). */
    private const CARD_KEYED_HANDLERS = ['kanban_move_card', 'kanban_block_reason'];

    private readonly WritebackAlertNotifier $alerts;

    public function __construct(
        private readonly HandlerRegistry $handlers,
        private readonly SubscriptionRegistry $subscriptions,
        ?WritebackAlertNotifier $alerts = null,
    ) {
        $this->alerts = $alerts ?? new WritebackAlertNotifier;
    }

    /**
     * The install-level identity of a durable write: which handler, keyed how, for which
     * (provider, scope). Agent-independent — every agent that classifies one event to one
     * handler + debounceKey emits the same write.
     */
    public static function subjectKey(string $provider, string $scopeId, string $handler, string $debounceKey): string
    {
        return sha1($provider."\x00".$scopeId."\x00".$handler."\x00".$debounceKey);
    }

    /**
     * Record $target as owed for $event. IDEMPOTENT on `(subject, event)` ONLY WHILE that row
     * still exists — a concurrent insert of the same pending write, or a redelivery arriving
     * before it is applied, is a no-op. Once that row is applied and deleted, a later call for
     * the same (subject, event) — e.g. a second agent classifying the same event to the same
     * handler + debounceKey — inserts and applies AGAIN; it is the durable handler's own
     * idempotency that makes that harmless, not this method. ⛔ A failure here (the database
     * down, the table missing) PROPAGATES — the durable write could not be recorded, and the
     * dispatch's 5xx is the existing contract for that.
     */
    public function enqueue(string $subjectKey, ReactionTarget $target, AgentConfig $agent, WebhookEvent $event): void
    {
        $this->declareJobs();
        if ($this->find($subjectKey, (int) $event->id) !== null) {
            return;
        }
        $this->purgeOnOverflow($subjectKey);

        try {
            WritebackOwedWrite::query()->create([
                'subject_key' => $subjectKey,
                'provider' => (string) $event->provider,
                'scope_id' => (string) $event->scope_id,
                'handler' => $target->handler,
                'debounce_key' => $target->debounceKey,
                'target_id' => $target->targetId,
                'agent_name' => $agent->agentName,
                'payload' => $target->payload,
                'webhook_event_id' => (int) $event->id,
                'queued_at' => now(),
                'attempts' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent agent inserted the same write between the read above and this
            // insert — the row exists, which is all this method promises.
        }
    }

    /**
     * Apply the subject's due rows, oldest first, until it is empty, its head is not yet due,
     * or its head is rate-limited again. Non-blocking: a subject another caller is draining is
     * left to that caller.
     */
    public function drain(string $subjectKey): void
    {
        do {
            $lock = Cache::lock(self::lockKey($subjectKey), self::LEASE_S);
            if (! $lock->get()) {
                return;
            }
            try {
                $mayContinue = $this->drainLocked($subjectKey);
            } finally {
                $lock->release();
            }
            // ⛔ THE RE-CHECK AFTER RELEASE. A caller that lost the lock above inserted its row
            // and returned trusting THIS holder to apply it; if that insert landed after this
            // holder's last head read, only this read can see it.
        } while ($mayContinue && $this->dueHead($subjectKey) !== null);
    }

    /** The row owed for `(subject, event)`, or null when there is none (never queued, or applied). */
    public function find(string $subjectKey, int $webhookEventId): ?WritebackOwedWrite
    {
        return WritebackOwedWrite::query()
            ->where('subject_key', $subjectKey)
            ->where('webhook_event_id', $webhookEventId)
            ->first();
    }

    /**
     * The scheduled sweep's pass: drain up to $maxSubjects subjects whose HEAD is due.
     *
     * ⛔ NO SUBJECT IS STARVED, on three legs. (1) EACH SUBJECT IS ISOLATED, and the first
     * failure is rethrown only AFTER the pass — a throw that ended the pass would starve every
     * subject ranked behind it. Rethrown afterwards, it still fails the job row, which is how a
     * job reports failure. (2) A HEAD THAT KEEPS FAILING RANKS LAST. A non-rate-limit failure
     * sets no `not_before` (so it is due on every pass) and, being oldest, would lead the `id`
     * order forever, filling every pass and keeping a rate-limited write elsewhere from ever
     * being retried. So heads whose last apply did not fail that way (`last_failed_at` null)
     * fill the pass by `id`. (3) ONE SLOT IS RESERVED FOR A FAILING HEAD. Leg (2) alone just
     * inverts the starvation: a rate-limit storm keeping $maxSubjects never-failed heads due
     * every pass would keep every failing head out until the watchdog expired it. So whenever
     * a due failing head exists, one slot goes to the least recently failed one; trying it
     * re-stamps it, behind every other failing head.
     *
     * THE BOUNDS, stated for $maxSubjects ≥ 2 (`OwedWriteRetryJob::MAX_SUBJECTS_PER_PASS`, held
     * by a test — at 1 the reserved slot would be the only slot): a due failing head is tried
     * within F passes, F the number of due failing heads (one per pass, round-robin). A due
     * never-failed head is tried once the older due never-failed heads ahead of it have had
     * $maxSubjects - 1 slots each; each head a pass tries leaves that group (applied, backed
     * off, or failed). The rows ahead of it are finite (every later insert has a higher `id`),
     * and one re-enters the group only after a backoff: a rate limit (at most `MAX_ATTEMPTS`
     * times, then given up) or an unreadable agent config (exponential, with no attempt cap —
     * {@see recordAttempt}). The watchdog bounds both at `MAX_AGE_S`, so the wait is finite,
     * at worst that long. A drain that loses the subject's lock to a live delivery consumes a
     * slot without resolving the head; that delivery is draining it.
     *
     * @return int how many subjects were drained
     */
    public function sweep(int $maxSubjects): int
    {
        $due = fn () => $this->heads()
            ->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', now()));

        $reserved = $due()->whereNotNull('last_failed_at')
            ->orderBy('last_failed_at')
            ->orderBy('id')
            ->value('subject_key');
        $subjects = $due()
            ->when($reserved !== null, fn ($q) => $q->where('subject_key', '!=', $reserved))
            ->orderByRaw('last_failed_at IS NOT NULL')
            ->orderBy('last_failed_at')
            ->orderBy('id')
            ->limit($reserved !== null ? $maxSubjects - 1 : $maxSubjects)
            ->pluck('subject_key');
        if ($reserved !== null) {
            $subjects->push($reserved);
        }

        $failure = null;
        foreach ($subjects as $subjectKey) {
            try {
                $this->drain((string) $subjectKey);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $subjects->count();
    }

    /**
     * The watchdog's pass: give up the HEAD of every subject owed longer than
     * {@see MAX_AGE_S}. Only the head — a stuck head does not take a healthy tail down with
     * it, and the next row becomes the head, retried by the subject's next drain. A subject
     * being drained right now is skipped; the next pass sees it again.
     *
     * @return int how many rows were given up
     */
    public function expireAged(int $maxRows): int
    {
        /** @var Collection<int, WritebackOwedWrite> $aged */
        $aged = $this->heads()
            ->where('queued_at', '<=', now()->subSeconds(self::MAX_AGE_S))
            ->orderBy('id')
            ->limit($maxRows)
            ->get();

        $expired = 0;
        foreach ($aged as $row) {
            $lock = Cache::lock(self::lockKey($row->subject_key), self::LEASE_S);
            if (! $lock->get()) {
                continue;
            }
            try {
                if (WritebackOwedWrite::query()->whereKey($row->id)->exists()) {
                    $this->giveUp($row, 'expired');
                    $expired++;
                }
            } finally {
                $lock->release();
            }
        }

        return $expired;
    }

    /**
     * Stop owing $row: delete it (so the subject advances) and raise ONE alert naming the
     * write and the `bridge:replay` remedy. `$reason` is `rate_limited`, `expired` or
     * `target_gone`; overflow gives up a whole subject through {@see purgeOnOverflow}.
     */
    public function giveUp(WritebackOwedWrite $row, string $reason): void
    {
        $row->delete();
        $gap = OwedWriteRetryJob::clockRetryGap();
        $this->alerts->notifyOwedWriteGaveUp(
            'owed_write.gave_up',
            'bridge owed-write: GAVE UP on a durable write the bridge owed — it was NOT applied (see `reason`; `remedy` re-runs it)',
            self::rowContext($row) + ['reason' => $reason, 'retry_sweep_gap' => $gap],
            self::repoOf($row), $row->handler, $reason, self::withholdsCardId($row),
            [$row->webhook_event_id], $row->attempts, self::remedy($row), $gap,
        );
    }

    /**
     * One drain under the lock. Returns false when it stopped because the subject refused
     * (a rate limit, or its agent config could not be read) — the caller must then not come
     * straight back — and true when it stopped because nothing is due.
     */
    private function drainLocked(string $subjectKey): bool
    {
        $row = $this->dueHead($subjectKey);
        while ($row !== null) {
            $handler = $this->handlers->resolve($row->handler);
            if (! $handler instanceof DurableReaction) {
                // The handler that owes this write is no longer registered as durable here —
                // nothing can apply it, so waiting would only age it out later and quieter.
                $this->giveUp($row, 'target_gone');
                $row = $this->dueHead($subjectKey);

                continue;
            }

            try {
                $agent = $this->resolveAgent($row);
            } catch (ConfigException $e) {
                // Transient and not the write's fault (a YAML mid-edit): one recorded attempt,
                // never a give-up.
                $this->recordAttempt($row, null, $e);

                return false;
            }
            if ($agent === null) {
                $this->giveUp($row, 'target_gone');
                $row = $this->dueHead($subjectKey);

                continue;
            }

            try {
                $handler->handle(self::targetOf($row), $agent);
            } catch (Throwable $e) {
                if (! $e instanceof RequestException || ! RefusalContext::isRateLimited($e)) {
                    $this->recordFailure($row, $e);
                    throw $e;
                }
                $this->recordAttempt($row, $e, $e);
                if ($row->attempts >= self::MAX_ATTEMPTS) {
                    $this->giveUp($row, 'rate_limited');
                }

                return false;
            }

            $row->delete();
            $row = $this->dueHead($subjectKey);
        }

        return true;
    }

    /**
     * attempts++, and the row is not retried before `BASE_BACKOFF_S * 2^(attempts-1)` — or the
     * refusal's own Retry-After, whichever is later.
     */
    private function recordAttempt(WritebackOwedWrite $row, ?RequestException $refusal, Throwable $cause): void
    {
        $row->attempts = $row->attempts + 1;
        // Its backoff governs it now, not the failing-head rank (see sweep()).
        $row->last_failed_at = null;
        $wait = max(
            $refusal !== null ? (RefusalContext::retryAfterSeconds($refusal) ?? 0) : 0,
            self::BASE_BACKOFF_S * (2 ** ($row->attempts - 1)),
        );
        $row->not_before = now()->addSeconds($wait);
        $row->last_status = $refusal?->response->status();
        $row->last_error = mb_substr(RedactedErrorText::of($cause), 0, 1000);
        $row->save();

        if ($refusal !== null) {
            Log::warning('bridge owed-write: a durable write was refused as rate-limited — it is queued as OWED and the bridge retries it itself', [
                'catalog_id' => 'owed_write.rate_limited',
                'status' => $row->last_status,
                'retry_after' => RefusalContext::retryAfterSeconds($refusal),
                'not_before' => $row->not_before->toIso8601String(),
            ] + self::rowContext($row));
        } else {
            Log::warning('bridge owed-write: the agent config could not be read to apply an owed write — left queued, retried later', [
                'catalog_id' => 'owed_write.agent_config_unreadable',
                'error' => $row->last_error,
                'not_before' => $row->not_before->toIso8601String(),
            ] + self::rowContext($row));
        }
    }

    /**
     * A failure that is not a rate limit: no attempt counted and no `not_before` — the row stays
     * due, and the throw propagates exactly as it did — but it is stamped, so {@see sweep} ranks
     * it behind every head that is not failing this way.
     */
    private function recordFailure(WritebackOwedWrite $row, Throwable $cause): void
    {
        $row->last_failed_at = now();
        $row->last_status = $cause instanceof RequestException ? $cause->response->status() : null;
        $row->last_error = mb_substr(RedactedErrorText::of($cause), 0, 1000);
        try {
            $row->save();
        } catch (Throwable $e) {
            // ⛔ Never replaces the handler's failure: the caller rethrows THAT one. Unstamped,
            // the row still ranks as never-failed, which costs only sweep order.
            Log::warning('bridge owed-write: could not record a failed apply on its owed write — the failure itself still propagates', [
                'catalog_id' => 'owed_write.failure_unrecorded',
                'error' => RedactedErrorText::of($e),
                'cause' => $row->last_error,
            ] + self::rowContext($row));
        }
    }

    /**
     * Any agent config that loads will do — no durable handler reads `$agent` beyond its
     * signature — so the row's own agent if it is still configured, else the first agent still
     * subscribed to the row's `(provider, scope)`, else null: the write's owner is gone.
     */
    private function resolveAgent(WritebackOwedWrite $row): ?AgentConfig
    {
        foreach ($this->subscriptions->agentConfigs() as $config) {
            if ($config->agentName === $row->agent_name) {
                return $config;
            }
        }

        return $this->subscriptions->subscribedTo($row->provider, $row->scope_id)[0] ?? null;
    }

    /**
     * The (MAX_QUEUE_PER_SUBJECT + 1)th insert on one subject: give up EVERY row it owes in one
     * alert and empty it, so the new row starts clean. Under the subject's lock, so a drain
     * mid-apply is never reported as dropped; a subject someone is draining is left for the
     * next insert to purge.
     */
    private function purgeOnOverflow(string $subjectKey): void
    {
        if (WritebackOwedWrite::query()->where('subject_key', $subjectKey)->count() < self::MAX_QUEUE_PER_SUBJECT) {
            return;
        }
        $lock = Cache::lock(self::lockKey($subjectKey), self::LEASE_S);
        if (! $lock->get()) {
            return;
        }
        try {
            /** @var Collection<int, WritebackOwedWrite> $rows */
            $rows = WritebackOwedWrite::query()->where('subject_key', $subjectKey)->orderBy('id')->get();
            if ($rows->isEmpty()) {
                return;
            }
            WritebackOwedWrite::query()->whereIn('id', $rows->pluck('id'))->delete();

            $head = $rows->first();
            $eventIds = array_values($rows->map(fn (WritebackOwedWrite $r): int => $r->webhook_event_id)->all());
            $gap = OwedWriteRetryJob::clockRetryGap();
            $this->alerts->notifyOwedWriteGaveUp(
                'owed_write.overflow_gave_up',
                'bridge owed-write: GAVE UP on EVERY write one subject owed — it exceeded the per-subject bound, which is itself the defect signal; none of them was applied (`remedy` re-runs each)',
                self::rowContext($head) + ['dropped' => count($eventIds), 'webhook_event_ids' => $eventIds, 'retry_sweep_gap' => $gap],
                self::repoOf($head), $head->handler, 'overflow', self::withholdsCardId($head),
                $eventIds, (int) $rows->max('attempts'),
                implode('; ', $rows->map(fn (WritebackOwedWrite $r): string => self::remedy($r))->all()),
                $gap,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Make sure the always-on watchdog — and, unless disabled, the default-armed retry sweep —
     * has an instance to run, at every {@see enqueue}, before the row exists. Declared by the
     * queue rather than shipped as a migration row: an install that never makes a durable
     * write never grows either periodic job. An operator who DISABLES an instance keeps it
     * disabled (`JobRegistry::insert` writes `enabled` at create only); one who REMOVES it gets
     * it back at the next durable write. Best-effort: failing to declare either must never
     * change what the dispatch answers — and costs one indexed `exists()` per instance once
     * both exist.
     */
    private function declareJobs(): void
    {
        try {
            app(JobRegistry::class)->declareIfAbsent(OwedWriteWatchdogJob::spec());
        } catch (Throwable $e) {
            Log::warning('bridge owed-write: could not declare the owed-write watchdog job — owed writes will not be aged out or alerted on until it exists', [
                'catalog_id' => 'owed_write.watchdog_undeclared',
                'error' => RedactedErrorText::of($e),
                'remedy' => 'php artisan bridge:jobs add '.OwedWriteWatchdogJob::INSTANCE.' --handler='.OwedWriteWatchdogJob::NAME.' (docs/periodic-jobs.md)',
            ]);
        }

        // ⛔ NOT ATTEMPTED WHILE A KILL SWITCH DISARMS IT: a disarmed mutator's spec is REFUSED
        // at insert (JobRegistry::insert), so trying it here would be an ordinary, expected
        // outcome of the operator's own choice — not the config gap this exists to report
        // loudly. Checked directly rather than caught, so a disabled retry sweep never logs as
        // though something were missing.
        if (JobHandlerRegistry::disarmedBy(OwedWriteRetryJob::NAME) !== null) {
            return;
        }
        try {
            app(JobRegistry::class)->declareIfAbsent(OwedWriteRetryJob::spec());
        } catch (Throwable $e) {
            Log::warning('bridge owed-write: could not declare the owed-write retry job — owed writes will not be retried on a clock until it exists (each subject\'s next event still retries it inline)', [
                'catalog_id' => 'owed_write.retry_undeclared',
                'error' => RedactedErrorText::of($e),
                'remedy' => 'php artisan bridge:jobs add '.OwedWriteRetryJob::INSTANCE.' --handler='.OwedWriteRetryJob::NAME.' (docs/periodic-jobs.md)',
            ]);
        }
    }

    /** The subject's oldest row, when it is due now. */
    private function dueHead(string $subjectKey): ?WritebackOwedWrite
    {
        $head = WritebackOwedWrite::query()->where('subject_key', $subjectKey)->orderBy('id')->first();
        if ($head === null || ($head->not_before !== null && $head->not_before->isFuture())) {
            return null;
        }

        return $head;
    }

    /**
     * The head row of every subject — the oldest id per `subject_key`.
     *
     * @return Builder<WritebackOwedWrite>
     */
    private function heads()
    {
        return WritebackOwedWrite::query()->whereIn('id', WritebackOwedWrite::query()->selectRaw('MIN(id)')->groupBy('subject_key'));
    }

    private static function lockKey(string $subjectKey): string
    {
        return 'bridge:owed-write:'.$subjectKey;
    }

    private static function targetOf(WritebackOwedWrite $row): ReactionTarget
    {
        return new ReactionTarget(
            handler: $row->handler,
            targetId: $row->target_id,
            debounceKey: $row->debounce_key,
            payload: $row->payload,
        );
    }

    /**
     * Whether the channel must withhold the subject's card id (DL-314). A move / block-reason
     * subject is keyed by an id parsed out of author-controlled text that nothing here has
     * verified as this install's; no other durable subject is keyed by a card at all, so every
     * other give-up carries a null `card_id` without claiming one was withheld.
     */
    private static function withholdsCardId(WritebackOwedWrite $row): bool
    {
        return in_array($row->handler, self::CARD_KEYED_HANDLERS, true);
    }

    private static function repoOf(WritebackOwedWrite $row): string
    {
        $repo = $row->payload['repo'] ?? null;

        return is_string($repo) && $repo !== '' ? $repo : $row->scope_id;
    }

    private static function remedy(WritebackOwedWrite $row): string
    {
        return "php artisan bridge:replay {$row->webhook_event_id} --agent={$row->agent_name} --force";
    }

    /**
     * The log context for a row: everything, including the target id the channel may not
     * carry — the log is the local operator's own surface (DL-314).
     *
     * @return array<string, mixed>
     */
    private static function rowContext(WritebackOwedWrite $row): array
    {
        return [
            'handler' => $row->handler,
            'target_id' => $row->target_id,
            'provider' => $row->provider,
            'scope_id' => $row->scope_id,
            'agent' => $row->agent_name,
            'webhook_event_id' => $row->webhook_event_id,
            'attempts' => $row->attempts,
            'queued_at' => $row->queued_at->toIso8601String(),
        ];
    }
}
