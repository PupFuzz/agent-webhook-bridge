<?php

namespace Tests\Feature\Retention;

use App\Bridge\Retention\RetentionGate;
use App\Bridge\Support\BridgePaths;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the DL-199 gate's DECISIONS — when it fires, when it refuses, and what it
 * does when the world is hostile. "It is registered" is not a property worth
 * testing: DL-012 shipped a correct pruner that nothing ever invoked, and this
 * gate exists because of that. What matters is whether it RUNS, and whether it
 * can be made to run when it should not.
 */
class RetentionGateTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/retention-gate-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.retention.enabled' => true,
            'bridge.retention.interval' => 86400,
            'bridge.retention.older_than' => '30d',
            'bridge.retention.null_payloads_older_than' => '',
            'bridge.retention.batch' => 500,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function agedEvent(int $daysAgo, string $suffix = ''): WebhookEvent
    {
        $e = WebhookEvent::create([
            'provider' => 'kanban',
            'scope_id' => '5',
            'delivery_id' => 'd-'.$daysAgo.'-'.$suffix.'-'.uniqid(),
            'event_type' => 'task.moved',
            'payload' => ['a' => 1],
        ]);
        // received_at is deliberately NOT fillable (the column is DB-defaulted), so
        // it has to be assigned after the create — passing it to create() is silently
        // dropped and leaves a "40-day-old" row stamped now.
        $e->received_at = now()->subDays($daysAgo);
        $e->save();

        return $e;
    }

    /**
     * Run ONE gate pass the way a real request does: schedule, then terminate.
     *
     * The reset is load-bearing for multi-fire tests: `Application::terminate()` runs
     * every registered terminating callback and never clears them, so a second fire()
     * would re-run the first one too (3 fires ⇒ 6 passes) and the test would silently
     * stop modelling "N successive deliveries". Under PHP-FPM each delivery is a fresh
     * process, so one callback per fire IS the real shape.
     */
    private function fire(): void
    {
        $this->app->make(RetentionGate::class)->schedule();
        $this->app->terminate();

        $prop = new \ReflectionProperty($this->app, 'terminatingCallbacks');
        $prop->setValue($this->app, []);
    }

    public function test_a_due_gate_prunes_aged_events(): void
    {
        $old = $this->agedEvent(40);
        $fresh = $this->agedEvent(1);

        $this->fire();

        $this->assertDatabaseMissing('webhook_events', ['id' => $old->id]);
        $this->assertDatabaseHas('webhook_events', ['id' => $fresh->id]);
    }

    public function test_the_interval_marker_suppresses_a_second_pass(): void
    {
        $old = $this->agedEvent(40);
        Cache::put('bridge:retention:last-run', true, 86400);

        $this->fire();

        $this->assertDatabaseHas('webhook_events', ['id' => $old->id]);
    }

    public function test_a_drained_pass_arms_the_interval(): void
    {
        $this->agedEvent(40);

        $this->fire();

        // Fewer rows than the batch ⇒ the store is drained ⇒ the marker stands, so
        // the next delivery pays nothing until the interval lapses.
        $this->assertTrue(Cache::has('bridge:retention:last-run'));
    }

    public function test_a_full_batch_clears_the_marker_so_the_next_delivery_keeps_draining(): void
    {
        // The batch-filled case is the whole reason a 20k backlog drains in hours
        // instead of one batch per day. If this regresses, retention still "works"
        // and a large install still never catches up — silently.
        config(['bridge.retention.batch' => 2]);
        $this->agedEvent(40, 'a');
        $this->agedEvent(41, 'b');
        $this->agedEvent(42, 'c');

        $this->fire();

        $this->assertSame(1, WebhookEvent::count());               // bounded: 2 of 3 went
        $this->assertFalse(Cache::has('bridge:retention:last-run'));

        $this->fire();                                              // next delivery continues

        $this->assertSame(0, WebhookEvent::count());
        $this->assertTrue(Cache::has('bridge:retention:last-run')); // drained ⇒ re-armed
    }

    public function test_the_batch_deletes_the_oldest_first(): void
    {
        config(['bridge.retention.batch' => 1]);
        $oldest = $this->agedEvent(50, 'oldest');
        $newer = $this->agedEvent(40, 'newer');

        $this->fire();

        $this->assertDatabaseMissing('webhook_events', ['id' => $oldest->id]);
        $this->assertDatabaseHas('webhook_events', ['id' => $newer->id]);
    }

    public function test_a_held_lock_makes_the_loser_skip_instead_of_queueing(): void
    {
        // DL-199 calls a BLOCKING lock forbidden: it would queue every concurrent
        // receive behind the pruner — the exact DL-001 latency regression.
        //
        // Asserting only "the row survived" does NOT pin that: swapping `->get()` for
        // `->block(5, ...)` also leaves the row alone (it waits, throws
        // LockTimeoutException, and runSafely swallows it), so the test passes 5s
        // slower and says nothing. Verified by mutation. The observable difference is
        // the WARNING: blocking-then-timing-out logs 'retention pass failed'; an
        // instant skip logs nothing at all. Assert on that, not on wall-clock (flaky).
        $old = $this->agedEvent(40);
        $held = Cache::lock('bridge:retention:lock', 300);
        $this->assertTrue($held->get());
        Log::spy();

        try {
            $this->fire();

            $this->assertDatabaseHas('webhook_events', ['id' => $old->id]);
            Log::shouldNotHaveReceived('warning');
        } finally {
            $held->release();
        }
    }

    public function test_disabled_retention_prunes_nothing(): void
    {
        config(['bridge.retention.enabled' => false]);
        $old = $this->agedEvent(40);

        $this->fire();

        $this->assertDatabaseHas('webhook_events', ['id' => $old->id]);
    }

    /**
     * Force a real prune failure, driver-independently and WITHOUT DDL: a directory
     * where {@see BridgePaths::filterJsonlLocked} expects a file
     * makes its `fopen()` fail, so the inbox leg throws (after the DB leg deletes).
     *
     * NOT `Schema::drop('webhook_events')`: on MariaDB that FK-errors (agent_dispatches
     * references it) AND, being DDL, implicitly commits the RefreshDatabase transaction
     * — dropping the table for every later test and leaking this test's rows into them.
     * A filesystem failure has neither hazard and behaves identically on SQLite/MariaDB.
     */
    private function forcePruneToThrow(): void
    {
        File::makeDirectory($this->dir.'/state/inbox.jsonl');
    }

    public function test_a_failing_prune_never_escapes_the_callback(): void
    {
        // An escaping throw would surface as a fatal after the response, in the one
        // process nobody watches — and retention is never worth failing a webhook
        // over: a 5xx makes the provider redeliver, compounding whatever broke. Use a
        // real failure (a broken inbox path), not a double — RetentionService is final.
        $this->agedEvent(40);
        $this->forcePruneToThrow();
        Log::spy();

        $this->fire();   // must not throw

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'retention pass failed')
            ->once();
    }

    public function test_a_failing_prune_records_a_last_error_marker_for_the_preflight(): void
    {
        // A caught throw that left ONLY a log line would rebuild DL-012: the marker-
        // before-prune back-off means the pass runs at most once per interval, so a
        // persistent failure would drain nothing forever while bridge:check still read
        // "retention: on". The durable marker is what lets the preflight see the stuck
        // state. Without this, a check that fails cannot be distinguished from one that
        // never ran.
        $this->agedEvent(40);
        $this->forcePruneToThrow();

        $this->fire();

        $marker = Cache::get(RetentionGate::ERROR_KEY);
        $this->assertIsArray($marker, 'a thrown pass must record a last-error marker');
        $this->assertArrayHasKey('exception', $marker);
        $this->assertArrayHasKey('error', $marker);
    }

    public function test_a_successful_pass_clears_a_stale_last_error_marker(): void
    {
        // The marker must self-heal: once retention runs cleanly again, the preflight
        // must stop warning. A marker that only ever got set would strand a false alarm.
        Cache::put(RetentionGate::ERROR_KEY, ['at' => 'earlier', 'exception' => 'X', 'error' => 'stale'], 3600);
        $old = $this->agedEvent(40);

        $this->fire();

        $this->assertDatabaseMissing('webhook_events', ['id' => $old->id]);   // it really pruned
        $this->assertFalse(Cache::has(RetentionGate::ERROR_KEY), 'a clean pass must clear the stale failure marker');
    }

    public function test_an_interval_suppressed_pass_does_not_clear_a_standing_failure(): void
    {
        // If a prior pass FAILED it set both the interval marker (back-off) and the
        // error marker. A within-interval delivery returns early WITHOUT pruning — it
        // must NOT erase the failure signal, or the preflight would go quiet while
        // retention is still stuck (nothing has actually pruned).
        Cache::put('bridge:retention:last-run', true, 86400);
        Cache::put(RetentionGate::ERROR_KEY, ['at' => 'earlier', 'exception' => 'X', 'error' => 'stuck'], 86400);
        $old = $this->agedEvent(40);

        $this->fire();

        $this->assertDatabaseHas('webhook_events', ['id' => $old->id]);   // suppressed: no prune
        $this->assertTrue(Cache::has(RetentionGate::ERROR_KEY), 'an early return must leave a standing failure marker in place');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function misconfiguredCases(): array
    {
        return [
            'window is not a number' => [['bridge.retention.older_than' => 'lots']],
            // `BRIDGE_RETENTION_OLDER_THAN=true` in .env reaches config as a BOOL, and
            // (string) true === '1' — a VALID one-day window. Uncaught, a typo silently
            // deletes 29 days more than asked and the preflight calls it healthy. A bool
            // is plausible here precisely because retention.enabled IS one.
            'window is a bare true (env bool, would parse as 1 DAY)' => [['bridge.retention.older_than' => true]],
            'window is a bare false' => [['bridge.retention.older_than' => false]],
            'batch past the bound (a 20-digit typo saturates to PHP_INT_MAX)' => [['bridge.retention.batch' => PHP_INT_MAX]],
            'window is zero days' => [['bridge.retention.older_than' => '0d']],
            'window is past the 100y cap' => [['bridge.retention.older_than' => '36501d']],
            'window is negative' => [['bridge.retention.older_than' => '-5']],
            'both windows empty' => [['bridge.retention.older_than' => '']],
            'interval is zero' => [['bridge.retention.interval' => 0]],
            'batch is zero' => [['bridge.retention.batch' => 0]],
        ];
    }

    /**
     * A bad window must prune NOTHING. The tempting failure is to fall back to the
     * default cutoff, which would delete on a typo — the one direction this
     * destructive path must never fail.
     *
     * @param  array<string, mixed>  $cfg
     */
    #[DataProvider('misconfiguredCases')]
    public function test_a_misconfigured_gate_prunes_nothing_and_warns(array $cfg): void
    {
        config($cfg);
        $old = $this->agedEvent(40);
        Log::spy();

        $this->fire();

        $this->assertDatabaseHas('webhook_events', ['id' => $old->id]);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'retention is enabled but misconfigured; nothing pruned')
            ->once();
    }

    public function test_a_misconfigured_gate_backs_off_instead_of_warning_per_delivery(): void
    {
        config(['bridge.retention.older_than' => 'lots']);
        Log::spy();

        $this->fire();
        $this->fire();
        $this->fire();

        // Three deliveries, one warning: this runs per webhook, so a config typo
        // must not turn every delivery into a log line.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'retention is enabled but misconfigured; nothing pruned')
            ->once();
    }

    public function test_the_null_payloads_leg_runs_only_when_configured(): void
    {
        config([
            'bridge.retention.older_than' => '',
            'bridge.retention.null_payloads_older_than' => '7d',
        ]);
        $aged = $this->agedEvent(10);

        $this->fire();

        // The row SURVIVES with its payload shed — that distinction is the leg's
        // entire point (the row is the dedup gate).
        $this->assertDatabaseHas('webhook_events', ['id' => $aged->id]);
        $this->assertNull($aged->fresh()->payload);
    }

    public function test_a_pass_reports_what_it_did(): void
    {
        // DL-012's failure was invisible: nothing said "I pruned 0, forever". A pass
        // that leaves no trace cannot be caught doing nothing.
        Log::spy();
        $this->agedEvent(40);

        $this->fire();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'retention pass' && $c['events_deleted'] === 1)
            ->once();
    }
}
