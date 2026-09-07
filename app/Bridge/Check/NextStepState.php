<?php

namespace App\Bridge\Check;

/**
 * WHERE an agent's board-tools enablement stopped (card#8959, DL-352).
 *
 * THREE STATES, NOT ONE PER FAULT, and the cut is by WHOSE NEXT ACTION IT IS rather
 * than by which check reported it. `bridge:check` already prints the fault per leg; what
 * the NEXT STEPS block adds is the one command to run next, so two faults that take the
 * same command are one state here even though they are two lines above. A state per fault
 * would have made the `command` field redundant with the state and reproduced the leg
 * output a second time, in a second voice.
 *
 * ⛔ AN EXPLICIT `enabled: false` IS NOT A STATE — it is a deliberate opt-out (a staged
 * agent), and telling its operator to provision it would be inventing an obligation from a
 * setting whose whole purpose is to decline one. A DEFAULT-suppressed block is different
 * and IS {@see self::BridgeSideIncomplete}: nobody chose it, the block cannot satisfy
 * itself, and `bridge:check` already FAILs on it.
 */
enum NextStepState: string
{
    /**
     * The agent's YAML carries no `board_tools:` block at all, so this agent has no board
     * window — the state every install starts in.
     */
    case NoBlock = 'no_block';

    /**
     * A block is present, and THIS BRIDGE's half of the door is not usable yet: a
     * default-on block that could not satisfy itself, an http agent whose bearer did not
     * resolve, or an ssh agent whose pinned forced-command line is absent, wrong, or
     * unreadable by this run.
     *
     * THE FAULT IS NOT RESTATED IN THE ENTRY. Each of those is already one reported
     * finding above, with its own cause and cure; the entry names the command that acts
     * on whichever one fired.
     */
    case BridgeSideIncomplete = 'bridge_side_incomplete';

    /**
     * The bridge half is complete and no successful board-tools call has ever been
     * observed for this agent.
     *
     * ⛔ IT IS NOT A CLAIM THAT THE SEAT IS UNWIRED, and cannot be: the bridge may not
     * read the seat's own `.mcp.json` or keypair (DL-229 — an account may only read its
     * own files), so a seat that was never wired and one that is simply idle are THE SAME
     * ABSENCE from here. {@see Checks\BoardToolsClientHalfCheck} owns that reasoning; this
     * state is the same absence rendered as a next action.
     */
    case SeatSideUnreported = 'seat_side_unreported';
}
