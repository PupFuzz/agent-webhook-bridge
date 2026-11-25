<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;

/**
 * Test-only thin subclass of the shipped classifier — the extension shape its docblock invites —
 * that adds one `test_event` intent, and its wake, to every event. It is how a test can
 * see what a gate lets through BESIDE the shipped classifier's own output: the shipped families
 * emit no other agent surface on an advisory notice, so without this the advisory bypass's
 * narrowing has nothing to narrow.
 *
 * Not a *Test.php file, so PHPUnit doesn't run it; referenced by FQCN in an agent's classifier.class.
 */
class ExtraIntentCoordinationClassifier extends CoordinationClassifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $result = parent::classify($ctx);
        $extra = new Intent(kind: 'test_event', subjectId: 'extra', provider: $ctx->provider, actor: $ctx->actor, summary: 'extra', payload: []);

        return new ClassifyResult(
            targets: [...$result->targets, ...$this->wakePush($extra, $ctx)],
            intents: [...$result->intents, $extra],
            reattributedActor: $result->reattributedActor,
            dropReason: $result->dropReason,
            once: $result->once,
        );
    }
}
