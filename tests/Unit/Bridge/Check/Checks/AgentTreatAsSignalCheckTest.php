<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\AgentTreatAsSignalCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\TestCase;

/**
 * The per-agent `treat_as_signal` roster validation (DL-242 stage 5c).
 *
 * EVERY GOLDEN FIXTURE'S `treat_as_signal` RESOLVES, so the golden corpus cannot see the
 * throw. THE COMMAND-LEVEL SUITE CAN, THOUGH: mutating it also reds
 * `BridgeCommandsTest::test_check_fails_on_unknown_treat_as_signal_name`. What this file
 * adds over that is the severity and the composed message, below.
 *
 * WHAT MAKES THIS LEG WORTH A `fail` RATHER THAN A WARN: an unresolvable name is
 * fail-closed at dispatch, so the operator who does not catch it here catches it as a
 * receiver 5xx. The severity is asserted, not just the presence of a finding — a warn
 * would leave the exit code green and the install would look healthy.
 */
class AgentTreatAsSignalCheckTest extends TestCase
{
    public function test_it_fails_the_agent_whose_treat_as_signal_names_no_known_agent(): void
    {
        $findings = $this->findingsFor(['alpha' => ['ghost']]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringStartsWith('agent alpha: ', $findings[0]->message);
        $this->assertStringContainsString('treat_as_signal references name(s) with no matching agent config: ghost', $findings[0]->message);
    }

    public function test_it_is_silent_when_every_name_resolves(): void
    {
        $this->assertSame([], $this->findingsFor(['alpha' => ['beta'], 'beta' => []]));
    }

    /** An empty list is the default population and short-circuits before the roster read. */
    public function test_it_is_silent_when_treat_as_signal_is_unset(): void
    {
        $this->assertSame([], $this->findingsFor(['alpha' => [], 'beta' => []]));
    }

    /**
     * The `catch` is per-agent, and that is the whole reason it is inside the loop. A
     * check that let the first throw escape would abort `bridge:check` and the operator
     * would never learn the second agent is broken too — one bad YAML would mask every
     * agent after it.
     */
    public function test_one_unresolvable_agent_does_not_hide_the_next(): void
    {
        $findings = $this->findingsFor([
            'alpha' => ['ghost'],
            'beta' => ['phantom'],
        ]);

        $this->assertCount(2, $findings);
        $this->assertStringStartsWith('agent alpha: ', $findings[0]->message);
        $this->assertStringStartsWith('agent beta: ', $findings[1]->message);
    }

    /** The guard — see the collisions check's equivalent for why null must not throw. */
    public function test_it_is_silent_when_no_registry_was_built(): void
    {
        $ctx = new CheckContext;
        $ctx->configs = [AgentConfig::fromArray('alpha', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'echo_suppression' => ['treat_as_signal' => ['ghost']],
        ])];

        $this->assertSame([], iterator_to_array((new AgentTreatAsSignalCheck)->run($ctx), false));
    }

    /**
     * @param  array<string, list<string>>  $treatAsSignalByAgent
     * @return list<Finding>
     */
    private function findingsFor(array $treatAsSignalByAgent): array
    {
        $configs = [];
        $id = 1;
        foreach ($treatAsSignalByAgent as $name => $treatAsSignal) {
            $configs[] = AgentConfig::fromArray($name, [
                'identity' => ['kanban_user_id' => $id++],
                'subscriptions' => [],
                'echo_suppression' => ['treat_as_signal' => $treatAsSignal],
            ]);
        }

        $ctx = new CheckContext;
        $ctx->configs = $configs;
        $ctx->registry = AgentRegistry::fromAgentConfigs($configs);

        return iterator_to_array((new AgentTreatAsSignalCheck)->run($ctx), false);
    }
}
