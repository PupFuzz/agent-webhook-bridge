<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobPassResult;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobRefusal;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Standup\StandupGate;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\RecordingJobHandler;
use Tests\TestCase;

/**
 * The pass's DECISIONS (card#8425 / DL-325) — what it runs, what it refuses, what it
 * survives. "The scheduler exists" is not a property worth testing: DL-012 shipped a correct
 * pruner nothing ever invoked, and every guard here is one of the ways that repeats.
 *
 * ⭐ THE FOUR DL-199 PROPERTIES ARE PINNED INDIVIDUALLY, because each is a separate way to
 * re-create the DL-001 latency regression: BOUNDED, INTERVAL-GATED, NON-BLOCKING and
 * NEVER-THROWS-PAST-THE-PASS.
 */
class JobSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private JobHandlerRegistry $handlers;

    private RecordingJobHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'bridge.jobs.enabled' => true,
            'bridge.jobs.min_pass_interval' => 60,
            'bridge.jobs.max_per_pass' => 3,
        ]);

        $this->handler = new RecordingJobHandler;
        $this->handlers = new JobHandlerRegistry([], $this->app->make(StandupGate::class));
        $this->handlers->register($this->handler);
        $this->handlers->register(new RecordingJobHandler('mutating_job', JobCapability::MutatesState));
    }

    private function scheduler(): JobScheduler
    {
        return new JobScheduler($this->handlers);
    }

    private function insert(string $name, string $handler = 'recording_job', int $interval = 600): ScheduledJob
    {
        return (new JobRegistry($this->handlers))->insert(new JobSpec(
            name: $name,
            handler: $handler,
            intervalS: $interval,
            owner: 'suite',
            docsRef: 'docs/periodic-jobs.md',
            justification: 'there is no inbound arrival on this install that creates this work',
        ));
    }

    /** Insert a row the registry itself would refuse, to model a build that lost a handler. */
    private function insertRaw(string $name, string $handler): ScheduledJob
    {
        return ScheduledJob::query()->create([
            'name' => $name,
            'handler' => $handler,
            'interval_s' => 600,
            'owner' => 'suite',
            'docs_ref' => 'docs/periodic-jobs.md',
            'justification' => 'inserted directly to model an instance that outlived its handler',
            'enabled' => true,
        ]);
    }

    public function test_a_due_job_runs_and_the_row_records_the_pass(): void
    {
        $this->insert('a');

        $result = $this->scheduler()->pass(JobPassSource::Manual);

        $this->assertTrue($result->didRun());
        $this->assertSame(1, $result->ok);
        $row = ScheduledJob::query()->where('name', 'a')->firstOrFail();
        $this->assertSame(ScheduledJob::STATUS_OK, $row->last_status);
        $this->assertNotNull($row->last_run_at);
        $this->assertNotNull($row->next_due_at);
        $this->assertSame(0, (int) $row->consecutive_failures);
    }

    public function test_a_job_that_is_not_due_is_left_alone(): void
    {
        $job = $this->insert('a');
        $job->next_due_at = now()->addMinutes(5);
        $job->save();
        Cache::forget('bridge:jobs:last-pass');

        $this->scheduler()->pass(JobPassSource::Manual);

        $this->assertSame([], $this->handler->sources());
    }

    public function test_a_disabled_job_never_runs(): void
    {
        $this->insert('a');
        (new JobRegistry($this->handlers))->setEnabled('a', false);

        $this->scheduler()->pass(JobPassSource::Manual);

        $this->assertSame([], $this->handler->sources());
    }

    /** BOUNDED — a pass touches at most `max_per_pass`, oldest-due first. */
    public function test_a_pass_is_bounded_and_drains_oldest_first(): void
    {
        config(['bridge.jobs.max_per_pass' => 2]);
        foreach (['a', 'b', 'c'] as $i => $name) {
            $job = $this->insert($name);
            $job->next_due_at = now()->subMinutes(10 - $i);   // a oldest, c newest
            $job->save();
        }

        $result = $this->scheduler()->pass(JobPassSource::Manual);

        $this->assertSame(['a', 'b'], $result->names, 'the pass must stop at the bound, and take the oldest-due first');
    }

    /** INTERVAL-GATED — one marker, shared by every ingress. */
    public function test_the_minimum_pass_interval_suppresses_a_second_pass(): void
    {
        $this->insert('a');
        $this->scheduler()->pass(JobPassSource::EventGate);

        $second = $this->scheduler()->pass(JobPassSource::Tick);

        $this->assertFalse($second->didRun());
        $this->assertSame(JobPassResult::SKIP_TOO_SOON, $second->skipped);
        $this->assertSame(['event_gate'], $this->handler->sources());
    }

    /**
     * NON-BLOCKING — the loser of the lock skips INSTANTLY and reports why. A blocking lock
     * here would queue concurrent receives behind a job, which is the DL-001 regression the
     * gate design forbids by name.
     */
    public function test_a_concurrent_pass_that_loses_the_lock_skips_instead_of_waiting(): void
    {
        $this->insert('a');
        $held = Cache::lock('bridge:jobs:lock', 300);
        $this->assertTrue($held->get(), 'the premise: another pass holds the lock');

        $startedAt = hrtime(true);
        $result = $this->scheduler()->pass(JobPassSource::EventGate);
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        $held->release();
        $this->assertFalse($result->didRun());
        $this->assertSame(JobPassResult::SKIP_LOCKED, $result->skipped);
        $this->assertSame([], $this->handler->sources());
        $this->assertLessThan(1000, $elapsedMs, 'the loser must skip instantly, never block');
    }

    /**
     * ⛔ THE DIRECTIVE'S OWN WORDS: an unknown handler is a LOUD REFUSAL, never a silent
     * skip. Loud means the row says so, with a reason a reader can act on.
     */
    public function test_an_instance_whose_handler_no_longer_exists_is_refused_loudly(): void
    {
        $this->insertRaw('orphan', 'handler_from_an_older_build');

        $result = $this->scheduler()->pass(JobPassSource::Manual);

        $this->assertSame(1, $result->refused);
        $row = ScheduledJob::query()->where('name', 'orphan')->firstOrFail();
        $this->assertSame(ScheduledJob::STATUS_REFUSED, $row->last_status);
        $this->assertSame(JobRefusal::UNKNOWN_HANDLER, $row->last_summary);
        $this->assertStringContainsString('handler_from_an_older_build', (string) $row->last_error);
        // NOT a silent skip: it is dated, counted and re-armed for its own interval rather
        // than retried on every delivery.
        $this->assertNotNull($row->last_run_at);
        $this->assertSame(1, (int) $row->consecutive_failures);
    }

    public function test_a_state_mutating_handler_disarmed_after_insert_is_refused_at_run(): void
    {
        // Armed at insert…
        $handlers = new JobHandlerRegistry(['mutating_job'], $this->app->make(StandupGate::class));
        $handlers->register(new RecordingJobHandler('mutating_job', JobCapability::MutatesState));
        (new JobRegistry($handlers))->insert(new JobSpec(
            name: 'mutator',
            handler: 'mutating_job',
            intervalS: 600,
            owner: 'suite',
            docsRef: 'docs/periodic-jobs.md',
            justification: 'inserted while armed, to prove the run-time check is not redundant',
        ));

        // …and DISARMED by the time it runs. This is why the refusal predicate is asked
        // twice: arming is operator config and can be withdrawn after a row exists.
        $result = $this->scheduler()->pass(JobPassSource::Manual);

        $this->assertSame(1, $result->refused);
        $this->assertSame(JobRefusal::UNARMED_MUTATOR, ScheduledJob::query()->where('name', 'mutator')->value('last_summary'));
    }

    public function test_a_throwing_handler_is_recorded_and_does_not_stop_the_other_jobs(): void
    {
        $boom = new RecordingJobHandler('boom_job');
        $boom->throwMessage = 'the upstream said no';
        $this->handlers->register($boom);
        $this->insert('aa-boom', 'boom_job');
        $this->insert('bb-fine');

        $result = $this->scheduler()->pass(JobPassSource::Manual);

        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->ok, 'one handler throwing must not cost the others their pass');
        $row = ScheduledJob::query()->where('name', 'aa-boom')->firstOrFail();
        $this->assertSame(ScheduledJob::STATUS_FAILED, $row->last_status);
        $this->assertSame('the upstream said no', $row->last_error);
        $this->assertSame(1, (int) $row->consecutive_failures);
    }

    public function test_a_clean_pass_clears_a_previous_failure_streak(): void
    {
        $boom = new RecordingJobHandler('boom_job');
        $boom->throwMessage = 'transient';
        $this->handlers->register($boom);
        $this->insert('a', 'boom_job', 60);

        $this->scheduler()->pass(JobPassSource::Manual);
        $this->assertSame(1, (int) ScheduledJob::query()->where('name', 'a')->value('consecutive_failures'));

        $boom->throwMessage = null;
        Cache::forget('bridge:jobs:last-pass');
        $this->travel(2)->minutes();
        $this->scheduler()->pass(JobPassSource::Manual);

        $row = ScheduledJob::query()->where('name', 'a')->firstOrFail();
        $this->assertSame(0, (int) $row->consecutive_failures);
        $this->assertNull($row->last_error);
    }

    /**
     * NEVER THROWS PAST THE PASS. A pass that fails as a WHOLE (not one job's fault) records
     * itself where `bridge:check` reads, instead of leaving only a log line nobody tails —
     * DL-012's silent inertness, which is what this whole subsystem exists not to repeat.
     */
    public function test_a_pass_that_fails_as_a_whole_records_itself_for_the_preflight(): void
    {
        Schema::drop('scheduled_jobs');

        $result = $this->scheduler()->passSafely(JobPassSource::Tick);

        $this->assertFalse($result->didRun());
        $this->assertIsArray(Cache::get(JobScheduler::ERROR_KEY));
    }

    public function test_a_clean_pass_forgets_the_last_pass_failure(): void
    {
        Cache::put(JobScheduler::ERROR_KEY, ['at' => 'then', 'exception' => 'X', 'error' => 'y'], 600);

        $this->scheduler()->passSafely(JobPassSource::Manual);

        $this->assertNull(Cache::get(JobScheduler::ERROR_KEY));
    }

    public function test_the_registry_switch_stops_every_pass(): void
    {
        config(['bridge.jobs.enabled' => false]);
        $this->insert('a');

        $result = $this->scheduler()->pass(JobPassSource::Tick);

        $this->assertSame(JobPassResult::SKIP_DISABLED, $result->skipped);
        $this->assertSame([], $this->handler->sources());
    }
}
