<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use RuntimeException;

/**
 * Payload-driven writeback-emitting classifier (DL-203 tests). Marked
 * EmitsWritebackReactions so the dispatcher's echo/signal gates classify-then-
 * strip instead of dropping. Driven by payload keys so one fixture covers the
 * matrix:
 *
 *  - `targets`          list of handler names — one ReactionTarget each
 *                       (distinct debounceKeys, no coalescing surprises)
 *  - `reattributed_to`  report this name as ClassifyResult::reattributedActor
 *                       (the DL-005 completion gate)
 *  - `throw`            throw instead of classifying (treatment-A pin)
 *
 * Always emits one Intent (the agent-facing surface the strip must remove).
 */
class WritebackEmittingClassifier implements Classifier, EmitsWritebackReactions
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $payload = $ctx->payload;
        if ($payload['throw'] ?? false) {
            throw new RuntimeException('writeback classifier boom');
        }

        $targets = [];
        foreach (($payload['targets'] ?? []) as $handler) {
            $targets[] = ReactionTarget::make($handler, 'card-1', "{$handler}:card-1");
        }

        $subjectId = (string) ($payload['subject_id'] ?? '0');
        $trueAuthor = $payload['reattributed_to'] ?? null;

        return new ClassifyResult(
            targets: $targets,
            intents: [new Intent('pr_event', $subjectId, $ctx->provider, $ctx->actor, "pr {$subjectId}")],
            reattributedActor: is_string($trueAuthor)
                ? new Actor(id: $ctx->actor->id, name: $trueAuthor, isKnownAgent: true)
                : null,
        );
    }
}
