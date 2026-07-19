<?php

namespace App\Bridge\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\EchoSuppression;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\InstallGuard;
use App\Bridge\Support\SignalAllowlist;
use App\Bridge\Support\SubscriptionRegistry;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The synchronous per-agent dispatch loop (the plan's keystone). Runs inline in
 * the webhook request: store the event, then for each subscribed agent classify
 * → stage intents → run handlers → mark the dispatch done. Returns to the
 * controller (which acks 200) only when every agent is processed.
 *
 * Failure treatment is split by transience/phase (implementation requirement 1),
 * NOT by exception class:
 *  - (A) classify throws  → record error, leave the dispatch ERRORED
 *        (processed_at null), continue. The webhook still acks 200 — a
 *        deterministic classifier bug must not 5xx into an ~11-day retry storm;
 *        the operator fixes it and runs bridge:replay.
 *  - (B) intent staging throws → propagate (NOT caught) → 5xx → upstream
 *        redelivers once the durable substrate recovers.
 *  - (C) handler/push throws → record a note but mark the dispatch DONE — the
 *        intent is already durable in the inbox (B), and an idle-agent
 *        connection-refused is NORMAL, not a delivery failure. The
 *        note is preserved (not cleared) for observability; bridge:replay
 *        --force can re-run it.
 *  - per-agent durability writes (processed_at) are uncaught → 5xx.
 *
 * NO DB::transaction wraps the loop (implementation requirement 2): each
 * agent's processed_at commit must persist as it happens, so a mid-loop 5xx +
 * redelivery resumes from where it stopped (earlier agents stay skipped) rather
 * than re-running — and re-firing — every agent.
 */
final class DispatchService
{
    public function __construct(
        private SubscriptionRegistry $subscriptions,
        private AgentRegistry $agents,
        private HandlerRegistry $handlers,
        private IntentLog $intentLog,
    ) {}

    /**
     * @param  array<mixed>  $payload  the parsed event body
     * @param  ?string  $onlyAgent  when set, dispatch to only this agent (used by bridge:replay --agent)
     */
    public function dispatch(string $provider, string $scopeId, EventDto $dto, array $payload, ?string $onlyAgent = null): void
    {
        // Refuse to write to a crosstalk-mismatched DB (DL-001). A misconfig
        // here is a 5xx (fail-closed) — kanban-board holds the event and
        // redelivers once the operator fixes the install, rather than letting
        // a -dev install write into the prod DB (or vice versa).
        if (($crosstalk = InstallGuard::dsnCrosstalk()) !== null) {
            throw new ConfigException($crosstalk);
        }

        /** @var WebhookEvent $event */
        $event = $this->dedupCreate(
            WebhookEvent::class,
            [
                'delivery_id' => $dto->deliveryId,
                'provider' => $provider,
                'scope_id' => $scopeId,
                'event_type' => $dto->eventType,
                'actor_id' => $dto->actorId,
                'payload' => $payload,
            ],
            ['delivery_id' => $dto->deliveryId],
        );
        $event->refresh();   // load the DB-default received_at for stable inbox ts

        // subscribedTo() reads the per-agent YAMLs (fail-closed: a malformed
        // config throws here → 5xx → redelivered once fixed).
        foreach ($this->subscriptions->subscribedTo($provider, $scopeId) as $agent) {
            if ($onlyAgent !== null && $agent->agentName !== $onlyAgent) {
                continue;   // bridge:replay --agent scoping
            }
            /** @var AgentDispatch $dispatch */
            $dispatch = $this->dedupCreate(
                AgentDispatch::class,
                ['webhook_event_id' => $event->id, 'agent_name' => $agent->agentName],
                ['webhook_event_id' => $event->id, 'agent_name' => $agent->agentName],
            );
            if ($dispatch->processed_at !== null) {
                continue;   // already done on a prior delivery → redelivery-skip
            }

            $actor = $this->agents->actorFromEvent($provider, $dto->actorId, $payload);

            // Resolved BEFORE the gates because the gates' disposition depends
            // on the classifier's marker (DL-203) — the marker cannot be read
            // without loading the class. A CATCHABLE resolution failure (missing
            // class, not-a-Classifier, ConfigException) is the same deterministic
            // config error as a classify throw → treatment A (record + ack 200).
            // A DL-025 stale-signature classifier is an UNCATCHABLE E_COMPILE_ERROR
            // that fatals here as it already does on every ungated event —
            // `bridge:check`'s out-of-process probeLoadable is the pre-deploy gate
            // for that, and hoisting widens WHICH events hit it (a gated stream no
            // longer acks 200 first), it does not create the failure mode.
            try {
                $classifier = ClassifierResolver::for($agent);
            } catch (Throwable $e) {
                $this->recordError($dispatch, $e);

                continue;
            }

            // Echo/signal are AGENT-SURFACE concerns, not dispatch concerns
            // (DL-203): for a github writeback-emitting classifier a gate hit
            // means classify-then-STRIP (inbox/wake surface removed, machine
            // writeback preserved — the agent's own PR merge must still move
            // its card) instead of a wholesale drop. Everything else keeps the
            // cheap pre-classify drop byte-identical — the provider gate keeps
            // the kanban global-echo stream (the writeback identity's own
            // card_updated echo, incl. a kanban-triage marker seat) off the
            // classify path entirely.
            $stripToMachine = $provider === 'github' && $classifier instanceof EmitsWritebackReactions;

            // Filtered out before classify → a gate-drop, not a delivery (DL-036).
            $gateReason = null;
            if ($this->isEcho($agent, $actor)) {
                $gateReason = 'echo: own write';
            } elseif (! $this->isSignal($agent, $actor)) {
                $gateReason = 'actor is not a signal';
            }
            if ($gateReason !== null && ! $stripToMachine) {
                $this->markDropped($dispatch, $gateReason);

                continue;
            }

            // (A) classify — application error → record + continue (no 5xx).
            // A throw on an already-gate-flagged dispatch is STILL treatment A
            // (ruled, DL-203): the classifier error is real and must stay
            // replayable regardless of how the gate would have disposed of it.
            try {
                $ctx = new ClassifyContext($dto->eventType, $payload, $actor, $provider, $scopeId, $agent);
                $result = $classifier->classify($ctx);
            } catch (Throwable $e) {
                $this->recordError($dispatch, $e);

                continue;
            }

            // Shared-identity echo completion (DL-005): the pre-classify echo
            // gate above could only match the raw id for a shared upstream
            // account (Actor.name was null by design, DL-002). If the classifier
            // recovered the true author, re-run the SAME per-agent echo check
            // now that attribution is better — drop the agent's OWN write (a
            // different shared-id agent's write has a non-self name and stays).
            // No-op when the classifier left reattributedActor null, and
            // redundant when a pre-classify gate already flagged the dispatch.
            if ($gateReason === null && $result->reattributedActor !== null && $this->isEcho($agent, $result->reattributedActor)) {
                if (! $stripToMachine) {
                    $this->markDropped($dispatch, 'echo: own write (reattributed author)');

                    continue;
                }
                $gateReason = 'echo: own write (reattributed author)';
            }

            // The DL-203 strip: a gate hit on a github writeback classifier
            // removes the agent-facing surface — every intent (so inbox staging
            // AND the route_intents synthesis below derive nothing) and every
            // non-DurableReaction target (fail-closed: an unmarked or
            // unregistered custom handler is agent-facing until marked durable,
            // so a classifier bug can't leak an own-write wake/spawn). Nothing
            // machine-facing left → the gate wins whole, recorded under its
            // ORIGINAL reason (never 'classifier emitted no reactions' — the
            // classifier may well have emitted; the gate ate it).
            if ($gateReason !== null) {
                $machineTargets = array_values(array_filter(
                    $result->targets,
                    fn (ReactionTarget $t): bool => $this->handlers->resolve($t->handler) instanceof DurableReaction,
                ));
                if ($machineTargets === []) {
                    $this->markDropped($dispatch, $gateReason);

                    continue;
                }
                $result = new ClassifyResult(targets: $machineTargets);
            }

            // (B) inbox staging — durability; an IO failure propagates → 5xx.
            // intents is a list, so $index is the intent's array index (the
            // stable per-event identity IntentLog needs).
            foreach ($result->intents as $index => $intent) {
                $this->intentLog->stage($agent, $event, $intent, $index);
            }

            // (C) handlers — durable-first, then best-effort (DL-009).
            // Same-event coalescing: collapse targets sharing a (handler,
            // debounceKey) (last-wins) so a classifier emitting duplicate buckets
            // fires each handler once. The handler is part of the key so two
            // DISTINCT handlers sharing a debounceKey (default debounceKey is the
            // targetId) don't clobber each other — e.g. a channel_push and a
            // log_intent for the same subject both run. No cross-delivery debounce
            // in the synchronous model (debounceSeconds is advisory metadata).
            $targets = [];
            foreach ($result->targets as $t) {
                $targets[$t->handler.'|'.$t->debounceKey] = $t;
            }

            // Per-agent channel routing (DL-006): channel.route_intents pushes
            // every staged intent to the agent's configured channel without the
            // classifier hand-emitting channel_push — the config-driven form of
            // EventDrivenClassifier, for fan-out where an agent is remote/idle.
            // The debounceKey is namespaced ('channel_push:<subject>') so a routed
            // push never clobbers an unrelated classifier target on the same
            // subject; it coalesces per subject like EventDrivenClassifier. The
            // shipped hand-emitting classifiers (EventDriven, Coordination) route
            // their channel_push through the guarded InboxOnlyClassifier::wakePush(),
            // which suppresses the hand-emit under route_intents (DL-191, DL-208) —
            // so pairing them with route_intents is safe (one wake). Only a RAW
            // custom classifier that emits channel_push WITHOUT wakePush() would
            // double-push here (distinct debounceKeys ⇒ both survive coalescing).
            if ($agent->channel->routeIntents) {
                foreach ($result->intents as $intent) {
                    $routed = ReactionTarget::make(
                        handler: 'channel_push',
                        targetId: $intent->subjectId,
                        debounceKey: 'channel_push:'.$intent->subjectId,
                        payload: $intent->toArray(),
                    );
                    $targets[$routed->handler.'|'.$routed->debounceKey] = $routed;
                }
            }
            // Partition by durability (DL-009). A DurableReaction handler performs
            // a non-loss-tolerant side effect (e.g. a card-move writeback); its
            // failure must PROPAGATE (treatment B → 5xx → redelivery), not be
            // swallowed as a best-effort note. Durable handlers run FIRST so a
            // durable throw short-circuits BEFORE any best-effort handler fires —
            // redelivery then re-runs the whole dispatch (durable handlers MUST be
            // idempotent) without re-amplifying best-effort pushes.
            $durable = [];
            $bestEffort = [];
            foreach ($targets as $target) {
                $handler = $this->handlers->resolve($target->handler);
                if ($handler instanceof DurableReaction) {
                    $durable[] = [$target, $handler];
                } else {
                    $bestEffort[] = [$target, $handler];
                }
            }

            // Durable: uncaught → propagate → 5xx; the dispatch stays unprocessed
            // (processed_at null) and is redelivered.
            foreach ($durable as [$durableTarget, $durableHandler]) {
                $durableHandler->handle($durableTarget, $agent);
            }

            // Best-effort: a throw is a recorded note, not a delivery failure.
            $note = null;
            foreach ($bestEffort as [$target, $handler]) {
                try {
                    if ($handler === null) {
                        throw new \RuntimeException("unknown handler '{$target->handler}'");
                    }
                    $handler->handle($target, $agent);
                } catch (Throwable $e) {
                    $note = self::exceptionNote($e);
                    Log::warning('bridge dispatch: handler failed', [
                        'agent' => $agent->agentName, 'handler' => $target->handler,
                        'error' => $note, 'exception' => $e,
                    ]);
                }
            }

            // A classifier that emitted no intents AND no targets (e.g. a recipient
            // filter dropped this agent) did no work — record a gate-drop, not a
            // delivery, so the ledger / inspect / replay can tell them apart (DL-036).
            if ($result->intents === [] && $targets === []) {
                $this->markDropped($dispatch, 'classifier emitted no reactions');
            } else {
                $this->markDelivered($dispatch, $note, $gateReason !== null ? 'echo: agent surface suppressed' : null);
            }
        }
    }

    private function isEcho(AgentConfig $agent, Actor $actor): bool
    {
        // Self identity is the agent's name (the YAML filename); its own upstream
        // ids are auto-seeded into treatAsEchoIds by AgentConfig. But drop any id
        // the registry knows is SHARED: a shared account's events must reach
        // classify so the DL-005 re-attribution decides per agent, rather than
        // being suppressed wholesale here by an auto-seeded shared self id (DL-007).
        $echoIds = array_values(array_filter(
            $agent->echoSuppression->treatAsEchoIds,
            fn (string $id) => ! $this->agents->isSharedGithubId($id),
        ));

        // Global echo ids (DL-009): the bridge's own machine-write identities —
        // e.g. the kanban user a card-move writeback acts as — are never a signal
        // for ANY agent, or the resulting card_updated webhook loops back. This is
        // the one non-per-agent echo input, unioned on top of the per-agent self
        // ids. Populated from the writeback identity when the writeback ships.
        return EchoSuppression::default(
            $agent->agentName,
            $agent->echoSuppression->treatAsEcho,
            array_values(array_unique([...$echoIds, ...self::globalEchoIds()])),
        )->isEcho($actor);
    }

    /**
     * @return list<string>
     */
    private static function globalEchoIds(): array
    {
        $ids = config('bridge.global_echo_ids', []);

        return array_values(array_map('strval', array_filter(
            is_array($ids) ? $ids : [],
            fn ($id): bool => is_string($id) || is_int($id),
        )));
    }

    private function isSignal(AgentConfig $agent, Actor $actor): bool
    {
        return SignalAllowlist::default($agent->echoSuppression->treatAsSignal, $this->agents)->isSignal($actor);
    }

    private function markDelivered(AgentDispatch $dispatch, ?string $note = null, ?string $reason = null): void
    {
        $dispatch->update([
            'processed_at' => now(),
            'outcome' => AgentDispatch::OUTCOME_DELIVERED,
            'error_message' => $note,   // a best-effort handler failure, if any
            // Non-null on a delivered row ONLY for the DL-203 echo-suppressed
            // machine writeback ('echo: agent surface suppressed'); otherwise
            // cleared like before (--force replay transition, DL-036).
            'reason' => $reason,
        ]);
        // Info-level so the healthy live path is observable (it otherwise logs
        // nothing — only failures logged at WARNING) — DL-036. The reason key
        // marks a suppressed-surface delivery in the live log (DL-203).
        Log::info('bridge dispatch: delivered', array_filter([
            'agent' => $dispatch->agent_name,
            'event' => $dispatch->webhook_event_id,
            'handler_note' => $note,
            'reason' => $reason,
        ], static fn ($v) => $v !== null));
    }

    private function markDropped(AgentDispatch $dispatch, string $reason): void
    {
        $dispatch->update([
            'processed_at' => now(),
            'outcome' => AgentDispatch::OUTCOME_DROPPED,
            'reason' => $reason,
            'error_message' => null,   // clear a prior pass's error (--force replay transition)
        ]);
        Log::info('bridge dispatch: dropped at gate', [
            'agent' => $dispatch->agent_name, 'event' => $dispatch->webhook_event_id, 'reason' => $reason,
        ]);
    }

    private function recordError(AgentDispatch $dispatch, Throwable $e): void
    {
        $message = self::exceptionNote($e);
        // reason => null clears a prior pass's drop reason on a --force replay
        // transition; processed_at is deliberately left untouched (null) so the
        // row stays replayable.
        $dispatch->update(['error_message' => $message, 'outcome' => AgentDispatch::OUTCOME_ERRORED, 'reason' => null]);
        Log::warning('bridge dispatch: classifier failed', [
            'agent' => $dispatch->agent_name, 'error' => $message, 'exception' => $e,
        ]);
    }

    /**
     * Format an exception for the stored, operator-readable `error_message`:
     * class + message ONLY, never `(string) $e` (the full trace + absolute server
     * paths — that stays in the log, not the DB field).
     */
    private static function exceptionNote(Throwable $e): string
    {
        return $e::class.': '.$e->getMessage();
    }

    /**
     * Race-safe insert-or-refetch (mirrors the receiver's explicit 23000
     * handling). NOT firstOrCreate — that is SELECT-then-INSERT and throws on
     * the loser of a concurrent insert rather than returning the existing row.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @param  array<string, mixed>  $create
     * @param  array<string, mixed>  $find
     * @return TModel
     */
    private function dedupCreate(string $class, array $create, array $find): Model
    {
        try {
            /** @var TModel $created */
            $created = $class::create($create);

            return $created;
        } catch (UniqueConstraintViolationException) {
            /** @var TModel $existing */
            $existing = $class::query()->where($find)->firstOrFail();

            return $existing;
        }
    }
}
