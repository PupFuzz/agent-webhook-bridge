<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Contracts\DeclaresConsumedEvents;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Support\ClassifierConfig;
use RuntimeException;

/**
 * Implements `DeclaresConsumedEvents` and THROWS from `consumedEventTypes()`.
 *
 * The one shape that reaches `CheckCommand`'s declaration catch (card#5698): every other
 * failure mode of that block is pre-empted upstream. `ClassifierResolver::for()`'s three
 * throws are already gated by `AgentClassifierResolvableCheck`'s `probeLoadable`, so the
 * dominant reachable throw is the call INTO classifier code — which is why the fixture
 * violates the interface's pure-map contract deliberately rather than incidentally.
 *
 * NOT `ThrowingClassifier`, which throws from `classify()` — a leg `bridge:check` never
 * calls, so it exercises the dispatch path and not this one.
 */
final class UnreadableDeclarationClassifier implements Classifier, DeclaresConsumedEvents
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        return new ClassifyResult(targets: [], intents: []);
    }

    public function consumedEventTypes(ClassifierConfig $cfg): array
    {
        throw new RuntimeException('declaration boom');
    }
}
