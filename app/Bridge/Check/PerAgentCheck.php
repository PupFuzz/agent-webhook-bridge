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
     * @return iterable<Finding|Silence> Same contract as {@see Check::run()}, including that
     *                                   yielding nothing is legal and is recorded as a
     *                                   disposition rather than lost — and that a
     *                                   deliberate silence must DECLARE itself with a
     *                                   {@see Silence} (card#5596).
     *
     * ITS DISPOSITION IS KEYED BY CHECK ID, NOT PER AGENT (the plan's accepted
     * granularity cost). Reporting for ANY agent makes the check `Reported` for the run,
     * because that is the strongest thing true of it.
     *
     * THE SILENCE DECLARATION IS THE ONE THING KEYED PER AGENT, and it has to be: this
     * method runs once per agent and can be deliberately silent for one and accidentally
     * silent for the next. An undeclared execution is recorded even when another agent's
     * execution reported, so the per-id fold above cannot swallow it
     * ({@see CheckInventory::undeclaredSilent()}).
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable;
}
