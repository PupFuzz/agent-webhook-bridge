<?php

namespace App\Bridge\Check;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use InvalidArgumentException;

/**
 * Runs the registered checks and returns what they reported (DL-242).
 *
 * TWO SCOPES, because output position is part of the contract (plan constraint (b)):
 * {@see run()} runs a slot's globally-scoped checks, and {@see runForAgent()} is called
 * from INSIDE a per-agent config iteration so per-agent findings stay interleaved where
 * they are today.
 *
 * BOTH SCOPES ARE SLOTTED (stage 1). Stage 0 gave each scope a single call site; stage 1
 * found two distinct per-agent iterations and two non-adjacent global positions, so a
 * {@see CheckSlot} names WHERE a group runs. That is an ordering mechanism only — see
 * the enum for why it is temporary.
 *
 * REGISTRATION IS UNCONDITIONAL (plan constraint (a)). "Not applicable" is a Finding
 * a check returns, never an absent registration: a check that never registered is
 * invisible to the inventory, which re-mints *"green because never looked"* one level
 * up, at the registry. This class therefore offers no conditional-registration
 * affordance, and callers must not build one out of an `if` around `register()`.
 *
 * A SLOT THAT IS NEVER RUN IS THE SAME HOLE ONE LEVEL DOWN, and this class does not
 * close it: nothing here asserts every registered slot was invoked. That assertion
 * belongs to stage 8 ("every registered check emits >= 1 finding"), which is where the
 * runner gains a disposition record. Until then `CheckRunnerTest` pins the registered
 * set and the golden fixtures pin what each slot prints.
 *
 * IT DELIBERATELY DOES NOT CATCH. A check that throws aborts `bridge:check`, exactly
 * as an unwrapped inline leg does today; the fail-soft legs (the retention marker
 * read, the per-agent lazy-config reads, the board probes) carry their own try/catch
 * and keep it when they migrate. Adding isolation here would convert those aborts
 * into warns — an operator-visible change, and not this program's to make silently.
 */
final class CheckRunner
{
    /** @var array<string, list<Check>> */
    private array $checks = [];

    /** @var array<string, list<PerAgentCheck>> */
    private array $perAgentChecks = [];

    /**
     * Ids already taken, across BOTH registries AND every slot — the inventory is one
     * namespace, so a duplicate id would silently merge two checks into one inventory
     * row no matter where they run.
     *
     * @var array<string, true>
     */
    private array $ids = [];

    public function register(CheckSlot $slot, Check ...$checks): self
    {
        foreach ($checks as $check) {
            $this->claimId($check->id());
            $this->checks[$slot->value][] = $check;
        }

        return $this;
    }

    public function registerPerAgent(CheckSlot $slot, PerAgentCheck ...$checks): self
    {
        foreach ($checks as $check) {
            $this->claimId($check->id());
            $this->perAgentChecks[$slot->value][] = $check;
        }

        return $this;
    }

    /** Run one slot's globally-scoped checks, in registration order. */
    public function run(CheckSlot $slot, CheckContext $ctx): CheckReport
    {
        $results = [];
        foreach ($this->checks[$slot->value] ?? [] as $check) {
            $results[] = new CheckResult($check->id(), $this->materialize($check->run($ctx)));
        }

        return new CheckReport($results);
    }

    /**
     * Run one slot's per-agent checks for ONE agent, in registration order. Called from
     * inside a config iteration so the findings land at the position the inline code
     * emits them today.
     */
    public function runForAgent(CheckSlot $slot, AgentConfig $config, CheckContext $ctx): CheckReport
    {
        $results = [];
        foreach ($this->perAgentChecks[$slot->value] ?? [] as $check) {
            $results[] = new CheckResult(
                $check->id(),
                $this->materialize($check->runFor($config, $ctx)),
                $config->agentName,
            );
        }

        return new CheckReport($results);
    }

    private function claimId(string $id): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('check id must not be empty');
        }
        if (isset($this->ids[$id])) {
            throw new InvalidArgumentException("duplicate check id: {$id}");
        }
        $this->ids[$id] = true;
    }

    /**
     * @param  iterable<Finding>  $findings
     * @return list<Finding>
     */
    private function materialize(iterable $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            $out[] = $finding;
        }

        return $out;
    }
}
