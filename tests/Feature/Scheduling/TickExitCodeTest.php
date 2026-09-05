<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Scheduling\TickRecord;
use App\Bridge\Scheduling\TickState;
use App\Console\Commands\Bridge\TickCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `bridge:tick`'s EXIT CONTRACT (card#8425 / DL-325), which is the only thing a crontab line
 * can read.
 *
 * ⭐ THE DOCBLOCK PROMISED THIS AND THE CODE DID NOT KEEP IT, which is why the file exists.
 * `JobScheduler::passSafely()` catches every `Throwable` by design (a job failure must not
 * fail a webhook), so the `guardDatabase()` the command wrapped its pass in could never be
 * reached and BOTH the no-table and the unreachable-server cases exited 0 — a crontab line
 * whose registry had not run since Tuesday reporting success every ten minutes. Swallowing
 * the THROW is correct; swallowing the FACT is not, so the fact rides the result
 * (`JobPassResult::passFailed()`) and the exit code is decided from it.
 *
 * ⛔ BOTH DIRECTIONS ARE PINNED, and the second is the one that keeps the alarm usable. If
 * an ordinary skip reddened, a busy install's crontab line would mail its operator on most
 * runs — the shared minimum interval is hit on nearly every delivery — and the mail that
 * would have carried a real fault gets filtered within a week.
 */
class TickExitCodeTest extends TestCase
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

    private function insert(string $name): void
    {
        $handlers = $this->app->make(JobHandlerRegistry::class);
        (new JobRegistry($handlers))->insert(new JobSpec(
            name: $name,
            handler: 'standup_digest',
            intervalS: 600,
            owner: 'suite',
            docsRef: 'docs/periodic-jobs.md',
            justification: 'there is no inbound arrival on this install that creates this work',
        ));
    }

    public function test_a_completed_pass_exits_zero(): void
    {
        $this->artisan('bridge:tick')->assertExitCode(TickCommand::SUCCESS);
    }

    /**
     * The measured case. An install that upgraded without `php artisan migrate` has the
     * command but not the table, and this is the shape an operator actually lands in.
     */
    public function test_a_pass_that_could_not_run_at_all_exits_non_zero(): void
    {
        $this->insert('a');
        Schema::drop('scheduled_jobs');

        $this->artisan('bridge:tick')
            ->expectsOutputToContain('the pass itself failed')
            ->assertExitCode(TickCommand::FAILURE);
    }

    /** The other fault: a cadence the install cannot act on, refused rather than clamped. */
    public function test_a_misconfigured_cadence_exits_non_zero(): void
    {
        config(['bridge.jobs.min_pass_interval' => 0]);

        $this->artisan('bridge:tick')
            ->expectsOutputToContain('MISCONFIGURED')
            ->assertExitCode(TickCommand::FAILURE);
    }

    /**
     * ⚑ THE CONTROL FOR THE ARM ABOVE. Every one of these is the scheduler working as
     * designed, and each must stay at 0 or the alarm trains its operator to ignore it.
     */
    public function test_the_ordinary_skips_exit_zero(): void
    {
        $this->artisan('bridge:tick')->assertExitCode(TickCommand::SUCCESS);

        // Inside the shared minimum interval — the common return on any install with traffic.
        $this->artisan('bridge:tick')
            ->expectsOutputToContain('minimum pass interval')
            ->assertExitCode(TickCommand::SUCCESS);

        Cache::flush();
        config(['bridge.jobs.enabled' => false]);
        $this->artisan('bridge:tick')
            ->expectsOutputToContain('BRIDGE_JOBS_ENABLED=false')
            ->assertExitCode(TickCommand::SUCCESS);
    }

    /**
     * ⭐ DEATH IS THE ALARM, so the stamp must survive the fault. A tick that arrived and
     * then found a broken registry is a LIVE clock with a broken registry — two different
     * alarms, and a non-zero exit that also suppressed the stamp would report the live one
     * as dead on the next `--assert-tick`.
     */
    public function test_a_failing_pass_still_stamps_the_tick(): void
    {
        Schema::drop('scheduled_jobs');

        $this->assertSame(TickState::Unmeasured, TickRecord::posture()->state, 'precondition: nothing has ticked');

        $this->artisan('bridge:tick')->assertExitCode(TickCommand::FAILURE);

        $this->assertNotSame(TickState::Unmeasured, TickRecord::posture()->state);
    }
}
