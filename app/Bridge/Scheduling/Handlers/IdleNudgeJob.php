<?php

namespace App\Bridge\Scheduling\Handlers;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\IdleNudge\Evaluation;
use App\Bridge\IdleNudge\FleetSnapshot;
use App\Bridge\IdleNudge\FleetSnapshotReader;
use App\Bridge\IdleNudge\IdleNudgeConfig;
use App\Bridge\IdleNudge\IdleNudgeEvaluator;
use App\Bridge\IdleNudge\IdleNudgePassRecord;
use App\Bridge\IdleNudge\IdleNudgeSources;
use App\Bridge\IdleNudge\IdleNudgeState;
use App\Bridge\IdleNudge\IdleNudgeUnmeasured;
use App\Bridge\IdleNudge\InboxUnreadable;
use App\Bridge\IdleNudge\NudgePlan;
use App\Bridge\IdleNudge\PushTimeUnreadable;
use App\Bridge\IdleNudge\SeatOfferPlan;
use App\Bridge\IdleNudge\SeatRecordPath;
use App\Bridge\IdleNudge\SeatRecordReader;
use App\Bridge\IdleNudge\SeatRecordUnmeasured;
use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Support\AuthoredIntentPush;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\DbClock;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The idle-with-pending-work nudge (card#9422 / DL-380) — the watchdog DL-325 Decision 9
 * recorded as a binding contract and could not build for want of a seat-state record.
 * Mezzanine's fleet snapshot is that record.
 *
 * ⭐ WHY IT IS A JOB. An idle seat makes no webhook traffic, so the after-response gate cannot
 * see it; the seat-state record lives in another system this bridge receives no events from.
 * `docs/periodic-jobs.md`'s decision order ends at step 4.
 *
 * ⚑ {@see JobCapability::ReadAndAlert}. It reads the snapshot and the inbox and pushes at most
 * one nudge per idle period at a seat's own channel. It moves no card and changes no seat.
 *
 * ⛔ TWO FAILURE CHANNELS, ONE PER KIND OF FACT. A pass that could not MEASURE throws
 * ({@see IdleNudgeUnmeasured}), so the row builds a failure streak `bridge:check` warns on. A
 * pass that measured returns ok — including a Mezzanine install's live reality, where no seat
 * publishes `protocol_agent_name` and every agent reads `no_declaring_seat` — and a push that
 * threw is recorded per agent in {@see IdleNudgePassRecord}, which the check leg reads.
 *
 * ⭐ A SEAT-RECORD AGENT IS NEVER HOSTAGE TO MEZZANINE (rt#562). Its own offer record is read
 * and judged first, before the Mezzanine-sourced half runs at all; a needed fleet read that did
 * not measure — or anything on that half that threw — turns only the Mezzanine-sourced agents
 * into `fleet_unmeasured`, and the pass still throws at the end — after its record is written —
 * so the row keeps the failure streak it had.
 */
final class IdleNudgeJob implements JobHandler
{
    public const NAME = 'idle_nudge';

    public const KIND = 'seat_idle_nudge';

    public function __construct(private readonly HandlerRegistry $handlers) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): JobCapability
    {
        return JobCapability::ReadAndAlert;
    }

    public function run(JobContext $ctx): JobOutcome
    {
        $cfg = IdleNudgeConfig::fromConfig();
        if (! $cfg->enabled) {
            return JobOutcome::ok('idle nudge is OFF for this install (BRIDGE_IDLE_NUDGE_ENABLED=false) — nothing read, nothing pushed');
        }

        try {
            return $this->measuredPass($cfg);
        } catch (Throwable $e) {
            // Every throw, not only the named ones: a pass that died on anything else would
            // otherwise leave the PREVIOUS pass's record standing, and `bridge:check` would
            // report that verdict as this install's current state. Only the class is recorded
            // for an unnamed throw — its message is not bridge vocabulary.
            if (! ($e instanceof IdleNudgeUnmeasured && $e->passRecorded)) {
                $this->recordUnmeasured($e instanceof IdleNudgeUnmeasured
                    ? $e->reason
                    : 'the pass threw '.$e::class.' before it finished — see the job row\'s last_error');
            }

            throw $e;
        }
    }

    private function recordUnmeasured(string $reason): void
    {
        try {
            IdleNudgePassRecord::unmeasured($reason);
        } catch (Throwable $recordFault) {
            // The pass's own fault is what the row must carry; a record that could not be
            // written must not replace it.
            Log::warning('idle nudge: the last-pass record could not be written', ['exception' => $recordFault::class]);
        }
    }

    private function measuredPass(IdleNudgeConfig $cfg): JobOutcome
    {
        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (Throwable) {
            throw new IdleNudgeUnmeasured('the declared agent YAMLs could not be loaded, so which agents exist is unknown — run bridge:check');
        }
        $sources = IdleNudgeSources::of($configs);

        // Loaded BEFORE any read: a state file this pass cannot parse costs no request, and is
        // never written over.
        $state = IdleNudgeState::load();
        $evaluator = new IdleNudgeEvaluator;

        // Judged BEFORE anything Mezzanine-side runs, so nothing that half throws can cost a
        // seat-record agent its verdict.
        $nowMs = Carbon::now()->getTimestampMs();
        $seatVerdicts = [];
        $seatRecordPaths = [];
        $reader = new SeatRecordReader;
        foreach ($sources->seatRecords as $agent => $declared) {
            $agent = (string) $agent;
            $seatRecordPaths[$agent] = null;
            try {
                $seatRecordPaths[$agent] = SeatRecordPath::resolve($declared);
                $offer = $reader->read($seatRecordPaths[$agent], $agent);
            } catch (SeatRecordUnmeasured $e) {
                $offer = $e->verdict;
            }
            $seatVerdicts[] = $evaluator->seatRecord($agent, $offer, $state->slotOf($agent), $nowMs);
        }

        [$evaluation, $fleetUnmeasured] = $this->mezzanine($cfg, $sources, $state, $evaluator);
        $evaluation = $evaluation->with($seatVerdicts);

        $accepted = 0;
        $failed = [];
        $pusher = new AuthoredIntentPush($this->handlers);
        foreach ($evaluation->plans() as $plan) {
            if ($plan instanceof SeatOfferPlan) {
                $state->markOfferAndSave($plan);
            } else {
                $state->markAndSave($plan->agent, $plan->idleSinceMs);
            }
            try {
                $pusher->send($plan instanceof SeatOfferPlan ? $this->offerIntent($plan) : $this->intent($plan), $plan->agent);
                $accepted++;
            } catch (Throwable $e) {
                $failed[] = $plan->agent;
                Log::warning('idle nudge: the push to this agent failed; it is NOT retried for this idle period', [
                    'agent' => $plan->agent,
                    'exception' => $e::class,
                ]);
            }
        }

        $state->forgetUndeclared($sources->declared());
        IdleNudgePassRecord::measured($evaluation, $accepted, $failed, $fleetUnmeasured, $seatRecordPaths);

        Log::info('idle nudge pass', [
            'seats' => $evaluation->seatTally,
            'verdicts' => $evaluation->verdictTally(),
            'pushes_accepted_by_transport' => $accepted,
            'pushes_failed' => $failed,
        ]);

        if ($fleetUnmeasured !== null) {
            throw new IdleNudgeUnmeasured($fleetUnmeasured, passRecorded: true);
        }

        return JobOutcome::ok($this->summary($evaluation, $accepted, count($failed)));
    }

    /**
     * The Mezzanine-sourced agents' verdicts, with the reason a fleet read that was needed did
     * not measure (null when it measured, or nothing needed it).
     *
     * ⛔ NOTHING ON THIS HALF ESCAPES IT. A throw anywhere in it — the read, the database clock,
     * the evaluation — turns every push-routed Mezzanine-sourced agent `fleet_unmeasured` and
     * names the throw's class, so the seat-record agents are still pushed and recorded.
     *
     * @return array{0: Evaluation, 1: ?string}
     */
    private function mezzanine(IdleNudgeConfig $cfg, IdleNudgeSources $sources, IdleNudgeState $state, IdleNudgeEvaluator $evaluator): array
    {
        $judge = fn (?FleetSnapshot $snapshot): Evaluation => $evaluator->evaluate(
            $snapshot,
            (string) $cfg->install,
            $sources->mezzanine,
            $state->nudged(),
            $this->unseenLines(...),
            $this->pushTimes(...),
            $snapshot === null ? 0.0 : (float) DbClock::now()->format('U.u'),
            $cfg->defaultAfterS,
        );

        if (! $sources->mezzanineNeeded()) {
            return [$judge(null), null];
        }
        if ($cfg->problem !== null) {
            return [$judge(null), 'misconfigured — '.$cfg->problem];
        }
        try {
            return [$judge((new FleetSnapshotReader)->read($cfg)), null];
        } catch (IdleNudgeUnmeasured $e) {
            return [$judge(null), $e->reason];
        } catch (Throwable $e) {
            Log::warning('idle nudge: the Mezzanine-sourced half of the pass threw; its agents read fleet_unmeasured', ['exception' => $e::class]);

            return [$judge(null), 'the Mezzanine-sourced half of the pass threw '.$e::class.' before it finished — see the log'];
        }
    }

    /**
     * @return list<array<mixed>>
     *
     * @throws InboxUnreadable
     */
    private function unseenLines(string $agent): array
    {
        try {
            // `readJsonl` answers `[]` for a file `is_file()` cannot see, and it cannot see
            // anything under a directory this process may not traverse — so an unreadable
            // state dir would read as an empty inbox. Asked first, so it cannot.
            $dir = BridgePaths::stateDir();
            if (! is_dir($dir) || ! is_readable($dir) || ! is_executable($dir)) {
                throw new InboxUnreadable("state dir {$dir} is not a readable, traversable directory");
            }

            return BridgePaths::unseenInboxLines($agent);
        } catch (UnreadableFileException $e) {
            throw new InboxUnreadable($e->getMessage(), previous: $e);
        }
    }

    /**
     * Each line's last push time, from the dispatch that staged it, joined through the delivery id
     * the line id embeds (`IntentLog`: `<delivery_id>:<agent>:<index>`). Three answers:
     *  - a float — `agent_dispatches.push_attempted_at`, epoch seconds on the DB clock;
     *  - `false` — no stamp AND `processed_at` is NULL: the dispatch never completed (a durable
     *    handler threw after staging, or staging failed partway), so no push reached the line and
     *    it is aged from its own `ts`;
     *  - `null` — unreadable: the id does not parse, the dispatch row is gone, or the dispatch
     *    COMPLETED with no stamp (a row from before DL-380's migration, a stamp write that failed,
     *    or a delivery while the nudge was disabled).
     *
     * @param  list<string>  $lineIds
     * @return array<string, float|false|null>
     *
     * @throws PushTimeUnreadable
     */
    private function pushTimes(string $agent, array $lineIds): array
    {
        $deliveryOf = [];
        foreach ($lineIds as $id) {
            $parts = explode(':', $id);
            $index = array_pop($parts);
            $owner = array_pop($parts);
            $delivery = implode(':', $parts);
            $deliveryOf[$id] = ($owner === $agent && $delivery !== '' && ctype_digit((string) $index)) ? $delivery : null;
        }

        $stamps = [];
        try {
            foreach (array_chunk(array_values(array_unique(array_filter($deliveryOf))), 500) as $chunk) {
                $rows = DB::table('agent_dispatches')
                    ->join('webhook_events', 'webhook_events.id', '=', 'agent_dispatches.webhook_event_id')
                    ->where('agent_dispatches.agent_name', $agent)
                    ->whereIn('webhook_events.delivery_id', $chunk)
                    ->get(['webhook_events.delivery_id', 'agent_dispatches.push_attempted_at', 'agent_dispatches.processed_at']);
                foreach ($rows as $row) {
                    $stamps[(string) $row->delivery_id] = match (true) {
                        is_string($row->push_attempted_at) && $row->push_attempted_at !== '' => (float) CarbonImmutable::parse($row->push_attempted_at, 'UTC')->format('U.u'),
                        $row->processed_at === null => false,
                        default => null,
                    };
                }
            }
        } catch (Throwable $e) {
            throw new PushTimeUnreadable('the dispatch ledger could not be read', previous: $e);
        }

        $out = [];
        foreach ($deliveryOf as $id => $delivery) {
            $out[$id] = $delivery === null || ! array_key_exists($delivery, $stamps) ? null : $stamps[$delivery];
        }

        return $out;
    }

    private function intent(NudgePlan $plan): Intent
    {
        $idleSince = FleetSnapshot::canonicalInstant($plan->idleSinceMs);

        return new Intent(
            kind: self::KIND,
            subjectId: 'idle-nudge:'.$plan->agent.':'.$idleSince,
            provider: 'bridge',
            actor: new Actor(id: null),
            summary: sprintf(
                'idle nudge: this seat has been idle %ds (past its %s horizon of %ds) with %d pushed intent(s) still unseen since it went idle',
                $plan->idleAgeS,
                $plan->suspect ? 'DEFAULT' : 'declared',
                $plan->horizonS,
                $plan->pendingTotal,
            ),
            payload: [
                'agent' => $plan->agent,
                'source' => 'mezzanine',
                'install_id' => $plan->installId,
                'seat_id' => $plan->seatId,
                'verdict' => $plan->suspect ? 'suspect' : 'idle_past_declared_horizon',
                'idle_since' => $idleSince,
                'server_time' => FleetSnapshot::canonicalInstant($plan->serverTimeMs),
                'idle_age_s' => $plan->idleAgeS,
                'horizon_s' => $plan->horizonS,
                'horizon_source' => $plan->suspect ? 'default' : 'declared',
                'pending_total' => $plan->pendingTotal,
                'pending_shown' => count($plan->pending),
                'pending' => $plan->pending,
            ],
        );
    }

    /**
     * `summary` IS the seat's own `prompt`, verbatim: the text its writer composed for exactly
     * this delivery (rt#562). Everything else is bridge vocabulary or a value the reader typed.
     */
    private function offerIntent(SeatOfferPlan $plan): Intent
    {
        $turnEndedAt = FleetSnapshot::canonicalInstant($plan->turnEndedAtMs);

        return new Intent(
            kind: self::KIND,
            subjectId: 'idle-nudge:'.$plan->agent.':'.$turnEndedAt,
            provider: 'bridge',
            actor: new Actor(id: null),
            summary: $plan->prompt,
            payload: [
                'agent' => $plan->agent,
                'source' => 'seat_record',
                'verdict' => 'idle_past_declared_horizon',
                'session_id' => $plan->sessionId,
                'idle_since' => $turnEndedAt,
                'idle_age_s' => $plan->idleAgeS,
                'horizon_s' => $plan->horizonS,
                'horizon_source' => 'declared',
                'cooldown_s' => $plan->cooldownS,
                'pending_total' => $plan->lanesTotal,
                'pending_shown' => count($plan->lanes),
                'pending' => $plan->lanes,
            ],
        );
    }

    /**
     * Counts FIRST and reasons last: the row column holds 255 characters and cuts the tail.
     */
    private function summary(Evaluation $evaluation, int $accepted, int $failed): string
    {
        $parts = [];
        foreach ($evaluation->verdictTally() as $code => $n) {
            $parts[] = "{$code} {$n}";
        }
        $seats = $evaluation->seatTally;

        return sprintf(
            'nudged %d (unconfirmed; failed %d) · agents %d · %s · %s',
            $accepted,
            $failed,
            count($evaluation->verdicts),
            $seats === null
                ? 'no fleet read'
                : sprintf('seats %d (foreign %d, unmapped %d)', array_sum($seats), $seats['foreign'], $seats['unmapped']),
            implode(', ', $parts),
        );
    }
}
