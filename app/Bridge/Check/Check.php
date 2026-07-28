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
     * @return iterable<Finding> MUST yield at least one finding — `unvalidated` when
     *                           the check could not run. Yielding nothing is how a leg
     *                           becomes invisible to the inventory, which is the failure
     *                           this registry exists to make impossible. The invariant is
     *                           only ENFORCED from stage 8; until then it is a contract
     *                           this docblock states and reviewers hold.
     */
    public function run(CheckContext $ctx): iterable;
}
