<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Support\AgentConfig;
use RuntimeException;

/** Always throws — exercises dispatch case (A): record error, ack 200. */
class ThrowingClassifier implements Classifier
{
    public function classify(string $eventType, array $payload, Actor $actor, string $provider, string $scopeId, AgentConfig $agent): ClassifyResult
    {
        throw new RuntimeException('classifier boom');
    }
}
