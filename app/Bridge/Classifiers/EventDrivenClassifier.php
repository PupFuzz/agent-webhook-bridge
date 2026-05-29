<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

/**
 * inbox_only + a paired channel_push ReactionTarget per Intent — the canonical
 * "go live" pattern. Ported from
 * examples/classifiers/event_driven_example.py.
 *
 * Every channel_push target's id matches an Intent's subject_id in the same
 * result (so the silent-drop guard never warns), and carries the Intent's
 * canonical wire shape as its payload (the handler's default body
 * envelope then sends {"intent": <toArray()>} rather than {"intent": {}}).
 * The transport (socket/url) is left to the handler's cfg-derived default.
 */
class EventDrivenClassifier extends InboxOnlyClassifier
{
    public function classify(
        string $eventType,
        array $payload,
        Actor $actor,
        string $provider,
        string $scopeId,
    ): ClassifyResult {
        $result = parent::classify($eventType, $payload, $actor, $provider, $scopeId);

        if ($result->intents === []) {
            return $result;
        }

        $channelTargets = array_map(
            fn (Intent $intent): ReactionTarget => ReactionTarget::make(
                handler: 'channel_push',
                targetId: $intent->subjectId,
                debounceSeconds: 0,
                payload: $intent->toArray(),
            ),
            $result->intents,
        );

        return new ClassifyResult(
            targets: array_merge($result->targets, $channelTargets),
            intents: $result->intents,
        );
    }
}
