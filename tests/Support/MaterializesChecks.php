<?php

namespace Tests\Support;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;

/**
 * Run ONE check and get the findings a renderer would be handed (card#5596).
 *
 * WHY THIS EXISTS. Every per-check unit test used to end in
 * `iterator_to_array($check->run($ctx), false)` — 43 sites across 30 files, each its own
 * copy of "materialize the yield stream". That was harmless while a check yielded nothing
 * but `Finding`s. It stopped being harmless the moment a check could also yield a
 * `Silence` (NAMED, not `{@see}`-linked: pint's docblock fixer turns a fully-qualified
 * `{@see}` into a real `use`, and here that would be an unused import): a raw
 * `iterator_to_array` hands the test the sentinel, and 110 assertions across 22 files went
 * red at once — one defect, multiplied by the duplication that let it spread.
 *
 * WHY IT DRIVES THE REAL RUNNER RATHER THAN STRIPPING THE SENTINEL ITSELF. Stripping it
 * here would be a SECOND implementation of `CheckRunner::materialize()`, and that method's
 * whole safety argument is that it is the ONLY strip site — the containment is what
 * guarantees no renderer can be handed a `Silence` to print. A test-local copy would make
 * that claim false the day it was written, and would then be free to drift away from the
 * behaviour it is standing in for. So this registers the check in a real
 * {@see CheckRunner}, runs it, and returns what the runner built. The tests measure the
 * production path instead of an imitation of it.
 *
 * That is the same lesson stage 8 recorded one level up: a seam tested only at the callee
 * leaves the call site unproven, and testing a pure method exhaustively proves its
 * composition while saying nothing about what still calls it.
 *
 * THE SLOT IS ARBITRARY AND THAT IS SAFE — it is a position key, and these helpers
 * register and run the same one, so no check can see another's. Exceptions still propagate:
 * `CheckRunner` deliberately does not catch, which is what keeps the `expectException`
 * tests measuring what they measured before.
 */
trait MaterializesChecks
{
    /** @return list<Finding> */
    protected function findingsOf(Check $check, ?CheckContext $ctx = null): array
    {
        return (new CheckRunner)
            ->register(CheckSlot::Install, $check)
            ->run(CheckSlot::Install, $ctx ?? new CheckContext)
            ->results[0]->findings;
    }

    /** @return list<Finding> */
    protected function findingsOfFor(PerAgentCheck $check, AgentConfig $config, ?CheckContext $ctx = null): array
    {
        return (new CheckRunner)
            ->registerPerAgent(CheckSlot::AgentConfig, $check)
            ->runForAgent(CheckSlot::AgentConfig, $config, $ctx ?? new CheckContext)
            ->results[0]->findings;
    }
}
