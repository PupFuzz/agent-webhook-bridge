<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Check\Checks\JobsPostureCheck;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\TickRecord;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The `jobs.posture` preflight leg (card#8425 / DL-325).
 *
 * ⭐ ITS SILENCE IS THE FIRST THING PINNED, because that is what makes the whole subsystem
 * adoptable without cost: an install with no rows and no declared tick must yield NOTHING.
 * Every golden fixture in this repository asserts the same thing byte-for-byte — the
 * committed captures moved by exactly one number (the registered total) when this check was
 * added, and by no line of content.
 */
class JobsPostureCheckTest extends TestCase
{
    use MaterializesChecks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['bridge.jobs.enabled' => true, 'bridge.jobs.tick_expected_every' => null]);
    }

    /**
     * ⚑ The scheduler's own bookkeeping columns (`last_status`, `last_error`,
     * `consecutive_failures`) are deliberately NOT fillable — {@see JobScheduler} is their
     * one writer — so they are assigned after the create rather than passed to it, where a
     * mass-assign would drop them in silence and this class would then be asserting against
     * a row it never actually built.
     */
    private function row(array $overrides = []): ScheduledJob
    {
        $job = ScheduledJob::query()->create([
            'name' => 'a-job',
            'handler' => 'recording_job',
            'interval_s' => 600,
            'owner' => 'suite',
            'docs_ref' => 'docs/periodic-jobs.md',
            'justification' => 'no arrival on this install can create or gate this work',
            'enabled' => true,
        ]);

        foreach ($overrides as $column => $value) {
            $job->{$column} = $value;
        }
        $job->save();

        return $job;
    }

    /** @param list<Finding> $findings */
    private function messages(array $findings): string
    {
        return implode("\n", array_map(fn ($f): string => $f->message, $findings));
    }

    public function test_an_install_that_adopted_nothing_says_nothing(): void
    {
        $this->assertSame([], $this->findingsOf(new JobsPostureCheck));
    }

    public function test_a_declared_and_fresh_tick_is_reported_as_ok(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();

        $findings = $this->findingsOf(new JobsPostureCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('tick: fresh', $findings[0]->message);
    }

    public function test_a_declared_tick_that_went_silent_is_reported_with_the_hook_to_assert_it(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();
        $this->travel(1500)->seconds();

        $findings = $this->findingsOf(new JobsPostureCheck);

        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('STALE', $findings[0]->message);
        $this->assertStringContainsString('bridge:jobs --assert-tick', $findings[0]->message);
    }

    /**
     * ⛔ A REFUSED INSTANCE IS A `fail`, and the asymmetry against the stale-tick warn is
     * deliberate: a refusal is a job that CANNOT run and will not start by itself.
     */
    public function test_a_refused_instance_fails_the_preflight(): void
    {
        $this->row([
            'last_status' => ScheduledJob::STATUS_REFUSED,
            'last_error' => "no handler named 'gone' exists in this build",
        ]);

        $findings = $this->findingsOf(new JobsPostureCheck);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("instance 'a-job' was REFUSED", $findings[0]->message);
    }

    public function test_a_single_failure_is_not_reported_but_a_streak_is(): void
    {
        $job = $this->row(['last_status' => ScheduledJob::STATUS_FAILED, 'last_error' => 'blip', 'consecutive_failures' => 1]);
        $this->assertSame([], $this->findingsOf(new JobsPostureCheck), 'one failure is a blip, not a posture');

        $job->consecutive_failures = 3;
        $job->save();

        $findings = $this->findingsOf(new JobsPostureCheck);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('failed 3 times in a row', $findings[0]->message);
    }

    public function test_rows_that_nothing_will_ever_run_are_reported(): void
    {
        config(['bridge.jobs.enabled' => false]);
        $this->row();

        $findings = $this->findingsOf(new JobsPostureCheck);

        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('DISABLED', $findings[0]->message);
        $this->assertStringContainsString('NONE of them runs', $findings[0]->message);
    }

    public function test_a_disabled_registry_with_no_rows_is_silent(): void
    {
        config(['bridge.jobs.enabled' => false]);

        $this->assertSame([], $this->findingsOf(new JobsPostureCheck));
    }

    /**
     * ⛔ The silent-misconfiguration hole: a fat-fingered horizon reads as "not adopted"
     * everywhere else, so an operator who thought they armed death-is-the-alarm armed
     * nothing. This leg is the only place they are told.
     */
    public function test_a_declaration_that_cannot_be_read_is_reported_rather_than_read_as_unadopted(): void
    {
        foreach (['ten', true, 0, -5] as $bad) {
            config(['bridge.jobs.tick_expected_every' => $bad]);

            $findings = $this->findingsOf(new JobsPostureCheck);

            $this->assertNotSame([], $findings, 'an unreadable horizon must not be silent');
            $this->assertSame(Severity::Warn, $findings[0]->severity);
            $this->assertStringContainsString('the tick freshness alarm is OFF', $findings[0]->message);
        }

        // The control: a readable declaration produces no such line.
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();
        $this->assertStringNotContainsString('alarm is OFF', $this->messages($this->findingsOf(new JobsPostureCheck)));
    }

    public function test_a_pass_that_failed_as_a_whole_is_surfaced(): void
    {
        Cache::put(JobScheduler::ERROR_KEY, ['at' => '2026-09-01T00:00:00+00:00', 'exception' => 'PDOException', 'error' => 'gone away'], 600);

        $findings = $this->findingsOf(new JobsPostureCheck);

        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('LAST SCHEDULER PASS FAILED', $findings[0]->message);
    }

    public function test_an_unreadable_registry_is_unvalidated_rather_than_an_all_clear(): void
    {
        Schema::drop('scheduled_jobs');

        $findings = $this->findingsOf(new JobsPostureCheck);

        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('could not read the periodic-job registry', $findings[0]->message);
    }
}
