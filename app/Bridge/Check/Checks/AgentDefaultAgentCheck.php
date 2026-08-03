<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;

/**
 * Assert `BRIDGE_DEFAULT_AGENT` names a config that was actually scanned, migrated out
 * of `CheckCommand::handle()` (DL-242 stage 5c).
 *
 * The failure it reports is silent everywhere else: a bare `bridge:inbox` resolves the
 * default agent, finds nothing, and surfaces no intents — indistinguishable from an
 * empty inbox. Warn rather than fail, because an install that always passes `--agent`
 * is correct with the variable unset or stale.
 *
 * IT READS `$ctx->agentNames`, AND `$ctx->configs` WOULD BE A BUG. `agentNames` holds
 * every `<name>.yml` the config-dir scan SAW; `configs` holds only those that PARSED. An
 * agent whose YAML is malformed is in the first and absent from the second — and it is
 * reported by its own leg already. Resolving the default against `configs` would tell the
 * operator to create a file that exists, pointing at the wrong fix for a fault another
 * line has already named. No fixture pairs a malformed YAML with a default agent naming
 * it, so the golden suite is silent on that swap; `AgentDefaultAgentCheckTest` is what
 * pins it. (Named, never `{@see}`-linked: pint would turn the FQCN into a real `use`.)
 */
final class AgentDefaultAgentCheck implements Check
{
    public function id(): string
    {
        return 'agent.default_agent';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $defaultAgent = config('bridge.default_agent');

        if (is_string($defaultAgent) && $defaultAgent !== '' && ! in_array($defaultAgent, $ctx->agentNames, true)) {
            yield Finding::warn("BRIDGE_DEFAULT_AGENT '{$defaultAgent}' has no matching config {$ctx->configDir}/{$defaultAgent}.yml");
        }

        yield Silence::because('no BRIDGE_DEFAULT_AGENT is set, or the one set names a config this scan found — the healthy case, and the overwhelmingly common one');
    }
}
