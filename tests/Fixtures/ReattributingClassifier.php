<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Support\AgentConfig;

/**
 * Models a shared-upstream-identity classifier (DL-005). The registry could not
 * attribute the event (Actor.name null for a shared account, DL-002), so this
 * recovers the true author from a payload convention (`reattributed_to`) and
 * reports it as ClassifyResult::reattributedActor while ALWAYS emitting its
 * intent — it does not know which agent it is serving. The dispatcher's
 * post-classify echo check decides, per agent, whether that author is a
 * self-echo.
 */
class ReattributingClassifier implements Classifier
{
    public function classify(string $eventType, array $payload, Actor $actor, string $provider, string $scopeId, AgentConfig $agent): ClassifyResult
    {
        $subjectId = (string) ($payload['subject_id'] ?? '0');
        $trueAuthor = $payload['reattributed_to'] ?? null;

        // `intent_author` stamps the emitted Intent's OWN actor name WITHOUT
        // reporting reattributedActor — to prove the dispatcher's echo recheck
        // keys on reattributedActor, never on the intent's actor.
        $intentAuthor = $payload['intent_author'] ?? null;
        $intentActor = is_string($intentAuthor)
            ? new Actor(id: $actor->id, name: $intentAuthor, isKnownAgent: true)
            : $actor;

        return new ClassifyResult(
            intents: [new Intent('new_card', $subjectId, $provider, $intentActor, "card {$subjectId}")],
            reattributedActor: is_string($trueAuthor)
                ? new Actor(id: $actor->id, name: $trueAuthor, isKnownAgent: true)
                : null,
        );
    }
}
