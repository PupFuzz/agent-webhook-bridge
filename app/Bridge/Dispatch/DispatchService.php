<?php

namespace App\Bridge\Dispatch;

use App\Bridge\Adapters\EventDto;
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

            $actor = $this->agents->actorFromEvent($dto->actorId, $payload);

            if ($this->isEcho($agent, $actor) || ! $this->isSignal($agent, $actor)) {
                $this->markDone($dispatch);   // filtered out → done, no work

                continue;
            }

            // (A) classify — application error → record + continue (no 5xx)
            try {
                $result = ClassifierResolver::for($agent)->classify($dto->eventType, $payload, $actor, $provider, $scopeId);
            } catch (Throwable $e) {
                $this->recordError($dispatch, $e);

                continue;
            }

            // (B) inbox staging — durability; an IO failure propagates → 5xx.
            // intents is a list, so $index is the intent's array index (the
            // stable per-event identity IntentLog needs).
            foreach ($result->intents as $index => $intent) {
                $this->intentLog->stage($agent, $event, $intent, $index);
            }

            // (C) handlers — best-effort; a failure is a recorded note, not a 5xx
            $note = null;
            foreach ($result->targets as $target) {
                $handler = $this->handlers->resolve($target->handler);
                try {
                    if ($handler === null) {
                        throw new \RuntimeException("unknown handler '{$target->handler}'");
                    }
                    $handler->handle($target, $agent);
                } catch (Throwable $e) {
                    $note = (string) $e;
                    Log::warning('bridge dispatch: handler failed', [
                        'agent' => $agent->agentName, 'handler' => $target->handler, 'error' => $note,
                    ]);
                }
            }

            $this->markDone($dispatch, $note);
        }
    }

    private function isEcho(AgentConfig $agent, Actor $actor): bool
    {
        return EchoSuppression::default(
            $agent->selfIdentity,
            $agent->echoSuppression->treatAsEcho,
            $agent->echoSuppression->treatAsEchoIds,
        )->isEcho($actor);
    }

    private function isSignal(AgentConfig $agent, Actor $actor): bool
    {
        return SignalAllowlist::default($agent->echoSuppression->treatAsSignal, $this->agents)->isSignal($actor);
    }

    private function markDone(AgentDispatch $dispatch, ?string $note = null): void
    {
        $dispatch->update(['processed_at' => now(), 'error_message' => $note]);
    }

    private function recordError(AgentDispatch $dispatch, Throwable $e): void
    {
        $dispatch->update(['error_message' => (string) $e]);
        Log::warning('bridge dispatch: classifier failed', [
            'agent' => $dispatch->agent_name, 'error' => (string) $e,
        ]);
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
