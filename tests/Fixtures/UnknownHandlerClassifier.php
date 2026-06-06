<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

/**
 * Emits one Intent + a ReactionTarget with an unregistered handler — exercises
 * dispatch case (C): the handler failure is a recorded note, the dispatch is
 * still marked done (the intent is durable in the inbox).
 */
class UnknownHandlerClassifier implements Classifier
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
            targets: [ReactionTarget::make('does_not_exist', $subjectId)],
            intents: [new Intent('new_card', $subjectId, $provider, $actor, "card {$subjectId}")],
        );
    }
}
