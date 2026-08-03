<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\AgentDefaultAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The `BRIDGE_DEFAULT_AGENT` roster assertion (DL-242 stage 5c).
 *
 * The golden fixture `default-agent-has-no-config` pins the firing case, so unlike its
 * two neighbours this leg was never a disclosed gap. WHAT THE FIXTURE DOES NOT PIN is
 * WHICH name list the check resolves against — and that is the one thing the migration
 * could plausibly get wrong, because the obvious-looking source (`$ctx->configs`) is the
 * wrong one. See the malformed-YAML test below; it is the reason this file exists.
 *
 * (Golden fixtures are NAMED, never `{@see}`-linked: pint's docblock fixer turns a
 * fully-qualified `{@see}` into a real `use`.)
 */
class AgentDefaultAgentCheckTest extends TestCase
{
    use MaterializesChecks;

    public function test_it_warns_when_the_default_agent_names_no_scanned_config(): void
    {
        $findings = $this->findingsFor('ghost-agent', ['prod-agent'], '/etc/bridge');

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame(
            "BRIDGE_DEFAULT_AGENT 'ghost-agent' has no matching config /etc/bridge/ghost-agent.yml",
            $findings[0]->message,
        );
    }

    public function test_it_is_silent_when_the_default_agent_was_scanned(): void
    {
        $this->assertSame([], $this->findingsFor('prod-agent', ['prod-agent', 'dev-agent'], '/etc/bridge'));
    }

    /**
     * An install that always passes `--agent` is correct with the variable unset, so
     * both the unset and the empty-string cases must stay quiet.
     */
    public function test_it_is_silent_when_no_default_agent_is_configured(): void
    {
        $this->assertSame([], $this->findingsFor(null, ['prod-agent'], '/etc/bridge'));
        $this->assertSame([], $this->findingsFor('', ['prod-agent'], '/etc/bridge'));
    }

    /**
     * THE MIGRATION TRAP, AND THE REASON `CheckContext` CARRIES `agentNames` AT ALL.
     *
     * `agentNames` records every `<name>.yml` the scan SAW; `configs` holds only those
     * that PARSED. An agent whose YAML is malformed is therefore in the first and absent
     * from the second. Resolving the default against `configs` — the field that already
     * existed, and the reuse a reviewer would wave through — makes this check tell the
     * operator to create a file that is sitting right there, while a separate line has
     * already reported the real fault.
     *
     * NO GOLDEN FIXTURE PAIRS A MALFORMED YAML WITH A DEFAULT AGENT NAMING IT, so the
     * byte-identical output contract is blind to the swap. This test is what is not.
     */
    public function test_a_malformed_config_still_counts_as_a_matching_config(): void
    {
        $ctx = new CheckContext;
        $ctx->configDir = '/etc/bridge';
        // What the scan saw: both YAMLs exist on disk.
        $ctx->agentNames = ['prod-agent', 'broken-agent'];
        // What parsed: only one. `broken-agent` threw on load and was reported there.
        $ctx->configs = [AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
        ])];

        config(['bridge.default_agent' => 'broken-agent']);

        $this->assertSame([], $this->findingsOf((new AgentDefaultAgentCheck), $ctx));
    }

    /**
     * A null config dir still renders a message rather than throwing — the leg's job is
     * to name the file the operator should look for, and an install with no config dir
     * has a bigger problem already reported above it.
     */
    public function test_it_renders_without_a_config_dir(): void
    {
        $findings = $this->findingsFor('ghost-agent', [], null);

        $this->assertCount(1, $findings);
        $this->assertSame(
            "BRIDGE_DEFAULT_AGENT 'ghost-agent' has no matching config /ghost-agent.yml",
            $findings[0]->message,
        );
    }

    /**
     * @param  list<string>  $agentNames
     * @return list<Finding>
     */
    private function findingsFor(?string $defaultAgent, array $agentNames, ?string $configDir): array
    {
        config(['bridge.default_agent' => $defaultAgent]);

        $ctx = new CheckContext;
        $ctx->agentNames = $agentNames;
        $ctx->configDir = $configDir;

        return $this->findingsOf((new AgentDefaultAgentCheck), $ctx);
    }
}
