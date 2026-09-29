<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;

/**
 * Emits one real `kanban_move_card` target — card 5 of `owner/repo`, outcome `merged` — for any
 * event, so a receiver-level test drives the production writeback handler through the route,
 * the dispatcher and the exception handler without a GitHub classifier's input shape.
 */
class MoveCardFiveClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        return new ClassifyResult(
            targets: [ReactionTarget::make('kanban_move_card', '5', payload: ['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'merged'])],
        );
    }
}
