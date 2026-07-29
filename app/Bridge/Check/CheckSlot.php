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

    /** Inside the per-agent config iteration, after that agent's channel legs. */
    case AgentConfig = 'agent-config';

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
