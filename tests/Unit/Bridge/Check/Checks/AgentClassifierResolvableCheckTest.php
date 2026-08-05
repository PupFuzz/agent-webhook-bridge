<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\AgentClassifierResolvableCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\Fixtures\LogIntentClassifier;
use Tests\Fixtures\ProcessDependentClassifier;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The per-agent classifier gate (DL-242 stage 5a).
 *
 * The golden fixture `agent-classifier-missing` already pins the FIRST failure arm and
 * the abort that follows it. What no fixture reaches is the SECOND arm — the
 * `ClassifierResolver::for()` catch — because `probeLoadable()` is designed to return
 * first for anything that fails in both processes. This file is that arm's whole
 * measurement, and it is also the only place the ok line is asserted against a check
 * rather than against a whole `bridge:check` run.
 *
 * (Golden fixtures and test classes are NAMED here, never `{@see}`-linked: pint's
 * docblock fixer turns a fully-qualified `{@see}` into a real `use`.)
 */
class AgentClassifierResolvableCheckTest extends TestCase
{
    use MaterializesChecks;

    protected function tearDown(): void
    {
        // The flag is process-global and this suite shares a process with everything
        // else that resolves a classifier. Leaking it true would make an unrelated
        // resolution throw, far from here.
        ProcessDependentClassifier::$throwOnConstruct = false;
        parent::tearDown();
    }

    public function test_an_unloadable_classifier_fails_and_names_the_agent(): void
    {
        $findings = $this->findingsFor('prod-agent', 'App\Bridge\Classifiers\NoSuchClassifier');

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame(
            'agent prod-agent: classifier class App\Bridge\Classifiers\NoSuchClassifier not found',
            $findings[0]->message,
        );
    }

    /**
     * The arm the out-of-process probe cannot reach — see ProcessDependentClassifier for
     * why a divergence between the two processes is the only way in.
     *
     * The assertion is on the CONSTRUCTOR's own message, which only the in-process
     * resolution can produce: `probeLoadable()`'s failure messages are this check's other
     * arm and read `classifier class … not found` / `… does not implement …`. So a
     * regression that collapsed the two arms would red here rather than pass.
     */
    public function test_a_classifier_that_throws_only_in_this_process_fails_rather_than_aborting_the_check(): void
    {
        ProcessDependentClassifier::$throwOnConstruct = true;

        $findings = $this->findingsFor('prod-agent', ProcessDependentClassifier::class);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame(
            'agent prod-agent: constructor failed against the booted application',
            $findings[0]->message,
        );
    }

    /**
     * The discriminating control. Without it a check hard-wired to `fail`, or one whose
     * healthy path was deleted, satisfies both tests above — and the ok line is the
     * byte-identical contract stages 0-7 hold, so its exact wording is the assertion.
     */
    public function test_a_resolvable_classifier_reports_the_config_ok_line(): void
    {
        $findings = $this->findingsFor('prod-agent', LogIntentClassifier::class);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame('agent config ok: prod-agent', $findings[0]->message);
    }

    /** @return list<Finding> */
    private function findingsFor(string $agent, string $classifierClass): array
    {
        $config = AgentConfig::fromArray($agent, [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'classifier' => ['class' => $classifierClass],
        ]);

        return $this->findingsOfFor(new AgentClassifierResolvableCheck, $config, new CheckContext);
    }
}
