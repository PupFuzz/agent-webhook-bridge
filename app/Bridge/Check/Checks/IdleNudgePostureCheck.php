<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\IdleNudge\AgentVerdict;
use App\Bridge\IdleNudge\IdleNudgeConfig;
use App\Bridge\IdleNudge\IdleNudgePassRecord;
use App\Bridge\IdleNudge\IdleNudgeSources;
use App\Bridge\Scheduling\Handlers\IdleNudgeJob;
use App\Bridge\Scheduling\TickPosture;
use App\Bridge\Support\Finding;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SubscriptionRegistry;
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
 *
 * ⚑ THE AGENT YAMLS ARE READ HERE, not taken from the context: this slot runs before the
 * per-agent loop publishes `CheckContext::$configs`. They decide whether any agent needs
 * Mezzanine ({@see IdleNudgeSources}), which is what makes the Mezzanine keys and the token file
 * binding, and supply the DECLARED values a seat-record fault line compares with, or falls back
 * to where the last pass recorded none. A seat record's own state is read off the last pass,
 * never stat-ed here: this process may not be the OS user the tick runs as, and would answer for
 * the wrong one.
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

        try {
            $sources = IdleNudgeSources::of((new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs());
        } catch (Throwable $e) {
            yield Finding::unvalidated('idle_nudge: the agent YAMLs could not be loaded ('.RedactedErrorText::of($e).'), so which agents it judges, and whether any of them needs Mezzanine, is unknown — every pass is unmeasured until they load.');

            return;
        }

        if ($sources->mezzanineNeeded()) {
            if ($cfg->problem !== null) {
                yield Finding::fail('idle_nudge: enabled but MISCONFIGURED — '.$cfg->problem.'. Every Mezzanine-sourced agent is unmeasured and none is nudged.');

                return;
            }

            $tokenPath = (string) $cfg->tokenPath;
            if (! is_file($tokenPath)) {
                yield Finding::fail("idle_nudge: the fleet token file {$tokenPath} is absent or unreachable — every Mezzanine-sourced agent is unmeasured.");
            } elseif (SecretFile::isInsecure($tokenPath)) {
                yield Finding::fail("idle_nudge: the fleet token file {$tokenPath} is group/world-readable — chmod 600; the fleet read refuses it, so every Mezzanine-sourced agent is unmeasured.");
            }
        }

        try {
            $instances = ScheduledJob::query()->where('handler', IdleNudgeJob::NAME)->where('enabled', true)->get();
        } catch (Throwable $e) {
            yield Finding::unvalidated('idle_nudge: could not read the periodic-job registry ('.RedactedErrorText::of($e).') — whether anything runs the nudge is unknown.');

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

        yield from $this->lastPass($sources);
    }

    /** @return iterable<Finding> */
    private function lastPass(IdleNudgeSources $sources): iterable
    {
        try {
            $record = IdleNudgePassRecord::read();
        } catch (Throwable $e) {
            yield Finding::unvalidated('idle_nudge: the last-pass record could not be read ('.RedactedErrorText::of($e).').');

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

        $tally = $this->tally($agents);
        $fleetUnmeasured = is_string($record['fleet_unmeasured'] ?? null) ? $record['fleet_unmeasured'] : null;
        if ($fleetUnmeasured !== null) {
            yield Finding::warn('idle_nudge: the last pass could not read the fleet snapshot — '.$fleetUnmeasured
                .'. No Mezzanine-sourced agent was judged, so the absence of their nudges says nothing about idle seats. ('.$tally.')');
        }

        yield from $this->seatRecordFaults($agents, $record, $sources);

        $routed = array_filter($agents, fn (mixed $code): bool => $code !== 'not_push_routed');
        $measured = array_filter($routed, fn (mixed $code): bool => ! in_array($code, AgentVerdict::UNMEASURED, true));

        if ($routed === []) {
            yield Finding::warn('idle_nudge: no declared agent declares `idle_nudge.seat_record` or sets `channel.route_intents: true`, so no agent is measurable. ('.$tally.')');

            return;
        }
        // ⚑ A DISTINCT CAUSE, NAMED. Every agent unreadable on push time is not "Mezzanine has
        // not shipped yet": the receiver writes the stamp and the tick reads it, in separate
        // processes, so either the column is missing or the receiver does not see the nudge as
        // enabled (a stale cached config, or an env var only the tick's environment carries).
        // Judged over the Mezzanine-sourced agents alone: a seat-record agent never reads a push
        // time, so counting it would hide this cause on any mixed install.
        $mezzanineRouted = array_diff_key($routed, $sources->seatRecords);
        if ($mezzanineRouted !== [] && array_filter($mezzanineRouted, fn (mixed $code): bool => $code !== 'push_time_unreadable') === []) {
            yield Finding::warn('idle_nudge: every push-routed Mezzanine-sourced agent read push_time_unreadable on the last pass ('.$tally
                .'), so no pending work could be aged. Two causes: (a) `php artisan migrate` was not run, so `agent_dispatches.push_attempted_at` does not exist; '
                .'(b) the webhook receiver\'s resolved config does not have the nudge enabled, so it writes no push time — rebuild the config cache (`php artisan config:cache`) and reload PHP-FPM. '
                .'Deliveries made before either is fixed stay unreadable until the seat\'s idle period ends.');

            return;
        }
        // Judged over the same population, for the same reason: a seat-record agent never reads
        // `no_declaring_seat`, and one that measured would otherwise bury this reading in an Ok.
        if ($mezzanineRouted !== [] && array_filter($mezzanineRouted, fn (mixed $code): bool => $code !== 'no_declaring_seat') === []) {
            yield Finding::warn('idle_nudge: every push-routed Mezzanine-sourced agent read no_declaring_seat on the last pass ('.$tally
                .') — the expected reading until Mezzanine seats publish `protocol_agent_name`, so none of them can be nudged yet.');

            return;
        }
        // The fleet line above already said why nothing Mezzanine-side measured.
        if ($measured === [] && $fleetUnmeasured === null) {
            yield Finding::warn('idle_nudge: every push-routed or seat-record agent was UNMEASURED on the last pass ('.$tally.').');

            return;
        }

        if ($failed === [] && $measured !== []) {
            yield Finding::ok('idle_nudge: last pass measured '.count($measured).' of '.count($routed).' push-routed or seat-record agent(s) ('.$tally.')');
        }
    }

    /**
     * One line per seat-record agent whose declared record the last pass could not act on, or
     * which has not moved since its notice (`offer_stale`). Each names the path the TICK read, as
     * the pass recorded it — never one resolved here, where `~` may be another user's home.
     *
     * @param  array<mixed>  $agents
     * @param  array<mixed>  $record
     * @return iterable<Finding>
     */
    private function seatRecordFaults(array $agents, array $record, IdleNudgeSources $sources): iterable
    {
        $read = is_array($record['seat_records'] ?? null) ? $record['seat_records'] : null;
        $compared = is_array($record['record_agents'] ?? null) ? $record['record_agents'] : null;
        foreach ($agents as $agent => $code) {
            if (! in_array($code, AgentVerdict::SEAT_RECORD_FAULTS, true)) {
                continue;
            }
            $agent = (string) $agent;
            $declared = $sources->seatRecords[$agent] ?? null;
            $path = match (true) {
                is_string($read[$agent] ?? null) => $read[$agent],
                $declared === null => '(no longer declared)',
                $read === null => "declared as {$declared} (the last-pass record predates the resolved path, so the path the tick read is not known)",
                default => "declared as {$declared}",
            };
            yield Finding::warn("idle_nudge: {$agent}'s seat record {$path} ".match ($code) {
                'seat_record_home_unresolved' => 'could not be resolved on the last pass: it starts with `~/` and the process the pass ran in has no usable HOME. Write the seat\'s absolute path, or give the tick a HOME.',
                'seat_record_absent' => 'was ABSENT on the last pass. The seat writes it at every turn end only while its `lanes.wake.enabled` is exactly `true`; `~` in the YAML resolves against the home of the process the pass ran in, so a tick running as another OS user needs the seat\'s absolute path.',
                'seat_record_not_visible' => 'could not be looked for on the last pass: a directory above it is not traversable by the OS user the pass ran as. Give that user +x on each directory down to the file (and read on the file).',
                'seat_record_unreadable' => 'was present but not read on the last pass: not readable by the pass\'s OS user, a symbolic link (refused), or past the size bound.',
                'seat_record_malformed' => 'is not a valid schema-v1 offer record (not a JSON object, or a member outside its contract).',
                'seat_record_unknown_version' => 'carries a schema version this build does not read (only `v: 1`) — upgrade the bridge or pin the seat\'s writer.',
                'seat_record_agent_mismatch' => $this->mismatch($agent, $declared, $compared, $sources),
                'seat_record_seat_claimed_twice' => $this->claimedTwice($agent, $agents, $compared),
                'offer_stale' => 'has NOT CHANGED for a whole horizon since its notice was pushed: the notice produced no turn end, or the seat stopped writing the record (its wake switched off, or the Stop hook no longer firing). No further notice is sent for it.',
            }.' This seat is not nudged.');
        }
    }

    /**
     * What the record's `agent` was compared against, as the last pass RECORDED it — the verdict
     * is that pass's, so a YAML edited since must not rewrite its reason — then the remedy for
     * each way the two can disagree. A pass record from before the comparand was recorded falls
     * back to the YAMLs as they are now, and says so.
     *
     * @param  array<mixed>|null  $compared  the pass record's `record_agents`
     */
    private function mismatch(string $agent, ?string $declared, ?array $compared, IdleNudgeSources $sources): string
    {
        $remedy = "set `idle_nudge.seat_agent` to the `agent` value inside it; otherwise point `idle_nudge.seat_record` at the intended seat's record.";
        $then = is_string($compared[$agent] ?? null) ? $compared[$agent] : null;
        if ($declared === null) {
            return $then === null
                ? 'was written for another agent; the agent is no longer declared, so the name it was compared against is not known.'
                : "was written for another agent: its `agent` is not `{$then}`, the name the last pass compared it against. The agent is no longer declared.";
        }
        $now = $sources->recordAgentOf($agent);
        $source = array_key_exists($agent, $sources->seatAgents)
            ? "the value of this YAML's `idle_nudge.seat_agent`"
            : "this YAML's agent name (no `idle_nudge.seat_agent` is set)";
        $ifIntended = array_key_exists($agent, $sources->seatAgents)
            ? "If this file is the intended seat's record, {$remedy}"
            : "If this file is the intended seat's record — the seat's own agent name differs from the bridge agent name — {$remedy}";

        if ($then === null) {
            return 'was written for another agent (the last-pass record predates the recorded comparand, so the name that pass compared against is not known; '
                ."as declared now it is `{$now}`, {$source}). {$ifIntended}";
        }
        if ($then !== $now) {
            return "was written for another agent: its `agent` is not `{$then}`, the name the last pass compared it against. "
                ."This YAML has changed since: the record must now carry `{$now}`, {$source}, which the next pass judges.";
        }

        return "was written for another agent: its `agent` is not `{$then}`, {$source}. {$ifIntended}";
    }

    /**
     * Every agent claiming the same seat as `$agent` on the last pass, from the comparands that
     * pass recorded — the one pass that wrote this verdict wrote `record_agents` beside it.
     *
     * @param  array<mixed>  $agents
     * @param  array<mixed>|null  $compared  the pass record's `record_agents`
     */
    private function claimedTwice(string $agent, array $agents, ?array $compared): string
    {
        $seat = (string) ($compared[$agent] ?? '');
        $claimants = array_keys(array_filter(
            $agents,
            fn (mixed $code, int|string $other): bool => $code === 'seat_record_seat_claimed_twice' && ($compared[$other] ?? null) === $seat,
            ARRAY_FILTER_USE_BOTH,
        ));
        $names = implode(', ', array_map(fn (int|string $a): string => '`'.$a.'`', $claimants));

        return "was not read on the last pass: {$names} each compare their seat record's `agent` against `{$seat}`, "
            .'and one seat\'s record wakes at most one channel, so none of them is sent it. '
            .'Keep that seat\'s `idle_nudge.seat_record` (and `idle_nudge.seat_agent`) in exactly one of those YAMLs and remove it from the others.';
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
