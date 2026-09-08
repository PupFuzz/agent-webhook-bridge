<?php

namespace App\Bridge\Check;

/**
 * WHERE an agent's board-tools enablement stopped (card#8959, DL-352).
 *
 * ⭐ THIS DOCBLOCK IS THE ONE OWNER OF WHAT EACH STATE MEANS. `docs/check-json-contract.md`
 * § 7a carries the same definitions for the machine consumer, and every other surface —
 * the runbook callout, the changelog, a PR body — POINTS at § 7a rather than restating
 * them: the first cut restated the definition in five places and two had already diverged
 * before it merged.
 *
 * FOUR STATES, cut along TWO axes, and both cuts are the whole design:
 *   - by WHOSE NEXT ACTION IT IS, not by which check reported it. `bridge:check` already
 *     prints each fault at its own severity with its own cure; what the NEXT STEPS block
 *     adds is the one command to run next, so two faults taking the same command are one
 *     state here even though they are two lines above.
 *   - by WHETHER THE BRIDGE HALF WAS MEASURED. A leg that could not look (this run was not
 *     root; the token file was not readable by this process) reports `unvalidated`, and
 *     that is a different state from a measured fault with the OPPOSITE remedy: re-run as
 *     the account that can read, never re-provision. Collapsing the two sent a correctly
 *     wired install to re-provision — card#7756's exact cost, one surface over — so
 *     `state` splits them rather than hedging in prose.
 *
 * ⛔ AN EXPLICIT `enabled: false` IS NOT A STATE — it is a deliberate opt-out (a staged
 * agent), and telling its operator to provision it would be inventing an obligation from a
 * setting whose whole purpose is to decline one. A DEFAULT-suppressed block is different
 * and IS {@see self::BridgeSideIncomplete}: nobody chose it, the block cannot satisfy
 * itself, and `bridge:check` already FAILs on it.
 *
 * FIRST MATCH WINS, in declaration order: an agent with no block cannot have a bearer
 * fault, an unmeasured bridge half cannot be called broken, and a broken bridge half has
 * nothing to ask of its seat yet.
 */
enum NextStepState: string
{
    /**
     * The agent's YAML carries no `board_tools:` block at all, so this agent has no board
     * window — the state every install starts in. Command: `bridge:provision-tools
     * --agent=<name>` (prints a paste-ready skeleton; never edits YAML).
     */
    case NoBlock = 'no_block';

    /**
     * A block is present and THIS RUN COULD NOT MEASURE this bridge's half of the door:
     * (ssh) the pinned forced-command line probe returned `unvalidated` — the
     * `authorized_keys` it needed was not readable by this run, typically because it was
     * not root; (http) the bearer token file could not be seen or read by THIS PROCESS
     * (cards #5698 / #5778), which says nothing about the web user whose resolver serves
     * the door.
     *
     * ⛔ NOT A FAULT, AND THE REMEDY IS THE OPPOSITE OF ONE: re-run `bridge:check` as the
     * account that can read (`sudo`), and do not re-provision on this line alone. The leg
     * above names which read was blocked.
     *
     * ⚠ THE COMMAND IS THE REMEDY FOR THE COMMON CAUSE, NOT FOR EVERY CAUSE (card#8976,
     * DL-359). Two of the ssh leg's `unvalidated` arms are reachable ONLY from a run that is
     * ALREADY root — both need a root-resolved `AuthorizedKeysFile` to exist at all — so a
     * privileged re-run cannot supply what they lacked: an entry this PHP process could not
     * RESOLVE (`%U` on a host with no `posix_getpwnam`, an extension `sudo` does not
     * install), and a file a root-resolved run could not OPEN. The state itself is still
     * right on those arms — nothing was measured, and re-provisioning is still the wrong
     * move — so this is a BOUND on the command, not a fifth state: `command` must name ONE
     * command, no command supplies a missing PHP extension, and a state whose `command` had
     * to be fabricated would put a FALSE instruction in the machine contract in place of a
     * merely unhelpful one. The finding above names what went unconsulted; the fix is there.
     */
    case BridgeSideUnverified = 'bridge_side_unverified';

    /**
     * A block is present and this bridge's half of the door was MEASURED AND FOUND NOT
     * USABLE: a default-on block that could not satisfy itself (`suppressedReason`); an
     * http bearer that resolved absent, blank, insecure, or shared with a sibling; an ssh
     * pinned line that is absent, ambiguous, or grants what the forced command must deny.
     * Command: `bridge:provision-tools --agent=<name>` — transport-aware, so one spelling
     * serves both doors.
     *
     * THE FAULT IS NOT RESTATED IN THE ENTRY. Each of those is already one reported finding
     * above, with its own cause and cure; the entry names the command that acts on whichever
     * one fired.
     */
    case BridgeSideIncomplete = 'bridge_side_incomplete';

    /**
     * The bridge half is complete and no successful board-tools call has ever been observed
     * for this agent. Command: `bridge:check`, after the seat has called.
     *
     * ⛔ IT IS NOT A CLAIM THAT THE SEAT IS UNWIRED, and cannot be: the bridge may not read
     * the seat's own `.mcp.json` or keypair (DL-229 — an account may only read its own
     * files), so a seat that was never wired and one that is simply idle are THE SAME
     * ABSENCE from here. {@see Checks\BoardToolsClientHalfCheck} owns that reasoning; this
     * state is the same absence rendered as a next action. And `--probe-tools` does not
     * clear it honestly: that probe stamps the same ledger row from the bridge box.
     */
    case SeatSideUnreported = 'seat_side_unreported';
}
