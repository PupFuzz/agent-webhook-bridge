<?php

namespace App\Bridge\Check;

use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;

/**
 * One `bridge:check` check that runs once per run (DL-242).
 *
 * This is not a new abstraction: it promotes the shape `ChannelSnapshotProbe` and
 * `SshTransportProbe` already have (`… → Finding[]`) into the contract the other
 * ~125 raw `warn()`/`error()` emission sites in `CheckCommand::handle()` should have
 * been using. Raw `warn()` cannot represent "did not run", so a raw leg's not-run
 * state is indistinguishable from its pass state — the generator that keeps minting
 * the silent-failure card. {@see Severity::Unvalidated} is how a
 * check says it could not run, and returning a Finding is how it says anything at all.
 *
 * Per-agent legs implement {@see PerAgentCheck} instead: their output is emitted
 * INSIDE the config iteration, and hoisting them would reorder output.
 */
interface Check
{
    /**
     * Stable machine id, e.g. `writeback.source_coverage`. It keys the inventory, so
     * it must be unique across the registry (enforced by {@see CheckRunner::register})
     * and must not change once shipped — a renamed id reads as one check disappearing
     * and another appearing.
     */
    public function id(): string;

    /**
     * @return iterable<Finding|Silence> Yield `unvalidated` when the check could not run, so
     *                                   a did-not-measure is never rendered as a pass.
     *
     * YIELDING NOTHING IS LEGAL AND IS RECORDED, NOT LOST. An earlier revision of this
     * docblock required at least one finding and said stage 8 would enforce it. Stage 8
     * MEASURED that first: 26 of the 37 checks registered AT THAT TIME yielded nothing on
     * at least one install shape and most were silent on the baseline, because "no identity
     * collisions" is correctly reported by saying nothing. Enforcing the old wording would
     * have meant a line of mostly-`ok` per registered check on every run. (The figures are
     * that measurement and are deliberately not re-derived by hand as the registered set
     * grows; the committed `minimal` golden file states the current run's inventory.) What is enforced instead is that a run which
     * COMPLETES ACCOUNTS for every registered check — see {@see CheckDisposition} — so
     * silence is now counted rather than absent. (A check that throws still aborts the
     * command before anything renders the account; {@see CheckRunner} does not catch.)
     *
     * A SILENCE MUST BE DECLARED (card#5596). Yielding nothing is still legal, but a
     * DELIBERATE silence says so by yielding a {@see Silence} on the path that produced it
     * — an execution that yields neither a finding nor a declaration is recorded as an
     * undeclared silence and disclosed to the operator as an internal defect
     * ({@see CheckInventory::undeclaredSilent()}). That closes the bound stage 8 disclosed
     * here: falling off the end of the generator by accident no longer looks like the check
     * correctly having nothing to say. It does NOT establish that a declared silence is
     * RIGHT — see {@see Silence} for what the declaration does and does not claim.
     */
    public function run(CheckContext $ctx): iterable;
}
