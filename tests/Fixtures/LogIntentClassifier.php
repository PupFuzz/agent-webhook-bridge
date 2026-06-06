<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

/** Emits one Intent + one cheap log_intent ReactionTarget (happy-path handler). */
class LogIntentClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $eventType = $ctx->eventType;
        $payload = $ctx->payload;
        $actor = $ctx->actor;
        $provider = $ctx->provider;
        $scopeId = $ctx->scopeId;
        $agent = $ctx->agent;
        $subjectId = (string) ($payload['subject_id'] ?? '0');

        return new ClassifyResult(
            targets: [ReactionTarget::make('log_intent', $subjectId)],
            intents: [new Intent('new_card', $subjectId, $provider, $actor, "card {$subjectId}")],
        );
    }
}
