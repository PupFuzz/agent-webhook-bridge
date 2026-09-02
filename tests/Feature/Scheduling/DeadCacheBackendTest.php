<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobSchedulerGate;
use App\Bridge\Scheduling\TickRecord;
use App\Bridge\Scheduling\TickState;
use App\Console\Commands\Bridge\TickCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\DeadBackend;
use Tests\TestCase;

/**
 * ⭐ WHEN THE RECORDER IS THE FAULT (card#8425 / DL-325). The shell's promise is *"NEVER
 * THROWS PAST THE PASS"* and the exit contract on six surfaces says a dead cache backend
 * rides `JobPassResult::passFailed()` — but the catch arm recorded the fault by writing to
 * the CACHE, so with the cache as the fault the arm re-raised its own exception: no log
 * line, no marker, and the throw escaped the shell that exists to contain it.
 *
 * ⛔ WHY NO EXISTING TEST SAW IT. Every other fault test breaks the DATABASE
 * (`Schema::drop`), which leaves the recording surface working, so the arm completes and
 * the promise looks kept. The fault has to be injected INTO THE RECORDER — that is what
 * {@see DeadBackend} is for — and the two escape paths it exposed are the ones an operator
 * actually meets: `bridge:tick` dying at `TickRecord::stamp()` with a stack trace (exit 1
 * by accident, not by contract), and the event ingress ending every delivery with an
 * unhandled fatal in the FPM worker, because `Application::terminate()` has no try/catch
 * around its terminating callbacks.
 *
 * ⚑ A DEAD CACHE IS NOT A HYPOTHETICAL on an install whose cache store is redis: the
 * store is down, or its socket moved, and every DL-199 gate on the box is inside it.
 */
class DeadCacheBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'bridge.jobs.enabled' => true,
            'bridge.jobs.min_pass_interval' => 60,
            'bridge.jobs.max_per_pass' => 3,
        ]);
    }

    /** Swap the CACHE for a backend that throws on every operation. */
    private function killTheCache(): void
    {
        Cache::swap(new DeadBackend('cache'));
    }

    public function test_a_dead_cache_backend_does_not_escape_the_pass(): void
    {
        Log::spy();
        $this->killTheCache();

        $result = $this->app->make(JobScheduler::class)->passSafely(JobPassSource::Tick);

        $this->assertFalse($result->didRun());
        $this->assertTrue($result->passFailed(), 'a dead cache backend is a pass FAULT, not an ordinary skip');
        $this->assertStringContainsString('the pass itself failed', $result->summary());
    }

    /**
     * ⚑ THE ORDER INSIDE THE ARM IS THE FIX, and this is what pins it. The marker write is
     * the leg that cannot work when the cache is the fault, so it goes LAST and guarded;
     * the log line is the only record that survives, and losing it left the operator with
     * a broken registry, no marker, and nothing in the log to correlate.
     */
    public function test_the_fault_is_logged_even_when_the_marker_cannot_be_written(): void
    {
        Log::spy();
        $this->killTheCache();

        $this->app->make(JobScheduler::class)->passSafely(JobPassSource::Tick);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'scheduled job pass failed')
            ->once();
    }

    /**
     * The tick's exit contract, on the fault it names in its own docblock. Reaching an
     * assertion at all is half the point: an escaping throw fails this test instead of
     * being read as the exit 1 the contract promises.
     */
    public function test_the_tick_exits_non_zero_with_a_summary_line_on_a_dead_cache_backend(): void
    {
        Log::spy();
        $this->killTheCache();

        $this->artisan('bridge:tick')
            ->expectsOutputToContain('the pass itself failed')
            ->assertExitCode(TickCommand::FAILURE);
    }

    /**
     * ⚑ TWO LOG LINES FROM ONE `bridge:tick`, AND THE PAIRING IS THE CLAIM.
     * `docs/periodic-jobs.md` tells an operator debugging a dead cache store to read the LOG,
     * because the marker lives in the store that failed — and it tells them to expect a line
     * PER FAILED LEG: the tick stamp and the pass fail separately and each records its own.
     * A single line would mean one of the two legs recorded nothing, which is exactly the
     * silent half this strand exists to end, and nothing pinned the pair.
     */
    public function test_one_dead_cache_tick_logs_the_stamp_and_the_pass_separately(): void
    {
        Log::spy();
        $this->killTheCache();

        $this->artisan('bridge:tick')->assertExitCode(TickCommand::FAILURE);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'the tick stamp could not be written; this tick will read as unmeasured')
            ->once();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'scheduled job pass failed')
            ->once();
    }

    /**
     * ⚑ THE CONTROL FOR THE PAIR ABOVE. On a HEALTHY cache the same tick records neither
     * line — without this, a spy that matched everything (or a message string that had
     * drifted into something always logged) would satisfy the test while measuring nothing.
     */
    public function test_a_healthy_cache_tick_logs_neither_fault_line(): void
    {
        Log::spy();

        $this->artisan('bridge:tick')->assertExitCode(TickCommand::SUCCESS);

        Log::shouldNotHaveReceived('warning', fn (string $m) => $m === 'the tick stamp could not be written; this tick will read as unmeasured');
        Log::shouldNotHaveReceived('warning', fn (string $m) => $m === 'scheduled job pass failed');
    }

    /** The same fault through the operator's hand-run pass — same contract, same exit. */
    public function test_a_hand_run_pass_exits_non_zero_on_a_dead_cache_backend(): void
    {
        Log::spy();
        $this->killTheCache();

        $this->artisan('bridge:jobs', ['action' => 'run'])
            ->expectsOutputToContain('the pass itself failed')
            ->assertExitCode(TickCommand::FAILURE);
    }

    /**
     * ⛔ THE INGRESS WITH NO ONE TO CATCH IT. `Application::terminate()` runs terminating
     * callbacks with no try/catch of its own, so anything escaping the gate's callback is
     * an unhandled fatal AFTER the response — in the FPM worker, on every delivery, for as
     * long as the cache is down.
     */
    public function test_the_event_ingress_terminating_callback_does_not_throw(): void
    {
        Log::spy();
        $this->killTheCache();

        $this->app->make(JobSchedulerGate::class)->schedule();
        $this->app->terminate();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'scheduled job pass failed')
            ->once();
    }

    /**
     * ⚑ THE OTHER RECORDING SURFACE. "Never throws past the pass" is a property of the
     * WHOLE arm, not of the cache write alone: an unwritable `storage/logs` is the same
     * class of fault, and a log call that re-raised would put the throw back on the exact
     * path this shell exists to keep clean. Nothing is recorded in that case, and what
     * carries the fault instead depends on the INGRESS: on this one (`JobPassSource::Tick`)
     * the pass reports it through its RESULT, which is what the tick's exit code reads. The
     * event ingress discards that result, so there a dead log AND a dead cache together are
     * unreported — see `App\Bridge\Support\FaultMarker`'s per-caller split.
     */
    public function test_a_dead_log_backend_does_not_escape_the_pass_either(): void
    {
        $this->killTheCache();
        Log::swap(new DeadBackend('log'));

        $result = $this->app->make(JobScheduler::class)->passSafely(JobPassSource::Tick);

        $this->assertTrue($result->passFailed());
    }

    /**
     * ⭐ DEATH IS THE ALARM, AND A DEAD CACHE IS UNMEASURED — never a stack trace.
     * `TickRecord`'s own docblock says a lost stamp "cannot read as fresh"; a stamp that
     * THREW made `bridge:tick`, `bridge:jobs` and `bridge:check` die before they could say
     * anything at all, which is the one direction the record is designed to avoid.
     */
    public function test_the_tick_record_reads_unmeasured_rather_than_throwing(): void
    {
        Log::spy();
        $this->killTheCache();

        TickRecord::stamp();

        $this->assertNull(TickRecord::lastAt());
        $this->assertSame(TickState::Unmeasured, TickRecord::posture()->state);
    }
}
