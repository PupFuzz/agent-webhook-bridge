<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;

/**
 * Test-only classifier that emits exactly one intent for ANY event type
 * (the shipped InboxOnlyClassifier is kanban-event-specific and no-ops on
 * GitHub events). This makes "an intent was staged" a causal signal that the
 * event reached classify — so its ABSENCE proves echo/signal filtering fired,
 * and its summary lets a test assert the resolved actor name.
 *
 * Not a *Test.php file, so PHPUnit doesn't run it; it autoloads via the
 * `Tests\` PSR-4 map and is referenced by FQCN in an agent's classifier.class.
 */
class AlwaysIntentClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $eventType = $ctx->eventType;
        $payload = $ctx->payload;
        $actor = $ctx->actor;
        $provider = $ctx->provider;
        $scopeId = $ctx->scopeId;
        $agent = $ctx->agent;
        $who = $actor->name ?? $actor->id ?? '?';

        return new ClassifyResult(intents: [new Intent(
            kind: 'test_event',
            subjectId: $scopeId,
            provider: $provider,
            actor: $actor,
            summary: "{$eventType} by {$who}",
            payload: [],
        )]);
    }
}
