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
 * REGISTRATION IS UNCONDITIONAL (plan constraint (a)). "Not applicable" is never an
 * absent registration: a check that never registered is invisible to the inventory,
 * which re-mints *"green because never looked"* one level up, at the registry. This
 * class therefore offers no conditional-registration affordance, and callers must not
 * build one out of an `if` around `register()`.
 *
 * IT IS ALSO NOT, IN THE MAJORITY CASE, A RETURNED `Finding` — which is what constraint
 * (a) originally said. A {@see CheckDisposition::NotRun} check never has `run()` invoked
 * at all, so no `Finding` exists to carry the verdict; the runner derives it from the
 * registration list and {@see noteNotRun()} attaches the ENVELOPE's reason. Only a check
 * that actually ran answers with a `Finding` (or with an empty yield).
 *
 * A SLOT THAT IS NEVER RUN IS THE SAME HOLE ONE LEVEL DOWN — CLOSED IN STAGE 8, and not
 * in the shape the stage row predicted. {@see inventory()} accounts for every registered
 * check on every run THAT COMPLETES (this class does not catch — see below — so a throwing
 * check aborts before anything renders the account), and the accounting is DERIVED FROM
 * THE REGISTRATION LIST rather than reported by the caller: a check whose slot is never
 * invoked records {@see CheckDisposition::NotRun} because nothing recorded anything else
 * for it, so a forgotten call site cannot produce a missing row. {@see noteNotRun()}
 * attaches only the human-readable REASON, and a forgotten reason degrades a message
 * instead of opening a hole. The alternative — requiring the caller to declare each skip
 * — would have made the mechanism's correctness depend on remembering to call it, which
 * is the same shape as the bug it closes.
 *
 * WHAT THE STAGE ROW SAID, AND WHY IT IS NOT WHAT SHIPPED. The row read *"every
 * registered check emits >= 1 finding"*. Measured before any code was written, that is
 * false on every install shape the corpus covers: as stage 8 measured it, on the baseline
 * install 13 of 37 checks were never invoked, 13 more ran and reported nothing, and 2
 * opt-in probes were never asked to look — 9 of 37 emitted anything (the four figures are
 * the four {@see CheckDisposition} cases, and they sum to the registered total by
 * construction). They are that measurement and are deliberately not re-derived by hand as
 * the registered set grows; the committed `minimal` golden file states the current run's
 * inventory. Enforcing it literally would
 * have meant dissolving the conditional envelopes stages 3a and 7b preserved as BEHAVIOR.
 * The enforceable invariant is that every registered check is ACCOUNTED FOR on every run
 * that completes. See the stage 8 result in `docs/CHECK-REGISTRY-PLAN.md`.
 *
 * A SILENCE IS NOW DECLARED RATHER THAN INFERRED (card#5596). Stage 8's account could say
 * a check ran and said nothing; it could not say whether that was the check looking and
 * correctly having nothing to report, or the check falling off the end of its generator
 * through a path its author did not intend. This class therefore stops inferring: a
 * deliberate silence is a {@see Silence} yielded on the path that produced it,
 * {@see materialize()} strips it before any {@see CheckResult} is built, and an execution
 * that yields neither a finding nor a declaration is recorded in
 * {@see CheckInventory::undeclaredSilent()}. The same direction as the not-run accounting
 * — the runner trusts a DECLARATION and never an inference from an empty yield — for the
 * same reason {@see OptInCheck} exists.
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

    /**
     * Every result this run produced, in the order the slots were invoked — what a
     * whole-run renderer walks (DL-249 stage 9).
     *
     * THE TEXT RENDERER NEVER NEEDS THIS, and that asymmetry is the reason it exists.
     * Text is emitted INCREMENTALLY, interleaved with the per-agent loop that produces
     * it, so each {@see CheckReport} is rendered and discarded at its call site. A JSON
     * document cannot be interleaved — it is one value, emitted once, after the last
     * check has run — so something has to retain what the reports carried. Deriving it
     * from the {@see CheckReport}s at the call sites instead would mean every caller
     * accumulating, which is the forgotten-call-site shape stage 8 removed from the
     * inventory.
     *
     * A PER-AGENT CHECK APPEARS ONCE PER AGENT here, unlike in {@see inventory()}, which
     * is keyed by check id. Both are wanted: the inventory answers "did this check get
     * to look", these answer "what did it say, and for whom".
     *
     * @var list<CheckResult>
     */
    private array $results = [];

    /** @var array<string, CheckDisposition> */
    private array $dispositions = [];

    /** @var array<string, string> */
    private array $notRunReasons = [];

    /**
     * Ids that had at least one execution yield NO finding and NO {@see Silence}
     * (card#5596) — a silence nobody declared.
     *
     * RECORDED AT EXECUTION TIME AND NOT FILTERED BY THE FINAL DISPOSITION, which is the
     * one place this deliberately does NOT mirror {@see self::$notRunReasons}. A
     * {@see PerAgentCheck} that reports for one agent and is undeclared-silent for another
     * ends the run {@see CheckDisposition::Reported}, so a set derived by filtering
     * `disposition === Silent` — the shape {@see CheckInventory::unexplainedNotRun()} uses
     * — would hide exactly that case. Recording per EXECUTION closes the per-agent
     * granularity bound here rather than disclosing it.
     *
     * @var array<string, true>
     */
    private array $undeclaredSilent = [];

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
            [$findings, $declaredSilence] = $this->materialize($check->run($ctx));
            $this->recordRan($check, $findings, $declaredSilence);
            $results[] = $this->results[] = new CheckResult($check->id(), $findings);
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
            [$findings, $declaredSilence] = $this->materialize($check->runFor($config, $ctx));
            $this->recordRan($check, $findings, $declaredSilence);
            $results[] = $this->results[] = new CheckResult(
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
     * Every result this run produced, in invocation order — see {@see self::$results}.
     *
     * @return list<CheckResult>
     */
    public function results(): array
    {
        return $this->results;
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
        $undeclaredSilent = [];
        foreach ($this->order as $id) {
            $dispositions[$id] = $this->dispositions[$id] ?? CheckDisposition::NotRun;
            if (isset($this->undeclaredSilent[$id])) {
                $undeclaredSilent[] = $id;
            }
        }

        return new CheckInventory($dispositions, $this->notRunReasons, $undeclaredSilent);
    }

    /**
     * @param  list<Finding>  $findings
     * @param  bool  $declaredSilence  whether this ONE execution yielded a {@see Silence}
     */
    private function recordRan(Check|PerAgentCheck $check, array $findings, bool $declaredSilence): void
    {
        $id = $check->id();
        if ($findings !== []) {
            // A per-agent check runs once per agent: reporting for ANY agent is the
            // strongest thing true of it this run, so a later silent agent must not
            // downgrade it.
            //
            // A `Silence` yielded alongside findings is IGNORED, and that is the PRIMARY
            // idiom rather than a tolerated oddity: a declaration says what a silence would
            // MEAN, so the common shape yields it unconditionally after the loop that may
            // or may not have reported. See `Silence`'s two placement rules.
            $this->dispositions[$id] = CheckDisposition::Reported;

            return;
        }

        if ($check instanceof OptInCheck && ! $check->wasRequested()) {
            // Already a DECLARED state — the check said the operator did not ask — so it
            // owes no second declaration, and requiring one would put `Silence` in the
            // not-requested vocabulary the opt-in decision kept it out of.
            $this->dispositions[$id] ??= CheckDisposition::NotRequested;

            return;
        }

        if (! $declaredSilence) {
            $this->undeclaredSilent[$id] = true;
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
     * Split one execution's yield stream into the findings a renderer sees and the
     * declaration it must never see (card#5596).
     *
     * THIS IS THE ONLY PLACE A {@see Silence} IS STRIPPED, and that containment is what
     * makes the sentinel safe: {@see CheckResult} is constructed from the returned
     * `list<Finding>` alone, so no renderer can be handed one to print. A test pins it —
     * a check yielding only a `Silence` produces a result with zero findings.
     *
     * @param  iterable<Finding|Silence>  $findings
     * @return array{0: list<Finding>, 1: bool}
     */
    private function materialize(iterable $findings): array
    {
        $out = [];
        $declaredSilence = false;
        foreach ($findings as $finding) {
            if ($finding instanceof Silence) {
                $declaredSilence = true;

                continue;
            }
            $out[] = $finding;
        }

        return [$out, $declaredSilence];
    }
}
