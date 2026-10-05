<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\Checks\CiToolsStateCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * card#11283 / DL-461 — `ci_tools.agent`: one line per enabled agent naming its block shape and
 * what the bridge serves it, read off the same `ServedTools` answer the dispatcher enforces.
 */
class CiToolsStateCheckTest extends TestCase
{
    use MaterializesChecks;

    /**
     * @param  array<string, mixed>|null  $block
     * @return list<Finding>
     */
    private function findings(?array $block): array
    {
        $config = AgentConfig::fromArray('seat', ['subscriptions' => []] + ($block === null ? [] : ['board_tools' => $block]));

        return $this->findingsOfFor(new CiToolsStateCheck, $config);
    }

    public function test_a_scoped_agent_is_reported_with_its_board_and_ci_tools(): void
    {
        $f = $this->findings(['enabled' => true, 'transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]);

        $this->assertCount(1, $f);
        $this->assertSame(Severity::Ok, $f[0]->severity);
        $this->assertStringContainsString('block is scoped', $f[0]->message);
        $this->assertStringContainsString('board_my_cards', $f[0]->message);
        $this->assertStringContainsString('ci_await, ci_await_cancel', $f[0]->message);
    }

    public function test_a_scope_less_agent_is_named_ci_only_with_no_board_tool(): void
    {
        $f = $this->findings(['enabled' => true, 'transport' => 'ssh']);

        $this->assertSame(Severity::Ok, $f[0]->severity);
        $this->assertStringContainsString('scope-less (CI tools only', $f[0]->message);
        $this->assertStringContainsString('served: ci_await, ci_await_cancel.', $f[0]->message);
        $this->assertStringNotContainsString('board_', $f[0]->message);
    }

    public function test_an_opt_out_is_named(): void
    {
        $f = $this->findings(['enabled' => true, 'transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55, 'ci_tools' => false]);

        $this->assertSame(Severity::Ok, $f[0]->severity);
        $this->assertStringContainsString('CI tools opted out', $f[0]->message);
        $this->assertStringNotContainsString('ci_await', $f[0]->message);
    }

    public function test_a_malformed_opt_out_fails_naming_it(): void
    {
        $f = $this->findings(['enabled' => true, 'transport' => 'ssh', 'ci_tools' => 'no']);

        $this->assertSame(Severity::Fail, $f[0]->severity);
        $this->assertStringContainsString('board_tools.ci_tools must be true or false', $f[0]->message);
    }

    public function test_a_scope_less_agent_with_the_ci_tools_off_warns_it_is_served_nothing(): void
    {
        $f = $this->findings(['enabled' => true, 'transport' => 'ssh', 'ci_tools' => false]);

        $this->assertSame(Severity::Warn, $f[0]->severity);
        $this->assertStringContainsString('served NO tool', $f[0]->message);
    }

    public function test_an_agent_with_no_enabled_block_is_silent(): void
    {
        $this->assertSame([], $this->findings(null));
        $this->assertSame([], $this->findings(['enabled' => false]));
    }
}
