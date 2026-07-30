<?php

namespace App\Bridge\Check;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;

/**
 * A `bridge:check` check that runs once PER AGENT, inside the config iteration
 * (DL-242, plan constraint (b)).
 *
 * The registry needs a per-agent scope and not just a global one because output is
 * emitted inside `CheckCommand`'s per-agent config loop (`agent config ok: {$name}`,
 * `agent {$name}: …`) and is interleaved per agent. A check hoisted to run after
 * derivation would REORDER output and break the byte-identical contract stages 0-7
 * hold. So these execute within the iteration, at the same ordinal position their
 * inline code held.
 */
interface PerAgentCheck
{
    /**
     * Stable machine id — same rules as {@see Check::id()}, and unique across BOTH
     * registries: the inventory is one namespace.
     */
    public function id(): string;

    /**
     * @return iterable<Finding> Same contract as {@see Check::run()}, including that
     *                           yielding nothing is legal and is recorded as a
     *                           disposition rather than lost.
     *
     * ITS DISPOSITION IS KEYED BY CHECK ID, NOT PER AGENT (the plan's accepted
     * granularity cost). Reporting for ANY agent makes the check `Reported` for the run,
     * because that is the strongest thing true of it.
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable;
}
