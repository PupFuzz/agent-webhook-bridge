<?php

namespace App\Bridge\Contracts;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;

/**
 * Turns a webhook event into intents (surfaced to the agent inbox) and/or
 * reaction targets (automated dispatches). Operators implement this for
 * agent-specific behaviour; the bridge ships InboxOnlyClassifier as the
 * canonical default.
 *
 * Signature mirrors the v0.11.x Python contract (event_type, payload, actor,
 * provider, scope_id) so existing custom classifiers port mechanically and
 * the interface stays decoupled from the persistence layer. scope_id is the
 * receiver-extracted scope (board_id / repo full_name); provider is its
 * symmetric peer.
 */
interface Classifier
{
    /**
     * @param  array<mixed>  $payload  the parsed event body
     */
    public function classify(
        string $eventType,
        array $payload,
        Actor $actor,
        string $provider,
        string $scopeId,
    ): ClassifyResult;
}
