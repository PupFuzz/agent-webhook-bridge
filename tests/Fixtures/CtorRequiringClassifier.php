<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;

/**
 * Signature-compatible, but its constructor REQUIRES an argument — so
 * `new $class` ArgumentCountErrors. Used to test ClassifierResolver's SF-1 wrap.
 */
class CtorRequiringClassifier implements Classifier
{
    public function __construct(private string $requiredArg) {}

    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        return new ClassifyResult;
    }
}
