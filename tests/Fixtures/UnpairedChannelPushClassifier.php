<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

/**
 * Payload-driven emitter of channel_push targets that do NOT pair with the one
 * Intent it emits — the shape the DL-278 silent-drop guard warns on. Carries no
 * EmitsWritebackReactions marker (that would change the dispatcher's gate
 * disposition, which these tests are not about).
 *
 *  - `subject_id`  the emitted Intent's subjectId (default '42')
 *  - `pushes`      list of {target_id, debounce_key?} — one channel_push each;
 *                  default a single unpaired 'unpaired-1'
 */
class UnpairedChannelPushClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $subjectId = (string) ($ctx->payload['subject_id'] ?? '42');

        $targets = [];
        foreach (($ctx->payload['pushes'] ?? [['target_id' => 'unpaired-1']]) as $push) {
            $targets[] = ReactionTarget::make(
                handler: 'channel_push',
                targetId: (string) $push['target_id'],
                debounceKey: isset($push['debounce_key']) ? (string) $push['debounce_key'] : null,
            );
        }

        return new ClassifyResult(
            targets: $targets,
            intents: [new Intent('card_event', $subjectId, $ctx->provider, $ctx->actor, "card {$subjectId}")],
        );
    }
}
