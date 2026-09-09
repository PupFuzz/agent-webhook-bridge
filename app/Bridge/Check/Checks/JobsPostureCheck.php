<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobsConfig;
use App\Bridge\Scheduling\TickAssertRecord;
use App\Bridge\Scheduling\TickPosture;
use App\Bridge\Scheduling\TickRecord;
use App\Bridge\Scheduling\TickState;
use App\Bridge\Support\FaultMarker;
use App\Bridge\Support\Finding;
use App\Models\ScheduledJob;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * The periodic-job registry's preflight posture (card#8425 / DL-325) — the tick's liveness,
 * and any instance that is not running.
 *
 * ⭐ IT IS SILENT ON AN INSTALL THAT HAS NOT ADOPTED ANY OF THIS, and the silence is the
 * design rather than an omission. The registry is on by default and empty by default: an
 * install with no rows and no declared tick runs no periodic work, which is the CORRECT
 * state for an install that found an event-driven answer for everything. A green line
 * announcing that would be one more line on every operator's preflight saying nothing.
 *
 * ⭐ DEATH IS THE ALARM, AND ONLY A DECLARATION CAN ARM IT. On an install that adopted the
 * tick, one crontab line drives every periodic job, so the failure mode is silence —
 * exactly how `bridge:prune` sat unscheduled for ~45 days across three installs (DL-012).
 * The alarm is therefore keyed on the OPERATOR'S OWN DECLARATION
 * (`BRIDGE_JOBS_TICK_EXPECTED_EVERY`), never on a fleet-wide constant: only this install
 * knows what its crontab line says, and an install that declared nothing is not failing by
 * not ticking. An ABSENT record reads as UNMEASURED — never as death.
 *
 * ⛔ AND IT REPORTS WHETHER THE ALARM HAS A READER (card#8425 / DL-351, rt#341). Every
 * sentence above is about a horizon an install DECLARED; none of it establishes that anything
 * ever ASKS. An install can declare `BRIDGE_JOBS_TICK_EXPECTED_EVERY`, wire no consumer of
 * `--assert-tick` anywhere, and the four-state evaluator will resolve `stale` perfectly for
 * nobody — a state indistinguishable from having declared no horizon, and worse than it,
 * because the declaration reads as coverage to whoever audits the config. That is the same
 * class as `StandupGate::ERROR_KEY`'s write site with no read site (DL-345) with the ends
 * swapped: there the marker had no reader, here the VERDICT has none.
 * {@see TickAssertRecord} records the assertion's own entry point, and this leg turns *"the
 * operator believes this is watched"* into a checkable claim.
 *
 * ⚑ WHY THE STALE-TICK LINE IS `warn` AND NOT `fail`, stated because the opposite is
 * defensible and was weighed. `fail` flips `bridge:check`'s exit code, and this command
 * gates deployment runbooks; a single missed cron minute would then red a deploy on an
 * install whose receiver is serving perfectly. The LOUD channel for a dead clock is
 * `bridge:jobs --assert-tick`, which exits non-zero and is what a session-start hook runs —
 * asserted at the moment a seat starts work, which is when it matters. This leg is the
 * preflight's disclosure of the same fact.
 *
 * ⛔ A MISCONFIGURED CADENCE IS `fail` TOO, and for the same reason: the scheduler refuses a
 * `min_pass_interval` / `max_per_pass` it cannot act on rather than clamping it to a number
 * nobody asked for, so an install with one runs NO periodic work at all on EITHER ingress
 * until an operator fixes the value. That is a broken install, and this is the only surface
 * that says so at preflight.
 *
 * ⛔ A REFUSED INSTANCE IS `fail`, and that asymmetry is deliberate. A refusal means the row
 * names a handler this build does not have, or names a state-mutating handler this install
 * never armed — a job that CANNOT run, and will not start running by itself. That is a
 * broken install, not a transient one.
 */
final class JobsPostureCheck implements Check
{
    /**
     * How many consecutive failures before a failing instance is worth an operator's
     * attention. One is a blip (a momentary DB or network fault); a third in a row is a
     * pattern, and the row carries the count so the number is checkable rather than felt.
     */
    private const FAILURE_STREAK = 3;

    public function id(): string
    {
        return 'jobs.posture';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $cfg = JobsConfig::fromConfig();

        try {
            $jobs = ScheduledJob::query()->orderBy('name')->get();
        } catch (Throwable $e) {
            yield Finding::unvalidated('jobs: could not read the periodic-job registry ('.$e->getMessage()
                .') — an install that has not run `php artisan migrate` since upgrading is the usual cause. Nothing here is evidence either way.');

            return;
        }

        if (! $cfg->enabled) {
            if ($jobs->isNotEmpty()) {
                // The trap this line exists for: the registry ENUMERATES rows that nothing
                // will ever run. A reader of `bridge:jobs` would see a periodic population;
                // the install has none.
                yield Finding::warn('jobs: the registry is DISABLED (BRIDGE_JOBS_ENABLED=false) but holds '.$jobs->count()
                    .' instance(s) — they are listed by `bridge:jobs` and NONE of them runs, on either ingress.');

                return;
            }

            yield Silence::because('the registry is disabled and holds no instances, so there is no periodic population to report on');

            return;
        }

        // A cadence this install cannot act on stops EVERY pass on BOTH ingresses, and the
        // scheduler refuses rather than clamping (JobsConfig). Without this line the only
        // symptom is a registry that enumerates healthy and never runs — DL-012's silent
        // inertness with a nicer listing. `fail`, not `warn`, and the asymmetry with
        // retention's `warn` is the subject's: a bad retention window leaves the receiver
        // serving and one subsystem idle, whereas this is an install whose entire periodic
        // population is dead and cannot recover by itself.
        // ⚑ IT DOES NOT RETURN. The cadence is one fault; a refused instance and a failed
        // pass are others with their own remedies, and suppressing them here would cost the
        // operator a round trip per fault — fix the cadence, re-run, discover the refusal.
        if (! $cfg->isUsable()) {
            yield Finding::fail('jobs: the registry is enabled but MISCONFIGURED — '.(string) $cfg->problem
                .'. NO pass runs on either ingress until it is fixed (the value is refused, never clamped).');
        }

        yield from $this->tickFindings();
        yield from $this->instanceFindings($jobs);
        yield from $this->passErrorFindings();

        yield Silence::because('the registry is enabled with a usable cadence, no tick horizon is declared (an adopted one always yields its state and its reader posture), and no instance is refused or repeatedly failing');
    }

    /**
     * @return iterable<Finding>
     */
    private function tickFindings(): iterable
    {
        // A declaration that cannot be READ is not the same state as no declaration: it
        // reads as "not adopted" everywhere else, so this is the only place an operator
        // who armed the alarm wrongly finds out they did not arm it.
        $problem = TickRecord::declarationProblem();
        if ($problem !== null) {
            yield Finding::warn('jobs: '.$problem);
        }

        $posture = TickRecord::posture();

        // Not adopted ⇒ NOTHING. The event gate is the default ingress and is complete on
        // its own; an install that never added a crontab line is not missing one.
        if (! $posture->adopted) {
            return;
        }

        if ($posture->state === TickState::Fresh) {
            yield Finding::ok('jobs: '.$posture->summary());
        } else {
            yield Finding::warn('jobs: '.$posture->summary().' Assert this from a session-start hook with `php artisan bridge:jobs --assert-tick`.');
        }

        yield from $this->tickReaderFindings($posture);
    }

    /**
     * ⛔ IS ANYTHING ACTUALLY READING THE ALARM? A declared horizon with no reader is
     * indistinguishable from no horizon at all, and it is WORSE than that: the declaration
     * reads as coverage to whoever audits the config, so the operator believes the clock is
     * watched (rt#341, sola-pm's blocking ask). Everything above computes the verdict
     * correctly; this leg is the only thing that reports whether anyone ever asks for it.
     *
     * ⚠ THE TWO ABSENCES ARE NOT COLLAPSED. *No record of an assert ever* and *asserted, and
     * here is how long ago* are different states with different remedies, so they are
     * different findings at different severities. {@see TickAssertRecord} does not expire for
     * the same reason.
     *
     * ⚑ NO VERDICT IS CLAIMED ON THE AGE. Nothing declares how often a seat's hook should
     * fire — the tick horizon is the operator's declaration precisely because only the
     * install knows its own cadence, and there is no equivalent declaration for the hook. So
     * the age is reported and judged by the reader, the same cut
     * {@see FaultMarker::lastFault()} makes over its own `at`.
     *
     * @return iterable<Finding>
     */
    private function tickReaderFindings(TickPosture $posture): iterable
    {
        $assertedAgeS = TickAssertRecord::ageS();

        if ($assertedAgeS === null) {
            yield Finding::warn('jobs: this install DECLARES a tick every '.$posture->expectedEveryS
                .'s and NOTHING HAS EVER ASSERTED IT — the bridge holds no record of `php artisan bridge:jobs --assert-tick`'
                .' being run here, so the freshness verdict above is computed for nobody and a dead crontab line would be'
                .' reported to no one. Run it from a session-start hook (or any periodic runbook step) so the declaration'
                .' is a checked claim rather than a belief. A cleared or unreadable cache store also reads this way —'
                .' this never reads as watched when it is not.');

            return;
        }

        yield Finding::ok('jobs: the tick horizon has a reader — `bridge:jobs --assert-tick` last ran '.$assertedAgeS
            .'s ago. Nothing declares how often it should run, so that age is reported and no verdict is claimed on it.');
    }

    /**
     * @param  Collection<int, ScheduledJob>  $jobs
     * @return iterable<Finding>
     */
    private function instanceFindings($jobs): iterable
    {
        foreach ($jobs as $job) {
            if ($job->last_status === ScheduledJob::STATUS_REFUSED) {
                yield Finding::fail("jobs: instance '".(string) $job->name."' was REFUSED and nothing ran — "
                    .(string) $job->last_error);

                continue;
            }

            if ($job->last_status === ScheduledJob::STATUS_FAILED
                && (int) $job->consecutive_failures >= self::FAILURE_STREAK) {
                yield Finding::warn("jobs: instance '".(string) $job->name."' has failed "
                    .(int) $job->consecutive_failures.' times in a row (owner: '.(string) $job->owner.'; docs: '
                    .(string) $job->docs_ref.') — last error: '.(string) $job->last_error);
            }
        }
    }

    /**
     * A pass that threw as a WHOLE — not one job's fault. Without this the registry can
     * enumerate as healthy while nothing in it has run since Tuesday, which is DL-012's
     * silent inertness with a nicer listing. The catch stays local: `CheckRunner`
     * deliberately does not isolate, so an unreachable cache backend must degrade this leg
     * rather than abort `bridge:check`.
     *
     * @return iterable<Finding>
     */
    private function passErrorFindings(): iterable
    {
        try {
            // Rendered by the class that WRITES the marker ({@see FaultMarker::lastFault()},
            // card#8683); the sentence below is this leg's own.
            $lastError = FaultMarker::lastFault(JobScheduler::ERROR_KEY);
            if ($lastError !== null) {
                yield Finding::warn('jobs: the LAST SCHEDULER PASS FAILED as a whole and no instance has run since ('
                    .$lastError.'). The marker clears itself on the next clean pass.');
            }
        } catch (Throwable $e) {
            yield Finding::unvalidated('jobs: could not read the last-pass-failure marker ('.$e->getMessage()
                .') — the cache backend the scheduler depends on may be unreachable.');
        }
    }
}
