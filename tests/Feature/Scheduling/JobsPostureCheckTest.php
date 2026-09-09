<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Check\Checks\JobsPostureCheck;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\TickAssertRecord;
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

    /**
     * The FULLY WIRED install: a declared horizon, a fresh tick, and something that asserts
     * it. ⚑ The assert record is stamped deliberately — without it this fixture is an install
     * whose alarm nobody reads, and the reader leg warns (see below). Leaving it out would
     * have made this test's `assertCount` the thing that noticed, which is how a fixture ends
     * up asserting the state it was not written about.
     */
    public function test_a_declared_and_fresh_and_asserted_tick_is_reported_as_ok(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();
        TickAssertRecord::stamp();

        $findings = $this->findingsOf(new JobsPostureCheck);

        $this->assertCount(2, $findings);
        $this->assertSame([Severity::Ok, Severity::Ok], array_map(fn (Finding $f): Severity => $f->severity, $findings));
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
     * ⛔ A DECLARED HORIZON WITH NO READER IS REPORTED (card#8425 / DL-351, rt#341) — the leg
     * that stops a dead alarm reading as coverage.
     *
     * ⚠ THE ASSERTIONS ARE ON THE CONTENT, not on the presence of a second finding. A leg
     * asserted only by count or by absence certifies whatever replaces it, and this warn's
     * whole value is that it names WHICH horizon and WHICH state — an operator who is told
     * only *"something is wrong with the tick"* goes and reads the crontab line, which is
     * fine and not the problem.
     *
     * The tick itself is FRESH here, so the only thing that can produce a warn is the reader
     * leg: the fixture rules out borrowing the staleness line's severity.
     */
    public function test_a_declared_horizon_that_nothing_has_ever_asserted_is_reported(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();

        $findings = $this->findingsOf(new JobsPostureCheck);
        $warns = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Warn));

        $this->assertCount(1, $warns, 'a fresh tick nobody asserts must warn exactly once, and about the reader');
        $this->assertStringContainsString('DECLARES a tick every 600s', $warns[0]->message);
        $this->assertStringContainsString('NOTHING HAS EVER ASSERTED IT', $warns[0]->message);
        $this->assertStringContainsString('bridge:jobs --assert-tick', $warns[0]->message);
    }

    /**
     * ⛔ THE CONTROL THAT KEEPS THE LEG ADOPTABLE: declaring nothing is not a defect. An
     * install that never wanted the tick must never be told to wire a hook for one — that is
     * every no-cron install in the fleet, and a line on each of their preflights is how a
     * check earns its way to being ignored.
     */
    public function test_an_install_that_declared_no_horizon_is_never_told_to_wire_a_reader(): void
    {
        // A tick has even been SEEN here — an undeclared install still gets no verdict and
        // therefore no reader demand.
        TickRecord::stamp();

        $this->assertSame([], $this->findingsOf(new JobsPostureCheck));
    }

    /**
     * ⭐ AND THE OTHER CONTROL: once something HAS asserted, the warn is gone and the age is
     * reported. No verdict is claimed on that age — nothing declares how often a seat's hook
     * should fire, and inventing a cadence here would be the fleet-wide constant the horizon
     * itself refuses.
     */
    public function test_an_install_whose_assert_has_run_reports_the_age_and_stops_warning(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();
        TickAssertRecord::stamp();
        $this->travel(120)->seconds();

        $findings = $this->findingsOf(new JobsPostureCheck);

        $this->assertSame([], array_filter($findings, fn (Finding $f): bool => $f->severity !== Severity::Ok));
        $this->assertStringContainsString('the tick horizon has a reader', $this->messages($findings));
        $this->assertStringContainsString('last ran 120s ago', $this->messages($findings));
        $this->assertStringContainsString('no verdict is claimed on it', $this->messages($findings));
    }

    /**
     * ⚑ THE TWO ALARMS ARE INDEPENDENT AXES, and both are reported when both hold: a dead
     * clock and an unwatched horizon have different remedies (fix the crontab line; wire the
     * hook), and suppressing either behind the other costs the operator a round trip.
     */
    public function test_a_dead_clock_and_an_unwatched_horizon_are_two_findings(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();
        $this->travel(5000)->seconds();

        $messages = $this->messages($this->findingsOf(new JobsPostureCheck));

        $this->assertStringContainsString('STALE', $messages);
        $this->assertStringContainsString('NOTHING HAS EVER ASSERTED IT', $messages);
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

    /**
     * ⛔ THE CADENCE TYPO IS A `fail`, and it is the only surface that reports it.
     * `BRIDGE_JOBS_MIN_PASS_INTERVAL=sixty` resolves to 0 through `config/bridge.php`'s
     * `(int)` cast; before this leg the scheduler floored it to a ONE-SECOND cadence and
     * said nothing, so the install ran a registry pass per delivery with no line anywhere
     * saying why. It now refuses, which means nothing periodic runs at all — a broken
     * install, not a transient one.
     */
    public function test_a_cadence_the_install_cannot_act_on_fails_the_preflight(): void
    {
        config(['bridge.jobs.min_pass_interval' => 0]);

        $findings = $this->findingsOf(new JobsPostureCheck);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('MISCONFIGURED', $findings[0]->message);
        $this->assertStringContainsString('min_pass_interval', $findings[0]->message);
    }

    /**
     * ⚑ THE CONTROL. The default cadence must stay silent, or the leg's own headline
     * property — nothing on an install that adopted nothing — is gone.
     */
    public function test_the_default_cadence_says_nothing(): void
    {
        config(['bridge.jobs.min_pass_interval' => 60, 'bridge.jobs.max_per_pass' => 3]);

        $this->assertSame([], $this->findingsOf(new JobsPostureCheck));
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
