<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Support\AgentConfig;

/**
 * The exact #2053 failure shape: a custom classifier still on the PRE-DL-025
 * positional classify() signature. Because it `implements Classifier` with an
 * incompatible signature, merely DECLARING this class is an uncatchable
 * E_COMPILE_ERROR ("Declaration must be compatible with Classifier::classify").
 *
 * It is therefore referenced ONLY by string FQCN (never imported or `new`ed) so
 * the parent PHPUnit process never autoloads it — otherwise the suite itself
 * would fatal. The out-of-process probe (ClassifierResolver::probeLoadable) is
 * what loads it, in a child php process, exactly as designed.
 */
class StaleSignatureClassifier implements Classifier
{
    public function classify(
        string $eventType,
        array $payload,
        Actor $actor,
        string $provider,
        string $scopeId,
        AgentConfig $agent,
    ): ClassifyResult {
        return new ClassifyResult;
    }
}
