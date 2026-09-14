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
use App\Bridge\IdleNudge\IdleNudgeState;
use App\Bridge\IdleNudge\IdleNudgeUnmeasured;
use App\Bridge\IdleNudge\InboxUnreadable;
use App\Bridge\IdleNudge\NudgePlan;
use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Support\AuthoredIntentPush;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\DbClock;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
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
 * pass that measured returns ok — including today's live reality, where no seat publishes
 * `protocol_agent_name` and every agent reads `no_declaring_seat` — and a push that threw is
 * recorded per agent in {@see IdleNudgePassRecord}, which the check leg reads.
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
            $this->recordUnmeasured($e instanceof IdleNudgeUnmeasured
                ? $e->reason
                : 'the pass threw '.$e::class.' before it finished — see the job row\'s last_error');

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
        if ($cfg->problem !== null) {
            throw new IdleNudgeUnmeasured('misconfigured — '.$cfg->problem);
        }

        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (Throwable) {
            throw new IdleNudgeUnmeasured('the declared agent YAMLs could not be loaded, so which agents exist is unknown — run bridge:check');
        }
        $agents = [];
        foreach ($configs as $agentConfig) {
            $agents[$agentConfig->agentName] = $agentConfig->channel->routeIntents;
        }

        // Loaded BEFORE the request: a state file this pass cannot parse costs no request, and
        // is never written over.
        $state = IdleNudgeState::load();

        $snapshot = (new FleetSnapshotReader)->read($cfg);
        $dbNowS = (float) DbClock::now()->format('U.u');

        $evaluation = (new IdleNudgeEvaluator)->evaluate(
            $snapshot,
            (string) $cfg->install,
            $agents,
            $state->nudged(),
            $this->unseenLines(...),
            $dbNowS,
            $cfg->defaultAfterS,
        );

        $accepted = 0;
        $failed = [];
        $pusher = new AuthoredIntentPush($this->handlers);
        foreach ($evaluation->plans() as $plan) {
            $state->markAndSave($plan->agent, $plan->idleSinceMs);
            try {
                $pusher->send($this->intent($plan), $plan->agent);
                $accepted++;
            } catch (Throwable $e) {
                $failed[] = $plan->agent;
                Log::warning('idle nudge: the push to this agent failed; it is NOT retried for this idle period', [
                    'agent' => $plan->agent,
                    'exception' => $e::class,
                ]);
            }
        }

        $state->forgetUndeclared(array_keys($agents));
        IdleNudgePassRecord::measured($evaluation, $accepted, $failed);

        Log::info('idle nudge pass', [
            'seats' => $evaluation->seatTally,
            'verdicts' => $evaluation->verdictTally(),
            'pushes_accepted_by_transport' => $accepted,
            'pushes_failed' => $failed,
        ]);

        return JobOutcome::ok($this->summary($evaluation, $accepted, count($failed)));
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
            'nudged %d (unconfirmed; failed %d) · agents %d · seats %d (foreign %d, unmapped %d) · %s',
            $accepted,
            $failed,
            count($evaluation->verdicts),
            array_sum($seats),
            $seats['foreign'],
            $seats['unmapped'],
            implode(', ', $parts),
        );
    }
}
