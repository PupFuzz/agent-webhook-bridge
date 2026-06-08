<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;

/**
 * Emits two targets routed to DISTINCT handlers that share one debounceKey.
 * Coalescing keyed on debounceKey alone would collapse them (drop one); keying
 * on (handler, debounceKey) runs both. Pins the coalescing-key fix.
 */
class SameKeyDistinctHandlersClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        return new ClassifyResult(
            targets: [
                ReactionTarget::make('h1', 'X', debounceKey: 'shared'),
                ReactionTarget::make('h2', 'X', debounceKey: 'shared'),
            ],
        );
    }
}
