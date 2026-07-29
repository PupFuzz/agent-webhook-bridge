<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use RuntimeException;

/**
 * A classifier that loads cleanly in a BARE php process and throws in a BOOTED one.
 *
 * It exists to reach the one arm of `AgentClassifierResolvableCheck` no fixture install
 * can: the `ClassifierResolver::for()` catch. That arm is unreachable in the ordinary
 * way BY DESIGN — `probeLoadable()` runs `new $class` in a child process first and
 * returns before `for()` is ever called, so anything that throws in both processes trips
 * the earlier arm instead. The only states that reach the later one are those where the
 * two processes DISAGREE, and the child is a bare `php -r` with nothing but
 * `vendor/autoload.php` — no Laravel app, no container, no config, no test-run state.
 *
 * The static flag stands in for exactly that class of divergence (a constructor that
 * reads container/config/runtime state the child process does not have). The child sees
 * the default `false` and constructs; the test process sets it and does not.
 */
final class ProcessDependentClassifier implements Classifier
{
    /**
     * Set true by the test that needs the in-process constructor to fail. Never set in
     * the probe subprocess, which loads this file fresh with the default.
     */
    public static bool $throwOnConstruct = false;

    public function __construct()
    {
        if (self::$throwOnConstruct) {
            throw new RuntimeException('constructor failed against the booted application');
        }
    }

    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        return new ClassifyResult(targets: [], intents: []);
    }
}
