<?php

namespace App\Bridge\Check;

/**
 * A named position in `bridge:check`'s output where a group of registered checks runs
 * (DL-242, stage 1).
 *
 * WHY THIS EXISTS — stage 0's runner had one global scope and one per-agent scope, on
 * the assumption that each is invoked from a single place. Stage 1 falsified that on
 * BOTH: `CheckCommand` runs several distinct per-agent iterations (the main config loop,
 * and — since stage 7b — three more over the board-tools subsets), and its global check
 * units sat at positions separated by unmigrated inline code. A single `run()` call site
 * can only serve checks whose output is contiguous, which is true only once the migration
 * is finished.
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
     * The HEAD of the run: the two directories the install is built on — the config dir
     * it scans for agent YAMLs, and the secret dir holding the webhook secrets and API
     * tokens — each reported as resolvable-or-not and then for its permissions.
     */
    case Install = 'install';

    /**
     * The database binding, between the secret-dir leg and the inbox-surfacing one:
     * whether the install can reach a database, and whether it is the RIGHT one.
     */
    case Database = 'database';

    /** The inbox surfacing layout/mode config, between the database and retention legs. */
    case Inbox = 'inbox';

    /** The install-wide retention posture, after the inbox-surfacing leg. */
    case Retention = 'retention';

    /**
     * The per-install PROVIDER plane, after the retention leg and before the per-agent
     * config iteration: the endpoint URLs this install was configured with, and whether
     * every configured provider has an adapter to receive for it.
     *
     * THE LAST GLOBAL SLOT BEFORE THE SCAN, and the reason {@see self::Install} could not
     * simply absorb it: three units of pre-loop output are separated by the database and
     * retention legs, which migrated first. The three slots collapse into one the moment
     * the enum does (see this enum's own docblock) — they are not three ideas.
     */
    case Providers = 'providers';

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

    /**
     * The event-follows-consumer plane, after the whole `writeback.json` envelope and
     * before the board-tools one: does an enabled classifier consume what has actually
     * ARRIVED for each subscribed github scope?
     *
     * INDEPENDENT OF WRITEBACK, WHICH IS WHY IT IS ITS OWN SLOT rather than the tail of
     * {@see self::WritebackProbe}: a coord agent has no writeback at all, and this plane
     * still runs for it. Its position is after the envelope only because that is where
     * the inline code printed it.
     */
    case EventConsumer = 'event-consumer';

    /**
     * The HEAD of the board-tools plane: the scan for a DEFAULT-on `board_tools` block
     * that could not satisfy itself.
     *
     * IT RUNS OVER EVERY CONFIG, NOT THE ENABLED SUBSET, WHICH IS WHY IT IS ITS OWN
     * SLOT rather than the head of {@see self::BoardToolsBearer}. A suppressed block is
     * `enabled === false`, so every slot below skips it silently — this is the only
     * place its failure surfaces, and it must fire on a fleet whose ONLY board-tools
     * agent is suppressed (where the subset below is empty and nothing else runs).
     */
    case BoardToolsSuppression = 'board-tools-suppression';

    /**
     * The board-tools BEARER plane, reached only when some agent has the block enabled:
     * the token-readability and token-collision problems the resolver accumulated at
     * construction.
     */
    case BoardToolsBearer = 'board-tools-bearer';

    /**
     * The per-agent board-STATE plane, inside the board-tools kanban-client envelope:
     * everything that needs a live board read (visibility, swimlanes, create stage, the
     * coordination board).
     *
     * PER-AGENT AND INSIDE AN ENVELOPE — the pairing is the point. Its guard is the
     * SECOND kanban client ({@see CheckContext::$boardToolsClient}), whose construction
     * failure skips this slot and the two ssh slots below with one warn.
     */
    case BoardToolsState = 'board-tools-state';

    /** Inside the board-tools ssh-agent iteration, before the DL-225 advisory. */
    case BoardToolsSsh = 'board-tools-ssh';

    /**
     * The DL-225 flipped-default advisory, in a SECOND pass over the same ssh agents.
     *
     * IT IS A SEPARATE SLOT BECAUSE ITS LOOP IS SEPARATE, and its loop is separate
     * because it reads {@see self::BoardToolsSsh}'s findings: the advisory fires per
     * agent but only once that slot has reported for EVERY agent, so folding it into
     * that slot would emit each agent's advisory before the next agent's pinned-line
     * result. It is the first slot whose input is another slot's REPORT rather than a
     * fact about the install (see {@see CheckContext::$sshSetupIncomplete}).
     */
    case BoardToolsSshAdvisory = 'board-tools-ssh-advisory';

    /** The opt-in `--probe-tools` HTTP live round-trip, after the whole offline plane. */
    case ProbeTools = 'probe-tools';

    /** The opt-in `--probe-tools-ssh` live round-trip, after the `--probe-tools` leg. */
    case ProbeToolsSsh = 'probe-tools-ssh';
}
