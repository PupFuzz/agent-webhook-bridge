<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanPromoteReleasedHandler;
use App\Bridge\Scheduling\Handlers\OwedWriteRetryJob;
use App\Bridge\Scheduling\Handlers\OwedWriteWatchdogJob;
use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobRefusal;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\KanbanHttpClient;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\OwedWriteQueue;
use App\Models\ScheduledJob;
use App\Models\WebhookEvent;
use App\Models\WritebackOwedWrite;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `OwedWriteQueue` — the one apply path for a durable write (card#10849 / DL-440) — driven
 * directly, with a probe handler whose answer each test scripts: backoff and Retry-After, the
 * give-up alert's dedup, the watchdog, the per-subject lock, and overflow.
 */
class OwedWriteQueueTest extends TestCase
{
    use RefreshDatabase;

    private const ALERT_URL = 'http://127.0.0.1:9932/';

    private const SUBJECT_TARGET = 'card-77';

    private string $dir;

    private HandlerRegistry $handlers;

    /** @var list<string> the `payload.tag` of every write the probe was asked to apply, in order */
    private array $applied = [];

    /** @var \Closure(ReactionTarget): void what the probe does before it records a success */
    private \Closure $behaviour;

    private int $deliveries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bridge-10849-queue-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir, 'bridge.state_dir' => $this->dir.'/state']);
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->behaviour = static function (): void {};
        $this->handlers = new HandlerRegistry;
        $this->handlers->register('probe_write', $this->probe());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // --- §10.6: Retry-After and the first-attempt backoff floor ---

    public function test_a_retry_after_longer_than_the_backoff_holds_the_row_until_it_elapses(): void
    {
        $this->rateLimitFirst(retryAfter: '600');
        $this->owe('a');

        $this->assertSame([], $this->applied);
        $this->assertSame(1, WritebackOwedWrite::query()->sole()->attempts);

        $this->travel(OwedWriteQueue::BASE_BACKOFF_S + 1)->seconds();
        $this->queue()->drain($this->subject());
        $this->assertSame([], $this->applied, 'the Retry-After, not the shorter backoff, decided when to retry');

        $this->travel(600)->seconds();
        $this->queue()->drain($this->subject());
        $this->assertSame(['a'], $this->applied);
        $this->assertSame(0, WritebackOwedWrite::query()->count());
    }

    public function test_a_retry_after_shorter_than_the_first_backoff_is_floored_at_the_backoff(): void
    {
        $this->rateLimitFirst(retryAfter: '5');
        $this->owe('a');

        $this->travel(30)->seconds();
        $this->queue()->drain($this->subject());
        $this->assertSame([], $this->applied, 'a drain before not_before does not call the handler');

        $this->travel(OwedWriteQueue::BASE_BACKOFF_S)->seconds();
        $this->queue()->drain($this->subject());
        $this->assertSame(['a'], $this->applied);
    }

    // --- §10.7: the same subject gives up twice → two alerts ---

    public function test_the_same_subject_giving_up_twice_raises_two_alerts(): void
    {
        $this->behaviour = fn () => throw $this->rateLimited();

        $this->exhaust('first');
        $this->travel(1)->days();
        $this->exhaust('second');

        $gaveUp = $this->alertsOfType('writeback_owed_write_gave_up');
        $this->assertCount(2, $gaveUp, 'neither give-up suppressed the other');
        foreach ($gaveUp as $body) {
            $this->assertSame('rate_limited', $body['reason']);
            $this->assertSame(OwedWriteQueue::MAX_ATTEMPTS, $body['attempts']);
            $this->assertStringStartsWith('php artisan bridge:replay ', $body['remedy']);
        }
        $this->assertSame(0, WritebackOwedWrite::query()->count());
    }

    // --- operator ruling (DL-440): a give-up names the config that kept the clock retry away ---

    public function test_a_give_up_names_no_gap_when_the_retry_sweep_is_in_place(): void
    {
        $this->owe('stuck-then-aged', drain: false);
        $this->rateLimitFirst(retryAfter: null);
        $this->queue()->drain($this->subject());   // owed ⇒ both instances declared
        $this->travel(OwedWriteQueue::MAX_AGE_S + 60)->seconds();
        (new OwedWriteWatchdogJob($this->handlers))->run($this->jobContext());

        $this->assertNull($this->alertsOfType('writeback_owed_write_gave_up')[0]['retry_sweep_gap']);
    }

    /**
     * @return iterable<string, array{0: callable(): void, 1: string}>
     */
    public static function sweepGaps(): iterable
    {
        yield 'kill switch' => [fn () => config(['bridge.jobs.owed_write_retry_disabled' => true]), 'BRIDGE_OWED_WRITE_RETRY_DISABLED=true'];
        yield 'jobs disabled' => [fn () => config(['bridge.jobs.enabled' => false]), 'BRIDGE_JOBS_ENABLED=false'];
        yield 'pass unusable' => [fn () => config(['bridge.jobs.max_per_pass' => 0]), 'the job registry can run no pass'];
        yield 'instance disabled' => [fn () => ScheduledJob::query()->where('name', OwedWriteRetryJob::INSTANCE)->update(['enabled' => false]), 'bridge:jobs enable '.OwedWriteRetryJob::INSTANCE];
        yield 'instance removed' => [fn () => ScheduledJob::query()->where('name', OwedWriteRetryJob::INSTANCE)->delete(), 'bridge:jobs add '.OwedWriteRetryJob::INSTANCE];
        yield 'instance refused' => [fn () => ScheduledJob::query()->where('name', OwedWriteRetryJob::INSTANCE)->update(['last_status' => ScheduledJob::STATUS_REFUSED, 'last_error' => 'unarmed']), 'was REFUSED at its last run: unarmed'];
    }

    #[DataProvider('sweepGaps')]
    public function test_a_give_up_names_the_config_that_kept_the_retry_sweep_away(callable $break, string $named): void
    {
        $this->owe('stuck', drain: false);
        $this->rateLimitFirst(retryAfter: null);
        $this->queue()->drain($this->subject());
        $break();
        $this->travel(OwedWriteQueue::MAX_AGE_S + 60)->seconds();
        $this->queue()->giveUp(WritebackOwedWrite::query()->sole(), 'expired');

        $gap = $this->alertsOfType('writeback_owed_write_gave_up')[0]['retry_sweep_gap'];
        $this->assertIsString($gap);
        $this->assertStringContainsString($named, $gap);
    }

    // --- §10.8: the watchdog gives up only an aged HEAD ---

    public function test_the_watchdog_gives_up_only_the_aged_head_and_the_row_behind_it_still_drains(): void
    {
        $this->behaviour = function (ReactionTarget $t): void {
            if (($t->payload['tag'] ?? null) === 'stuck') {
                throw $this->rateLimited();
            }
        };
        $this->owe('stuck');
        $this->owe('healthy', drain: false);
        $this->assertSame(2, WritebackOwedWrite::query()->count());

        // BOTH rows are past the age bound: what spares the tail is that it is not the head.
        $this->travel(OwedWriteQueue::MAX_AGE_S + 60)->seconds();
        $outcome = (new OwedWriteWatchdogJob($this->handlers))->run($this->jobContext());

        $this->assertStringContainsString('gave up 1', $outcome->summary);
        $remaining = WritebackOwedWrite::query()->sole();
        $this->assertSame('healthy', $remaining->payload['tag']);
        $this->assertSame(0, $remaining->attempts, 'the healthy row was not touched');
        $this->assertSame(['expired'], array_column($this->alertsOfType('writeback_owed_write_gave_up'), 'reason'));

        $this->queue()->drain($this->subject());
        $this->assertSame(['healthy'], $this->applied);
        $this->assertSame(0, WritebackOwedWrite::query()->count());
    }

    public function test_the_watchdog_needs_no_arming_and_the_sweep_does(): void
    {
        $registry = new JobHandlerRegistry([], $this->app->make(StandupGate::class), $this->handlers);

        $this->assertInstanceOf(OwedWriteWatchdogJob::class, $registry->runnable(OwedWriteWatchdogJob::NAME));
        $this->assertSame(JobCapability::ReadAndAlert, $registry->resolve(OwedWriteWatchdogJob::NAME)?->capability());
        $refusal = $registry->runnable(OwedWriteRetryJob::NAME);
        $this->assertInstanceOf(JobRefusal::class, $refusal);
        $this->assertSame(JobRefusal::UNARMED_MUTATOR, $refusal->reason);
    }

    public function test_the_first_durable_write_declares_the_watchdog_instance(): void
    {
        $this->assertFalse(ScheduledJob::query()->where('name', OwedWriteWatchdogJob::INSTANCE)->exists());

        $this->owe('a');   // a healthy write — declared before the insert, not on a failure

        $this->assertSame(['a'], $this->applied);
        $job = ScheduledJob::query()->where('name', OwedWriteWatchdogJob::INSTANCE)->sole();
        $this->assertTrue($job->enabled);
        $this->assertSame(OwedWriteWatchdogJob::NAME, $job->handler);
    }

    /**
     * Operator ruling, 2026-09-29 (card#10849 / DL-440): `owed_write_retry` ships ARMED and its
     * instance is declared by default, the one named exception to DL-325's default-off.
     */
    public function test_the_first_durable_write_also_declares_the_retry_instance_armed_by_default(): void
    {
        $this->assertFalse(ScheduledJob::query()->where('name', OwedWriteRetryJob::INSTANCE)->exists());

        $this->owe('a');   // a healthy write — declared before the insert, not on a failure

        $this->assertSame(['a'], $this->applied);
        $job = ScheduledJob::query()->where('name', OwedWriteRetryJob::INSTANCE)->sole();
        $this->assertTrue($job->enabled);
        $this->assertSame(OwedWriteRetryJob::NAME, $job->handler);
    }

    public function test_the_kill_switch_withholds_both_the_arming_and_the_retry_instance(): void
    {
        config(['bridge.jobs.owed_write_retry_disabled' => true]);

        $this->assertSame(JobRefusal::UNARMED_MUTATOR, $this->app->make(JobHandlerRegistry::class)->runnable(OwedWriteRetryJob::NAME)?->reason ?? null);

        $this->rateLimitFirst(retryAfter: null);
        $this->owe('a');

        // The watchdog is unaffected by the retry job's own kill switch.
        $this->assertTrue(ScheduledJob::query()->where('name', OwedWriteWatchdogJob::INSTANCE)->exists());
        $this->assertFalse(ScheduledJob::query()->where('name', OwedWriteRetryJob::INSTANCE)->exists(), 'a disabled retry job gets no instance — inserting one would be refused anyway');
    }

    /**
     * A write left owed by a failure that is NOT an HTTP error response — a timeout or a
     * refused connection is a `ConnectionException`, not a `RequestException` — must still have
     * the watchdog and the retry sweep behind it, or nothing ever alerts on it.
     */
    public function test_a_write_left_owed_by_a_connection_failure_has_both_jobs_declared(): void
    {
        $this->behaviour = fn () => throw new ConnectionException('cURL error 28: Operation timed out');

        try {
            $this->owe('a');
            $this->fail('a connection failure propagates, exactly as before');
        } catch (ConnectionException) {
        }

        $this->assertSame('a', WritebackOwedWrite::query()->sole()->payload['tag'], 'the write stays owed');
        $this->assertTrue(ScheduledJob::query()->where('name', OwedWriteWatchdogJob::INSTANCE)->exists());
        $this->assertTrue(ScheduledJob::query()->where('name', OwedWriteRetryJob::INSTANCE)->exists());
    }

    /**
     * A write left owed because its drain lost the subject's lock — to a holder that will never
     * release it, e.g. a process killed mid-apply, whose lease still runs for `LEASE_S` — must
     * still have both jobs behind it. The delivery answers 200 "owed" on this path.
     */
    public function test_a_write_left_owed_by_a_lost_lock_has_both_jobs_declared(): void
    {
        $held = Cache::lock('bridge:owed-write:'.$this->subject(), OwedWriteQueue::LEASE_S);
        $this->assertTrue($held->get());

        $this->owe('a');

        $this->assertSame([], $this->applied, 'the drain lost the lock and applied nothing');
        $this->assertSame('a', WritebackOwedWrite::query()->sole()->payload['tag']);
        $this->assertTrue(ScheduledJob::query()->where('name', OwedWriteWatchdogJob::INSTANCE)->exists());
        $this->assertTrue(ScheduledJob::query()->where('name', OwedWriteRetryJob::INSTANCE)->exists());
        $held->release();
    }

    // --- §10.9: the per-subject lock ---

    public function test_an_overlapping_drain_of_the_same_subject_no_ops_and_the_write_applies_once(): void
    {
        $overlapped = false;
        $this->behaviour = function () use (&$overlapped): void {
            if (! $overlapped) {
                $overlapped = true;
                $this->queue()->drain($this->subject());   // a second caller, mid-apply
            }
        };

        $this->owe('a');

        $this->assertSame(['a'], $this->applied, 'the overlapping drain applied nothing');
        $this->assertSame(0, WritebackOwedWrite::query()->count());
        $this->assertSame([], $this->alertsOfType('writeback_owed_write_gave_up'));
    }

    public function test_a_row_inserted_after_the_holders_last_read_is_applied_by_the_holders_recheck(): void
    {
        // The lost-wakeup window: a concurrent request inserts its row AFTER the holder read
        // the subject empty and BEFORE the holder released, so its own drain loses the lock.
        $fired = false;
        $armed = false;
        $this->behaviour = function () use (&$armed): void {
            $armed = true;
        };
        DB::listen(function (QueryExecuted $q) use (&$fired, &$armed): void {
            if (! $armed || $fired || ! str_contains($q->sql, 'writeback_owed_writes') || ! str_contains(strtolower($q->sql), 'limit 1')) {
                return;
            }
            if (WritebackOwedWrite::query()->count() !== 0) {
                return;   // not yet the read that found the subject empty
            }
            $fired = true;
            $this->owe('late', drain: true);   // inserts, then loses the lock to the holder
        });

        $this->owe('first');

        $this->assertTrue($fired, 'the window was exercised');
        $this->assertSame(['first', 'late'], $this->applied);
        $this->assertSame(0, WritebackOwedWrite::query()->count());
    }

    // --- §10.10: overflow ---

    public function test_the_insert_past_the_per_subject_bound_gives_up_every_owed_row_in_one_alert(): void
    {
        $ids = [];
        for ($i = 0; $i < OwedWriteQueue::MAX_QUEUE_PER_SUBJECT; $i++) {
            $ids[] = $this->owe("r{$i}", drain: false);
        }
        $this->assertSame(OwedWriteQueue::MAX_QUEUE_PER_SUBJECT, WritebackOwedWrite::query()->count());

        $newest = $this->owe('newest', drain: false);

        $gaveUp = $this->alertsOfType('writeback_owed_write_gave_up');
        $this->assertCount(1, $gaveUp);
        $this->assertSame('overflow', $gaveUp[0]['reason']);
        $this->assertSame($ids, $gaveUp[0]['webhook_event_ids']);
        $row = WritebackOwedWrite::query()->sole();
        $this->assertSame($newest, $row->webhook_event_id);
        $this->assertSame(0, $row->attempts);
    }

    // --- the sweep: one failing subject does not starve the rest ---

    public function test_a_subject_whose_head_keeps_failing_does_not_starve_the_sweep_of_other_subjects(): void
    {
        $this->behaviour = function (ReactionTarget $t): void {
            if (($t->payload['tag'] ?? null) === 'broken') {
                throw new RequestException(new Response(new GuzzleResponse(500, [], '{"message":"Server Error"}')));
            }
        };
        // The failing subject is the OLDER one, so it is first in the sweep's order.
        $this->oweOn('probe-broken', 'broken');
        $this->oweOn('probe-healthy', 'healthy');

        try {
            $this->queue()->sweep(5);
            $this->fail('the failing subject must still fail the pass');
        } catch (RequestException $e) {
            $this->assertSame(500, $e->response->status());
        }

        $this->assertSame(['healthy'], $this->applied, 'the healthy subject was drained in the same pass');
        $this->assertSame('broken', WritebackOwedWrite::query()->sole()->payload['tag'], 'the failing write stays owed');
    }

    /**
     * More persistently-failing subjects than one pass drains, all OLDER than a rate-limited
     * write that is due: by `id` alone the failing heads fill every pass and the rate-limited
     * write is never retried until the watchdog expires it.
     */
    public function test_persistently_failing_heads_cannot_monopolise_the_sweep(): void
    {
        $attempted = [];
        $limitedOnce = false;
        $this->behaviour = function (ReactionTarget $t) use (&$attempted, &$limitedOnce): void {
            $tag = (string) ($t->payload['tag'] ?? '');
            $attempted[] = $tag;
            if (str_starts_with($tag, 'broken')) {
                throw new RequestException(new Response(new GuzzleResponse(500, [], '{"message":"Server Error"}')));
            }
            if ($tag === 'limited' && ! $limitedOnce) {
                $limitedOnce = true;
                throw $this->rateLimited();
            }
        };

        $perPass = OwedWriteRetryJob::MAX_SUBJECTS_PER_PASS;
        // One more failing subject than a pass drains, each already failed once live …
        for ($i = 0; $i <= $perPass; $i++) {
            $this->oweOn("probe-broken-{$i}", "broken-{$i}");
            try {
                $this->queue()->drain(OwedWriteQueue::subjectKey('kanban', '5', 'probe_write', "probe-broken-{$i}"));
            } catch (RequestException) {
            }
        }
        // … and, newest of all, a write refused by a rate limit whose backoff then elapses.
        $this->oweOn('probe-limited', 'limited');
        $this->queue()->drain(OwedWriteQueue::subjectKey('kanban', '5', 'probe_write', 'probe-limited'));
        $this->travel(OwedWriteQueue::BASE_BACKOFF_S + 1)->seconds();
        $attempted = [];

        try {
            $this->queue()->sweep($perPass);
        } catch (RequestException) {
        }
        $this->assertContains('limited', $this->applied, 'the due rate-limited write was retried in the first pass');

        $this->travel(1)->seconds();
        try {
            $this->queue()->sweep($perPass);
        } catch (RequestException) {
        }
        for ($i = 0; $i <= $perPass; $i++) {
            $this->assertContains("broken-{$i}", $attempted, "failing subject {$i} was not starved by the others");
        }
    }

    /**
     * The converse of the test above: more due NEVER-failed heads than one pass drains (a
     * sustained rate-limit storm keeps them coming) must not keep a failing head out of every
     * pass — one slot per pass is reserved for the least recently failed due head.
     */
    public function test_a_failing_head_gets_a_slot_even_when_never_failed_heads_fill_the_pass(): void
    {
        $this->behaviour = function (ReactionTarget $t): void {
            if (($t->payload['tag'] ?? null) === 'broken') {
                throw new RequestException(new Response(new GuzzleResponse(500, [], '{"message":"Server Error"}')));
            }
        };
        $this->oweOn('probe-broken', 'broken');
        try {
            $this->queue()->drain(OwedWriteQueue::subjectKey('kanban', '5', 'probe_write', 'probe-broken'));
        } catch (RequestException) {
        }
        $this->assertNotNull(WritebackOwedWrite::query()->sole()->last_failed_at);

        $perPass = OwedWriteRetryJob::MAX_SUBJECTS_PER_PASS;
        for ($i = 0; $i <= $perPass; $i++) {
            $this->oweOn("probe-fresh-{$i}", "fresh-{$i}");
        }
        $this->applied = [];

        try {
            $this->queue()->sweep($perPass);
            $this->fail('the failing head was tried, and its failure still fails the pass');
        } catch (RequestException) {
        }

        $this->assertCount($perPass - 1, $this->applied, 'every other slot went to a never-failed head, oldest first');
        $this->assertSame(array_map(static fn (int $i): string => "fresh-{$i}", range(0, $perPass - 2)), $this->applied);
    }

    /**
     * Two requests owing their subjects' first writes at once both see no instance and both
     * insert; `scheduled_jobs.name` is unique, so the loser's insert is refused. The instance
     * exists — that is a success, not the loud "could not declare" line.
     */
    public function test_losing_the_first_declare_race_is_not_reported_as_an_undeclared_job(): void
    {
        $raced = false;
        DB::listen(function (QueryExecuted $q) use (&$raced): void {
            if ($raced || ! str_contains($q->sql, 'scheduled_jobs') || ! str_contains(strtolower($q->sql), 'limit 1')) {
                return;
            }
            // JobRegistry::insert() has just read the name absent; a concurrent request
            // inserts it before this one's save.
            $raced = true;
            $this->app->make(JobRegistry::class)->insert(OwedWriteWatchdogJob::spec());
        });
        Log::spy();

        $this->owe('a');

        $this->assertTrue($raced, 'the window was exercised');
        $this->assertSame(1, ScheduledJob::query()->where('name', OwedWriteWatchdogJob::INSTANCE)->count());
        Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []): bool => str_ends_with((string) ($context['catalog_id'] ?? ''), '_undeclared'));
        $this->assertSame(['a'], $this->applied);
    }

    public function test_a_failure_that_cannot_be_recorded_does_not_replace_the_handlers_own(): void
    {
        $this->behaviour = fn () => throw new RequestException(new Response(new GuzzleResponse(500, [], '{"message":"Server Error"}')));
        WritebackOwedWrite::saving(static function (WritebackOwedWrite $row): void {
            if ($row->last_failed_at !== null) {
                throw new \RuntimeException('database went away');
            }
        });

        try {
            $this->owe('a');
            $this->fail('the handler failure propagates');
        } catch (RequestException $e) {
            $this->assertSame(500, $e->response->status(), 'the handler\'s own failure, not the stamp\'s');
        } finally {
            WritebackOwedWrite::flushEventListeners();
        }
        $this->assertSame('a', WritebackOwedWrite::query()->sole()->payload['tag']);
    }

    // --- the bounds, held against the constants they are sized from ---

    public function test_the_lock_outlives_the_slowest_handler_and_the_age_bound_outlives_the_retry_schedule(): void
    {
        $promoteWorstCase = KanbanPromoteReleasedHandler::MAX_CANDIDATES
            * (2 * KanbanPromoteReleasedHandler::RUNTIME_GITHUB_TIMEOUT_SECONDS + KanbanHttpClient::TIMEOUT_SECONDS);
        $this->assertGreaterThan($promoteWorstCase, OwedWriteQueue::LEASE_S);

        // sweep() reserves one slot for a failing head; at 1 that slot would be the only one.
        $this->assertGreaterThanOrEqual(2, OwedWriteRetryJob::MAX_SUBJECTS_PER_PASS);

        $schedule = OwedWriteQueue::BASE_BACKOFF_S * (2 ** OwedWriteQueue::MAX_ATTEMPTS);
        $this->assertGreaterThan($schedule, OwedWriteQueue::MAX_AGE_S);
    }

    // --- helpers ---

    private function probe(): Handler
    {
        return new class($this) implements DurableReaction, Handler
        {
            public function __construct(private readonly OwedWriteQueueTest $test) {}

            public function handle(ReactionTarget $target, AgentConfig $agent): void
            {
                $this->test->applyProbe($target);
            }
        };
    }

    /** @internal the probe's body — public only so the anonymous handler can reach it */
    public function applyProbe(ReactionTarget $target): void
    {
        ($this->behaviour)($target);
        $this->applied[] = (string) ($target->payload['tag'] ?? '');
    }

    private function queue(): OwedWriteQueue
    {
        return new OwedWriteQueue($this->handlers, new SubscriptionRegistry($this->dir));
    }

    private function subject(): string
    {
        return OwedWriteQueue::subjectKey('kanban', '5', 'probe_write', self::SUBJECT_TARGET);
    }

    /** Insert one owed write for a fresh event on the subject, and (by default) drain it — what a delivery does. */
    private function owe(string $tag, bool $drain = true): int
    {
        $event = WebhookEvent::query()->create([
            'delivery_id' => 'owed-'.(++$this->deliveries),
            'provider' => 'kanban', 'scope_id' => '5', 'event_type' => 'task.moved', 'payload' => [],
        ]);
        $agent = AgentConfig::load('prod-agent', $this->dir);
        $queue = $this->queue();
        $queue->enqueue($this->subject(), ReactionTarget::make('probe_write', self::SUBJECT_TARGET, payload: ['tag' => $tag]), $agent, $event);
        if ($drain) {
            $queue->drain($this->subject());
        }

        return (int) $event->id;
    }

    /** Insert one owed write on ANOTHER subject (its own target id), without draining it. */
    private function oweOn(string $targetId, string $tag): void
    {
        $event = WebhookEvent::query()->create([
            'delivery_id' => 'owed-'.(++$this->deliveries),
            'provider' => 'kanban', 'scope_id' => '5', 'event_type' => 'task.moved', 'payload' => [],
        ]);
        $this->queue()->enqueue(
            OwedWriteQueue::subjectKey('kanban', '5', 'probe_write', $targetId),
            ReactionTarget::make('probe_write', $targetId, payload: ['tag' => $tag]),
            AgentConfig::load('prod-agent', $this->dir),
            $event,
        );
    }

    /** Owe one write and retry it past every backoff until the queue gives it up. */
    private function exhaust(string $tag): void
    {
        $this->owe($tag);
        for ($i = 1; $i < OwedWriteQueue::MAX_ATTEMPTS; $i++) {
            $this->travel(OwedWriteQueue::BASE_BACKOFF_S * (2 ** $i) + 1)->seconds();
            $this->queue()->drain($this->subject());
        }
        $this->assertSame(0, WritebackOwedWrite::query()->count(), "'{$tag}' was given up");
    }

    private function rateLimitFirst(?string $retryAfter): void
    {
        $refused = false;
        $this->behaviour = function () use (&$refused, $retryAfter): void {
            if (! $refused) {
                $refused = true;
                throw $this->rateLimited($retryAfter);
            }
        };
    }

    private function rateLimited(?string $retryAfter = null): RequestException
    {
        return new RequestException(new Response(new GuzzleResponse(429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter], '{"message":"Too Many Attempts."}')));
    }

    private function jobContext(): JobContext
    {
        return new JobContext(OwedWriteWatchdogJob::INSTANCE, [], null, 900, JobPassSource::Tick);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alertsOfType(string $type): array
    {
        return array_values(array_filter(array_map(
            static fn (array $pair): array => $pair[0]->data(),
            Http::recorded(fn (Request $r) => str_starts_with($r->url(), self::ALERT_URL))->all(),
        ), static fn (array $body): bool => ($body['type'] ?? null) === $type));
    }
}
