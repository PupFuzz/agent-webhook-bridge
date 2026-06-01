<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;

/** Emits one Intent + one cheap log_intent ReactionTarget (happy-path handler). */
class LogIntentClassifier implements Classifier
{
    public function classify(string $eventType, array $payload, Actor $actor, string $provider, string $scopeId, AgentConfig $agent): ClassifyResult
    {
        $subjectId = (string) ($payload['subject_id'] ?? '0');

        return new ClassifyResult(
            targets: [ReactionTarget::make('log_intent', $subjectId)],
            intents: [new Intent('new_card', $subjectId, $provider, $actor, "card {$subjectId}")],
        );
    }
}
