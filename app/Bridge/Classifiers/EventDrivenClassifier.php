<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;

/**
 * inbox_only + a paired channel_push ReactionTarget per Intent — the canonical
 * "go live" pattern.
 *
 * Every channel_push target's id matches an Intent's subject_id in the same
 * result (so the silent-drop guard never warns), and carries the Intent's
 * canonical wire shape as its payload (the handler's default body
 * envelope then sends {"intent": <toArray()>} rather than {"intent": {}}).
 * The transport (socket/url) is left to the handler's cfg-derived default.
 *
 * Each push is emitted through the base's guarded {@see InboxOnlyClassifier::wakePush()}
 * so a `route_intents:true` channel — where the dispatcher already routes every staged
 * intent — suppresses the hand-emit instead of double-waking (DL-191, card #4494).
 */
class EventDrivenClassifier extends InboxOnlyClassifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $result = parent::classify($ctx);

        if ($result->intents === []) {
            return $result;
        }

        $channelTargets = array_merge(
            ...array_map(
                fn (Intent $intent): array => $this->wakePush($intent, $ctx),
                $result->intents,
            ),
        );

        return new ClassifyResult(
            targets: array_merge($result->targets, $channelTargets),
            intents: $result->intents,
        );
    }
}
