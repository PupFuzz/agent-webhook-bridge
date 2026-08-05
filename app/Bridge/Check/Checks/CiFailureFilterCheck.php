<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use Throwable;

/**
 * Surface the `impl-ci-wake` CI-FAILURE workflow-name filter for eyeball verification
 * (DL-197), migrated out of `CheckCommand::handle()`'s per-agent loop (DL-242 stage 5a).
 *
 * The filter INVERTS its family's fail-loud posture: a set-but-non-matching pattern — a
 * typo, or a workflow later renamed — silently blackholes EVERY CI-failure wake for the
 * scope, with no inbox trace under the default `drop`. Config validation cannot catch a
 * well-formed-but-stale pattern, so the only available check is to print the configured
 * patterns where an operator can compare them against real workflow names. Warn, never
 * fail: the filter is a deliberate opt-in.
 *
 * `ci_failure_workflow_patterns` IS A LAZY CONFIG KEY — not eagerly parsed by
 * {@see AgentConfig::load()}, unlike `families` — so a malformed value (a non-list, a
 * blank entry) first throws HERE. The classify path would 5xx on it at runtime. The
 * `catch` is this check's own, in the narrow form the plan allows: it wraps ONE lazy read
 * and no derivation. Keeping it means one bad value surfaces as one agent's error line
 * instead of aborting the whole run and skipping every remaining agent — `CheckRunner`
 * deliberately does not catch.
 *
 * NO GOLDEN FIXTURE REACHES THAT `catch`, but the command-level suite does: mutating it
 * reds `BridgeCommandsTest::test_check_fails_on_malformed_ci_failure_workflow_patterns`,
 * which writes a non-list value and asserts the substring plus the exit flip.
 * `CiFailureFilterCheckTest` is what pins the composed per-agent message. (Named, never
 * `{@see}`-linked: pint would turn the FQCN into a real `use`.)
 */
final class CiFailureFilterCheck implements PerAgentCheck
{
    public function id(): string
    {
        return 'agent.ci_failure_filter';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $name = $config->agentName;

        try {
            $failureFilter = $config->classifierConfig->strings('ci_failure_workflow_patterns');
            if ($failureFilter !== [] && in_array('impl-ci-wake', $config->classifierConfig->strings('families'), true)) {
                yield Finding::warn("agent {$name}: classifier.config.ci_failure_workflow_patterns = [".implode(', ', $failureFilter).'] — the impl-ci-wake CI-FAILURE wake fires ONLY for workflow_run names containing one of these (case-insensitive substring); a failure of any OTHER workflow on a subscribed scope will NOT wake. Verify these match your intended workflow names — a typo or a renamed workflow silences every failure wake.');
            }
        } catch (Throwable $e) {
            yield Finding::fail("agent {$name}: classifier.config.ci_failure_workflow_patterns — ".$e->getMessage());
        }

        yield Silence::because('this agent sets no ci_failure_workflow_patterns, or does not run impl-ci-wake at all — there is no filter narrowing its CI-failure wakes, so nothing to put in front of the operator');
    }
}
