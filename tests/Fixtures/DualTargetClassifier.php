<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;

/**
 * Emits two reaction targets — a best-effort `be` and a durable `dur` — so a
 * test can register a RecordingHandler / RecordingDurableHandler for each and
 * observe the dispatch ordering + failure treatment. The `be` target is listed
 * FIRST to prove the dispatcher reorders durable-before-best-effort (DL-009),
 * not just preserves emission order.
 */
class DualTargetClassifier implements Classifier
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
                ReactionTarget::make('be', 'be-x'),    // distinct debounceKeys so neither
                ReactionTarget::make('dur', 'dur-x'),   // coalesces the other away
            ],
        );
    }
}
