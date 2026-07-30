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
 * A SLOT THAT IS NEVER RUN IS THE SAME HOLE ONE LEVEL DOWN — CLOSED IN STAGE 8, and not
 * in the shape the stage row predicted. {@see inventory()} accounts for every registered
 * check on every run, and the accounting is DERIVED FROM THE REGISTRATION LIST rather
 * than reported by the caller: a check whose slot is never invoked records
 * {@see CheckDisposition::NotRun} because nothing recorded anything else for it, so a
 * forgotten call site cannot produce a missing row. {@see noteNotRun()} attaches only the
 * human-readable REASON, and a forgotten reason degrades a message instead of opening a
 * hole. The alternative — requiring the caller to declare each skip — would have made the
 * mechanism's correctness depend on remembering to call it, which is the same shape as
 * the bug it closes.
 *
 * WHAT THE STAGE ROW SAID, AND WHY IT IS NOT WHAT SHIPPED. The row read *"every
 * registered check emits >= 1 finding"*. Measured before any code was written, that is
 * false on every install shape the corpus covers: 13 of 37 checks are never invoked on
 * the baseline install and 15 more run and report nothing. Enforcing it literally would
 * have meant dissolving the conditional envelopes stages 3a and 7b preserved as BEHAVIOR.
 * The enforceable invariant is that every registered check is ACCOUNTED FOR. See the
 * stage 8 result in `docs/CHECK-REGISTRY-PLAN.md`.
 *
 * WHICH CHECKS THE COMMAND REGISTERS IS NOW PINNED, by a test asserting the exact id set
 * against {@see registeredIds()}. Before stage 8 a check dropped from `CheckCommand`'s
 * registration list was caught only if its absence changed golden output, so a check
 * silent on the fixture set could be unregistered silently.
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

    /**
     * Registration order across BOTH registries — the order {@see inventory()} reports in.
     *
     * @var list<string>
     */
    private array $order = [];

    /** @var array<string, CheckDisposition> */
    private array $dispositions = [];

    /** @var array<string, string> */
    private array $notRunReasons = [];

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
            $findings = $this->materialize($check->run($ctx));
            $this->recordRan($check, $findings);
            $results[] = new CheckResult($check->id(), $findings);
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
            $findings = $this->materialize($check->runFor($config, $ctx));
            $this->recordRan($check, $findings);
            $results[] = new CheckResult(
                $check->id(),
                $findings,
                $config->agentName,
            );
        }

        return new CheckReport($results);
    }

    /**
     * Every registered check id, in registration order across both registries.
     *
     * The pinnable inventory surface: a test asserting this exact set against a literal
     * is what makes dropping a check from `CheckCommand`'s registration list a red rather
     * than a silence (disproved-claims item 7).
     *
     * @return list<string>
     */
    public function registeredIds(): array
    {
        return $this->order;
    }

    /**
     * Record WHY a slot's checks did not run this pass. Optional and idempotent-by-first:
     * the OUTERMOST envelope to close is the true cause, so a later, narrower reason does
     * not overwrite it.
     *
     * It attaches a reason only — it never creates or clears a disposition. A check this
     * is never called for is still accounted for, as {@see CheckDisposition::NotRun} with
     * no reason, and surfaces in {@see CheckInventory::unexplainedNotRun()}.
     */
    public function noteNotRun(CheckSlot $slot, string $reason): self
    {
        foreach ($this->checks[$slot->value] ?? [] as $check) {
            $this->notRunReasons[$check->id()] ??= $reason;
        }
        foreach ($this->perAgentChecks[$slot->value] ?? [] as $check) {
            $this->notRunReasons[$check->id()] ??= $reason;
        }

        return $this;
    }

    /**
     * The exact account of this run: every registered check, with what became of it.
     *
     * NotRun is DERIVED — anything the run never recorded a disposition for did not run.
     * That direction is deliberate and is the reason this cannot silently lose a check.
     */
    public function inventory(): CheckInventory
    {
        $dispositions = [];
        foreach ($this->order as $id) {
            $dispositions[$id] = $this->dispositions[$id] ?? CheckDisposition::NotRun;
        }

        return new CheckInventory($dispositions, $this->notRunReasons);
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function recordRan(Check|PerAgentCheck $check, array $findings): void
    {
        $id = $check->id();
        if ($findings !== []) {
            // A per-agent check runs once per agent: reporting for ANY agent is the
            // strongest thing true of it this run, so a later silent agent must not
            // downgrade it.
            $this->dispositions[$id] = CheckDisposition::Reported;

            return;
        }

        if ($check instanceof OptInCheck && ! $check->wasRequested()) {
            $this->dispositions[$id] ??= CheckDisposition::NotRequested;

            return;
        }

        $this->dispositions[$id] ??= CheckDisposition::Silent;
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
        $this->order[] = $id;
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
