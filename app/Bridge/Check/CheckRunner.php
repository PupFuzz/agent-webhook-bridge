<?php

namespace App\Bridge\Check;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use InvalidArgumentException;

/**
 * Runs the registered checks and returns what they reported (DL-242).
 *
 * TWO SCOPES, because output position is part of the contract (plan constraint (b)):
 * {@see run()} is called once, and {@see runForAgent()} is called from INSIDE
 * `CheckCommand`'s per-agent config iteration so per-agent findings stay interleaved
 * where they are today.
 *
 * REGISTRATION IS UNCONDITIONAL (plan constraint (a)). "Not applicable" is a Finding
 * a check returns, never an absent registration: a check that never registered is
 * invisible to the inventory, which re-mints *"green because never looked"* one level
 * up, at the registry. This class therefore offers no conditional-registration
 * affordance, and callers must not build one out of an `if` around `register()`.
 *
 * IT DELIBERATELY DOES NOT CATCH. A check that throws aborts `bridge:check`, exactly
 * as an unwrapped inline leg does today; the fail-soft legs (the retention marker
 * read, the per-agent lazy-config reads, the board probes) carry their own try/catch
 * and keep it when they migrate. Adding isolation here would convert those aborts
 * into warns — an operator-visible change, and not this program's to make silently.
 */
final class CheckRunner
{
    /** @var list<Check> */
    private array $checks = [];

    /** @var list<PerAgentCheck> */
    private array $perAgentChecks = [];

    /**
     * Ids already taken, across BOTH registries — the inventory is one namespace, so
     * a duplicate id would silently merge two checks into one inventory row.
     *
     * @var array<string, true>
     */
    private array $ids = [];

    public function register(Check ...$checks): self
    {
        foreach ($checks as $check) {
            $this->claimId($check->id());
            $this->checks[] = $check;
        }

        return $this;
    }

    public function registerPerAgent(PerAgentCheck ...$checks): self
    {
        foreach ($checks as $check) {
            $this->claimId($check->id());
            $this->perAgentChecks[] = $check;
        }

        return $this;
    }

    /** Run every globally-scoped check, in registration order. */
    public function run(CheckContext $ctx): CheckReport
    {
        $results = [];
        foreach ($this->checks as $check) {
            $results[] = new CheckResult($check->id(), $this->materialize($check->run($ctx)));
        }

        return new CheckReport($results);
    }

    /**
     * Run every per-agent check for ONE agent, in registration order. Called from
     * inside the config iteration so the findings land at the position the inline
     * code emits them today.
     */
    public function runForAgent(AgentConfig $config, CheckContext $ctx): CheckReport
    {
        $results = [];
        foreach ($this->perAgentChecks as $check) {
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
