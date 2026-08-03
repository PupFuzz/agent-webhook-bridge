<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\CiFailureFilterCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The DL-197 CI-failure-filter advisory (DL-242 stage 5a).
 *
 * The golden fixture `agent-ci-failure-patterns-and-wake-membership` pins the firing
 * case. What it does not reach is the `catch` — a malformed lazy key — nor either
 * silent case, and a check that emitted its warn unconditionally would still pass that
 * fixture only by accident of its config. Both gates and the catch are measured here.
 *
 * (Golden fixtures are NAMED, never `{@see}`-linked: pint's docblock fixer turns a
 * fully-qualified `{@see}` into a real `use`.)
 */
class CiFailureFilterCheckTest extends TestCase
{
    use MaterializesChecks;

    public function test_it_warns_when_the_filter_is_set_and_the_family_is_enabled(): void
    {
        $findings = $this->findingsFor([
            'families' => ['impl-ci-wake'],
            'ci_failure_workflow_patterns' => ['Laravel Tests', 'Security'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        // The configured patterns are echoed back LOWERCASED (ClassifierConfig::strings
        // lowercases for case-insensitive matching) — the operator compares them against
        // real workflow names, so the rendered list is the whole point of the line.
        $this->assertStringContainsString(
            'agent prod-agent: classifier.config.ci_failure_workflow_patterns = [laravel tests, security]',
            $findings[0]->message,
        );
    }

    /**
     * The family gate. A filter configured for a family that is not enabled wakes
     * nothing and warning about it would be noise — and this is the branch that
     * distinguishes the check from one that warns whenever the key is present.
     */
    public function test_it_is_silent_when_the_filter_is_set_but_the_family_is_not_enabled(): void
    {
        $this->assertSame([], $this->findingsFor([
            'families' => ['coord-message'],
            'ci_failure_workflow_patterns' => ['Laravel Tests'],
        ]));
    }

    /** The complementary gate: the family is on, but no filter narrows it. */
    public function test_it_is_silent_when_the_family_is_enabled_but_no_filter_is_set(): void
    {
        $this->assertSame([], $this->findingsFor(['families' => ['impl-ci-wake']]));
    }

    /**
     * The lazy-key catch. `ci_failure_workflow_patterns` is not parsed at load, so a
     * malformed value first throws inside this check — where, without the catch,
     * `CheckRunner` (which deliberately does not catch) would abort the whole run and
     * skip every remaining agent.
     */
    public function test_a_malformed_filter_fails_that_agent_rather_than_aborting_the_run(): void
    {
        $findings = $this->findingsFor([
            'families' => ['impl-ci-wake'],
            'ci_failure_workflow_patterns' => 'not-a-list',
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringStartsWith(
            'agent prod-agent: classifier.config.ci_failure_workflow_patterns — ',
            $findings[0]->message,
        );
        // The parser's own diagnosis, not this check's — asserting it is what proves the
        // throw was caught rather than the message being assembled from the key name.
        $this->assertStringContainsString('must be a list of strings', $findings[0]->message);
    }

    /**
     * @param  array<mixed>  $classifierConfig
     * @return list<Finding>
     */
    private function findingsFor(array $classifierConfig): array
    {
        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'classifier' => ['config' => $classifierConfig],
        ]);

        return $this->findingsOfFor(new CiFailureFilterCheck, $config, new CheckContext);
    }
}
