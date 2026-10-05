<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Tools\ServedTools;

/**
 * WHAT THE BRIDGE SERVES ONE ENABLED AGENT, AND WHETHER ITS CI TOOLS ARE ON (card#11283).
 *
 * One line per agent with an enabled `board_tools` block, read off {@see ServedTools} — the same
 * answer the dispatcher enforces and the `served_tools` op returns — so an operator sees what a
 * seat can call without reading its config: a scoped agent's board tools and CI tools, a
 * scope-less (CI-only) agent's CI tools, and an opt-out.
 *
 * FAILs on a `board_tools.ci_tools` value that is not a strict bool: the agent is served no CI
 * tool until it is fixed, and that was not a choice anyone made. WARNs on an agent served no tool
 * at all — a scope-less block with the CI tools off is a door to nothing.
 *
 * ⚠ IT CANNOT SAY WHETHER A SCOPE-LESS BLOCK BELONGS ON THIS SEAT. The framework's onboarding
 * writes scope-less blocks for implementation seats; the bridge has no way to tell an impl seat
 * from a PM or solo one, so this line makes the block VISIBLE and leaves the judgement to the
 * operator reading it.
 */
final class CiToolsStateCheck implements PerAgentCheck
{
    public const ID = 'ci_tools.agent';

    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $bt = $config->boardTools;
        if ($bt === null || ! $bt->enabled) {
            yield Silence::because('this agent has no enabled board_tools block, so the bridge serves it no tool');

            return;
        }

        $name = $config->agentName;
        $served = ServedTools::make()->namesFor($bt);
        $shown = $served === [] ? 'nothing' : implode(', ', $served);
        $shape = $bt->isScopeless() ? 'scope-less (CI tools only — no board read or write)' : 'scoped';

        if ($bt->ciToolsProblem !== null) {
            yield Finding::fail("ci_tools: agent {$name}: {$bt->ciToolsProblem}. Block is {$shape}; served: {$shown}.");

            return;
        }
        if ($served === []) {
            yield Finding::warn("ci_tools: agent {$name}: served NO tool — the block is {$shape} and opts out of the CI tools (board_tools.ci_tools: false), so its seat authenticates to a door with nothing behind it. Remove the opt-out, add a board scope, or set enabled: false.");

            return;
        }
        $optOut = $bt->ciTools ? '' : ' CI tools opted out (board_tools.ci_tools: false).';

        yield Finding::ok("ci_tools: agent {$name}: block is {$shape}; served: {$shown}.{$optOut}");
    }
}
