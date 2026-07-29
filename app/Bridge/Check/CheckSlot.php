<?php

namespace App\Bridge\Check;

/**
 * A named position in `bridge:check`'s output where a group of registered checks runs
 * (DL-242, stage 1).
 *
 * WHY THIS EXISTS — stage 0's runner had one global scope and one per-agent scope, on
 * the assumption that each is invoked from a single place. Stage 1 falsified that on
 * BOTH: `CheckCommand` runs two distinct per-agent iterations (the main config loop, and
 * the ssh-agent loop inside `checkBoardTools()`), and its global check units
 * sit at positions separated by unmigrated inline code (`--probe-tools` renders between
 * the two board-tools legs). A single `run()` call site can only serve checks whose
 * output is contiguous, which is true only once the migration is finished.
 *
 * A SLOT DECIDES WHERE OUTPUT LANDS, NEVER WHETHER A CHECK RUNS. Plan constraint (a) is
 * unchanged: every check is registered unconditionally, and "not applicable" is a
 * Finding it returns. Slots are the ordering mechanism constraint (b) already forced for
 * the per-agent scope, generalized — per-agent checks must stay interleaved at their
 * position, and so must global ones.
 *
 * TEMPORARY BY CONSTRUCTION. Slots exist because stages 1-7 leave inline code between
 * the migrated units. When the last unit migrates, the surviving inline code is gone,
 * every slot is adjacent, and the enum collapses to one ordered registry — the target
 * design's "build context → run registry → render → exit".
 */
enum CheckSlot: string
{
    /**
     * The database binding, between the secret-dir leg and the inbox-surfacing one:
     * whether the install can reach a database, and whether it is the RIGHT one.
     */
    case Database = 'database';

    /** The install-wide retention posture, after the inbox-surfacing leg. */
    case Retention = 'retention';

    /**
     * The HEAD of the per-agent config iteration, immediately after that agent's YAML
     * parsed: can its classifier be resolved at all?
     *
     * THE ONLY ABORT SLOT. `CheckCommand` reads this slot's report and `continue`s the
     * iteration on a `fail`, so every remaining leg for that agent is skipped — the
     * semantics the inline code held, where an unresolvable classifier meant there was
     * no point asserting anything else about the agent. That makes registration here
     * different in kind from every other slot: A CHECK WHOSE `fail` SHOULD NOT SKIP THE
     * AGENT'S REMAINING LEGS MUST NOT BE REGISTERED HERE. Today the slot holds one
     * check whose only `fail` is the resolution failure, so the coupling is exact; a
     * second one would widen the abort silently.
     */
    case AgentClassifier = 'agent-classifier';

    /**
     * Inside the per-agent config iteration, after the silent classifier-derivation
     * block: the advisories that read a LAZY `classifier.config` key. Separate from
     * {@see self::AgentClassifier} because these must NOT abort the agent — and reachable
     * only when that slot passed.
     */
    case AgentPolicy = 'agent-policy';

    /**
     * Inside the per-agent config iteration, after the silent github-scope
     * accumulation: this agent's secret/token files, its channel transport, and the
     * deployed channel-server snapshot.
     *
     * THE FIRST SLOT TO ABSORB ITS NEIGHBOURS RATHER THAN SPLIT. Before stage 5b it ran
     * *after* inline channel legs; those legs are now checks in it, so the slot moved up
     * to where they started and nothing unmigrated prints inside it. That is the
     * collapse this enum's docblock predicts — a slot boundary disappearing because the
     * inline code that forced it is gone.
     */
    case AgentConfig = 'agent-config';

    /**
     * The agent ROSTER/IDENTITY plane, after the per-agent config iteration has finished
     * and before the `writeback.json` load envelope.
     *
     * IT IS POST-LOOP BY NECESSITY, NOT BY LAYOUT. Everything registered here asserts
     * against the roster as a whole — collisions ACROSS agents, `treat_as_signal`
     * resolved against every known name, a default agent naming one of them — and that
     * roster does not exist until the last YAML has been read. This is the first slot
     * whose position is forced by what its checks READ rather than by where unmigrated
     * inline code happens to print.
     *
     * NAMED FOR THE ROSTER, NOT THE REGISTRY, on purpose: this program calls
     * {@see CheckRunner} "the check registry", and its checks read an
     * `App\Bridge\Support\AgentRegistry`. A case called `AgentRegistry` would collide
     * with both.
     */
    case AgentRoster = 'agent-roster';

    /**
     * The writeback CONFIG plane, inside the `writeback.json` load envelope and before
     * the writeback client is constructed. Everything here asserts on config alone, so
     * it fires on a half-configured install — which is where a writeback misconfig is
     * most likely and least visible.
     */
    case Writeback = 'writeback';

    /**
     * The writeback PROBE plane, inside the writeback-client envelope: everything that
     * needs a constructed `KanbanClient` and a live board read. Separate from
     * {@see self::Writeback} because its guard is different (the client must exist) and
     * because its legs THROW, which the config plane's do not.
     */
    case WritebackProbe = 'writeback-probe';

    /** Inside `checkBoardTools()`'s ssh-agent iteration, before the DL-225 advisory. */
    case BoardToolsSsh = 'board-tools-ssh';

    /** The opt-in `--probe-tools-ssh` live round-trip, after the `--probe-tools` leg. */
    case ProbeToolsSsh = 'probe-tools-ssh';
}
