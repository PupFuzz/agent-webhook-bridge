<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\IdleNudge\AgentVerdict;
use App\Bridge\IdleNudge\IdleNudgeConfig;
use App\Bridge\IdleNudge\IdleNudgePassRecord;
use App\Bridge\Scheduling\Handlers\IdleNudgeJob;
use App\Bridge\Scheduling\TickPosture;
use App\Bridge\Support\Finding;
use App\Bridge\Support\SecretFile;
use App\Models\ScheduledJob;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The idle nudge's posture (card#9422 / DL-380): is it configured, is something running it,
 * and did its last pass MEASURE anything.
 *
 * ⛔ THE ABSENCE OF NUDGES MUST BE LEGIBLE. A nudge that never fires looks identical whether no
 * seat was idle, the fleet read was refused, or no seat publishes the name the join needs — and
 * the last is the live state until Mezzanine ships `protocol_agent_name`. So this leg reads the
 * last pass's structured record and says which.
 *
 * ⚑ NO LIVE REQUEST. Preflight never calls Mezzanine: the pass the job already made is the
 * measurement, and its age is judged from the job's own ROW against the instance's own
 * `interval_s` — the one cadence the scheduler itself emits (`docs/periodic-jobs.md`) — with the
 * tick's own grace derivation ({@see TickPosture::graceS()}), not a second formula.
 *
 * ⚑ WHAT IT DELIBERATELY DOES NOT REPEAT. A failure streak on the row and an enabled instance on
 * an install with no adopted tick are `jobs.posture`'s to report.
 */
final class IdleNudgePostureCheck implements Check
{
    public function id(): string
    {
        return 'idle_nudge.posture';
    }

    public function run(CheckContext $ctx): iterable
    {
        $cfg = IdleNudgeConfig::fromConfig();

        if (! $cfg->enabled) {
            yield Silence::because('the idle nudge is off (its default), so this install reads no fleet snapshot and pushes no nudge');

            return;
        }

        if ($cfg->problem !== null) {
            yield Finding::fail('idle_nudge: enabled but MISCONFIGURED — '.$cfg->problem.'. Every pass is unmeasured and nothing is pushed.');

            return;
        }

        $tokenPath = (string) $cfg->tokenPath;
        if (! is_file($tokenPath)) {
            yield Finding::fail("idle_nudge: the fleet token file {$tokenPath} is absent or unreachable — every pass is unmeasured.");
        } elseif (SecretFile::isInsecure($tokenPath)) {
            yield Finding::fail("idle_nudge: the fleet token file {$tokenPath} is group/world-readable — chmod 600; every pass refuses to read it.");
        }

        try {
            $instances = ScheduledJob::query()->where('handler', IdleNudgeJob::NAME)->where('enabled', true)->get();
        } catch (Throwable $e) {
            yield Finding::unvalidated('idle_nudge: could not read the periodic-job registry ('.$e->getMessage().') — whether anything runs the nudge is unknown.');

            return;
        }

        if ($instances->isEmpty()) {
            yield Finding::warn('idle_nudge: enabled, but no ENABLED `'.IdleNudgeJob::NAME.'` job instance exists, so nothing runs it. Insert one with `php artisan bridge:jobs add … --handler='.IdleNudgeJob::NAME.'` (docs/periodic-jobs.md).');

            return;
        }
        if ($instances->count() > 1) {
            yield Finding::fail('idle_nudge: '.$instances->count().' ENABLED `'.IdleNudgeJob::NAME.'` instances ('.$instances->pluck('name')->implode(', ')
                .') — exactly one is supported. Each reads the fleet on its own cadence against one shared dedupe record; disable all but one.');

            return;
        }

        $instance = $instances->first();
        if ($instance->last_run_at === null) {
            yield Finding::warn("idle_nudge: instance '{$instance->name}' has NOT RUN yet, so nothing has been measured.");

            return;
        }

        $ageS = (int) $instance->last_run_at->diffInSeconds(Carbon::now(), true);
        $staleAfterS = $instance->interval_s + TickPosture::graceS($instance->interval_s);
        if ($ageS > $staleAfterS) {
            yield Finding::warn("idle_nudge: instance '{$instance->name}' last ran {$ageS}s ago, past its interval of {$instance->interval_s}s plus a grace of "
                .TickPosture::graceS($instance->interval_s).'s — the result below is old, and idle seats since then have not been looked at.');
        }

        yield from $this->lastPass();
    }

    /** @return iterable<Finding> */
    private function lastPass(): iterable
    {
        try {
            $record = IdleNudgePassRecord::read();
        } catch (Throwable $e) {
            yield Finding::unvalidated('idle_nudge: the last-pass record could not be read ('.$e->getMessage().').');

            return;
        }

        if ($record === null || $record === []) {
            yield Finding::unvalidated('idle_nudge: no readable last-pass record at '.IdleNudgePassRecord::path().' — what the last pass concluded is unknown.');

            return;
        }

        if ($record['measured'] !== true) {
            yield Finding::warn('idle_nudge: the last pass was UNMEASURED — '.(string) ($record['reason'] ?? 'no reason recorded')
                .'. No seat was judged, so the absence of nudges says nothing about idle seats.');

            return;
        }

        $agents = is_array($record['agents'] ?? null) ? $record['agents'] : [];
        $failed = is_array($record['failed_agents'] ?? null) ? $record['failed_agents'] : [];
        if ($failed !== []) {
            yield Finding::warn('idle_nudge: the last pass FAILED to push to '.implode(', ', $failed)
                .' — not retried for that idle period. Check the seat\'s channel server.');
        }

        $routed = array_filter($agents, fn (mixed $code): bool => $code !== 'not_push_routed');
        $measured = array_filter($routed, fn (mixed $code): bool => ! in_array($code, AgentVerdict::UNMEASURED, true));
        $tally = $this->tally($agents);

        if ($routed === []) {
            yield Finding::warn('idle_nudge: no declared agent sets `channel.route_intents: true`, so no agent is measurable — the nudge only acts on intents that were pushed at the seat. ('.$tally.')');

            return;
        }
        if ($measured === []) {
            yield Finding::warn('idle_nudge: every push-routed agent was UNMEASURED on the last pass ('.$tally
                .'). `no_declaring_seat` on every agent is the expected reading until Mezzanine seats publish `protocol_agent_name`.');

            return;
        }

        if ($failed === []) {
            yield Finding::ok('idle_nudge: last pass measured '.count($measured).' of '.count($routed).' push-routed agent(s) ('.$tally.')');
        }
    }

    /** @param  array<mixed>  $agents */
    private function tally(array $agents): string
    {
        $counts = [];
        foreach ($agents as $code) {
            $counts[(string) $code] = ($counts[(string) $code] ?? 0) + 1;
        }
        ksort($counts);

        return implode(', ', array_map(fn (string $code, int $n): string => "{$code} {$n}", array_keys($counts), $counts));
    }
}
