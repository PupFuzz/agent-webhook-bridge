<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Support\AgentConfig;

/**
 * Per-agent (recipient-aware) classifier fixture. Records the serving agent's
 * name on every call (to prove the dispatcher passes each agent its OWN config
 * through the shared cached instance — no leak across the per-agent loop), and
 * stages an intent ONLY when the event's `to` label addresses the serving agent
 * (or `all`). The whole point of the FR: a recipient-aware decision keyed on
 * $agent, impossible before the param existed.
 */
class RecipientAwareClassifier implements Classifier
{
    /** @var list<string> serving agentNames seen, in call order */
    public static array $seenAgents = [];

    public static function reset(): void
    {
        self::$seenAgents = [];
    }

    public function classify(
        string $eventType,
        array $payload,
        Actor $actor,
        string $provider,
        string $scopeId,
        AgentConfig $agent,
    ): ClassifyResult {
        self::$seenAgents[] = $agent->agentName;

        $to = is_string($payload['to'] ?? null) ? $payload['to'] : '';
        if ($to !== 'all' && $to !== $agent->agentName) {
            return new ClassifyResult;   // not addressed to this agent → filtered
        }

        return new ClassifyResult(intents: [new Intent(
            kind: 'addressed',
            subjectId: $agent->agentName,
            provider: $provider,
            actor: $actor,
            summary: "addressed to {$agent->agentName}",
        )]);
    }
}
