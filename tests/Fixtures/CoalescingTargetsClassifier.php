<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;

/**
 * Emits three log_intent targets in ONE result: two share a debounceKey
 * (must coalesce last-wins → one handler invocation) and one is distinct
 * (fires on its own). No intents — exercises the dispatch-time coalescing only.
 */
class CoalescingTargetsClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $eventType = $ctx->eventType;
        $payload = $ctx->payload;
        $actor = $ctx->actor;
        $provider = $ctx->provider;
        $scopeId = $ctx->scopeId;
        $agent = $ctx->agent;

        return new ClassifyResult(
            targets: [
                ReactionTarget::make('log_intent', 'A', debounceKey: 'bucket-x'),
                ReactionTarget::make('log_intent', 'B', debounceKey: 'bucket-x'),
                ReactionTarget::make('log_intent', 'C', debounceKey: 'bucket-y'),
            ],
        );
    }
}
