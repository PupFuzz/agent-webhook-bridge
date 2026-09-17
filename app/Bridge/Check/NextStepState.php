<?php

namespace App\Bridge\Check;

/**
 * WHERE THE WIRING OF THIS INSTALL STOPPED, per agent (card#8959, DL-352; widened past board
 * tools by card#9150).
 *
 * ⛑ THE BOARD-TOOLS STATES AND THE GITHUB-SUBSCRIPTION STATES SHARE ONE ENUM, which is a
 * fact about the BLOCK and not a seam in this enum. What the NEXT STEPS block is for is
 * *this install is not wired end to end, here is the ONE command to run next* — DL-352 built
 * it with one subject because there was one, and the github-webhook leg is the second thing
 * that answers to that same sentence. A second block titled NEXT STEPS would be the surface
 * competing with itself; a state whose subject differs is not.
 *
 * ⭐ THIS DOCBLOCK IS THE ONE OWNER OF WHAT EACH STATE MEANS. `docs/check-json-contract.md`
 * § 7a carries the same definitions for the machine consumer, and every other surface —
 * the runbook callout, the changelog, a PR body — POINTS at § 7a rather than restating
 * them: the first cut restated the definition in five places and two had already diverged
 * before it merged.
 *
 * THE BOARD-TOOLS STATES ARE CUT along TWO axes, and both cuts are the whole design:
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
     *
     * ⛔ AN ABSENT BLOCK IS NECESSARY FOR THIS STATE AND, SINCE card#8973 / DL-360, NO
     * LONGER SUFFICIENT — an agent whose block this install has RECORDED as enabled and
     * whose config now has none is reported as LOST by `board_tools.lost`, and gets NO entry
     * here. The two would otherwise contradict each other on one screen: this state's whole
     * premise (DL-357 Decision 8) is that it is a QUESTION for the operator, the one state a
     * correctly-configured install can sit in forever, and its own "NO ⇒ set `enabled:
     * false`" answer would MUTE a FAIL printed two lines above. So the population is now
     * *no block AND no record of one* — `next_steps` being empty still means nothing is
     * OUTSTANDING, never that every agent has a window.
     */
    case NoBlock = 'no_block';

    /**
     * A block is present and THIS RUN COULD NOT MEASURE this bridge's half of the door:
     * (ssh) the pinned forced-command line probe returned `unvalidated`; (http) the bearer
     * token file could not be seen or read by THIS PROCESS (cards #5698 / #5778), which
     * says nothing about the web user whose resolver serves the door.
     *
     * ⚠ THE ssh HALF IS SEVERAL CAUSES AND NOT ONE, AND THESE ARE EXAMPLES OF THEM: an
     * `authorized_keys` file this run could not READ (typically because it was not root);
     * an `AuthorizedKeysFile` entry it could not RESOLVE (`%U` with no uid lookup), so the
     * pinned line may be in exactly that file (card#8976); the configured
     * `board_tools.ssh_account` never LOOKED UP at all, because this PHP process has no
     * `posix_getpwnam` — nothing was named, nothing was attempted, and whether that account
     * exists is UNKNOWN (DL-259). ⛔ DO NOT READ THEM AS A CLOSED SET, HERE OR IN
     * `docs/check-json-contract.md` § 7a:
     * the arms live in `SshTransportProbe`, a new one reaches this state without appearing
     * here, and prose that claimed to enumerate them was falsified three times inside one
     * card. `tests/Feature/Console/Check/UnvalidatedCallSiteTest.php` pins the set FROM THE
     * SOURCE; what this list is for is saying that the state is not one claim.
     *
     * ⛔ NOT A FAULT, AND THE REMEDY IS THE OPPOSITE OF ONE: re-run `bridge:check` as the
     * account that can read (`sudo`), and do not re-provision on this line alone. The leg
     * above names what it could not consult — a file, an `AuthorizedKeysFile` entry, or the
     * account itself.
     *
     * ⚠ THE COMMAND IS THE REMEDY FOR THE COMMON CAUSE, NOT FOR EVERY CAUSE (card#8976,
     * DL-359). The ssh leg's `unvalidated` arms that need a root-resolved
     * `AuthorizedKeysFile` to exist at all are reachable ONLY from a run that is ALREADY
     * root, so a privileged re-run cannot supply what they lacked: an entry this PHP
     * process could not RESOLVE (`%U` on a host with no `posix_getpwnam`, an extension
     * `sudo` does not install), and a file a root-resolved run could not OPEN. ⚑ THE
     * NEVER-LOOKED-UP-ACCOUNT ARM IS NOT ROOT-GATED AT ALL and `sudo` is no remedy for it
     * either — the missing extension IS the whole cause, and a privileged re-run installs
     * nothing. The state itself is still right on those arms — nothing was measured, and re-provisioning is
     * still the wrong move — so this is a BOUND on the command, not a state of its own:
     * `command` must name ONE command, no command supplies a missing PHP extension, and a
     * state whose `command` had to be fabricated would put a FALSE instruction in the
     * machine contract in place of a merely unhelpful one. The finding above names what
     * went unconsulted; the fix is there.
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

    /**
     * A github subscription this agent DECLARES has no webhook on the repo delivering to this
     * install's receiver — and this run established that by READING the repo's whole hook
     * list (card#9150). The one state whose subject is not board tools, and the one keyed to a
     * SCOPE rather than to the agent alone ({@see NextStep::$scope} carries it; it is null on
     * every other state). Command: `bridge:check`, once the hook has been added by hand.
     *
     * ⛔ IT IS A MEASURED ABSENCE AND NEVER A BLIND READ, and the distinction matters more
     * here than anywhere else in this enum: a run that could not enumerate the repo's hooks —
     * no token, a 403, a token without `admin:repo_hook`, a network failure — reports
     * `unvalidated` in `checks[]` and gets NO ENTRY HERE. Issuing this instruction off an
     * unmeasured read would send an operator to re-create a webhook that is already there,
     * which is {@see self::BridgeSideUnverified}'s cost in a different plane.
     *
     * ⛔ THE COMMAND IS NOT `bridge:provision-tools`, AND IT IS NOT `bridge:provision`
     * EITHER. `bridge:provision` skips every non-kanban provider by design (no repo-admin
     * token), so there is no command on this box that creates the thing. The remedy is
     * repo-settings work by a person with `admin:repo_hook`; the command named is the one a
     * BRIDGE reader can run to re-ask the question once they have done it — the same shape
     * {@see self::SeatSideUnreported} takes for the same reason.
     */
    case GithubWebhookMissing = 'github_webhook_missing';

    /**
     * The same measured absence one step more precise: a github subscription this agent
     * DECLARES has no webhook delivering to this install's receiver, AND the repo carries other
     * webhooks (card#9717). Keyed to a SCOPE like {@see self::GithubWebhookMissing}, and
     * {@see NextStep::$scope} carries it. Command: `bridge:check`, once someone has established
     * which of the two situations below this is and acted on it.
     *
     * ⛔ IT IS A SECOND STATE AND NOT A REWORDING, BECAUSE THE NEXT ACTION GENUINELY DIFFERS —
     * which is this enum's cut (see the class docblock: states are cut by WHOSE NEXT ACTION IT
     * IS). A repo carrying hooks that are not ours is EITHER an install whose own hook was
     * deleted while other integrations kept theirs, OR a repo already served by ANOTHER
     * install's bridge that this install still declares. The first is fixed by adding a hook;
     * the second by removing THIS install's declaration — and doing the first on the second
     * points a SECOND card-mover at one board, where two movers race on every PR event. So the
     * next action is *find out which install owns this repo*, not *add a hook*, and a state that
     * rendered both as {@see self::GithubWebhookMissing} would be issuing one of two opposite
     * instructions on a coin toss. That is card#7756's cost one surface over, and the same
     * reason {@see self::BridgeSideUnverified} split from {@see self::BridgeSideIncomplete}
     * rather than hedging in prose.
     *
     * ⛔ THE SEVERITY DOES NOT MOVE WITH THE STATE. The `checks[]` finding behind this is a
     * `fail`, exactly as {@see self::GithubWebhookMissing}'s is: this install is deaf on that
     * scope either way, and where the cause is a stale declaration the stale declaration is
     * itself the fault. What changes is the cause named and the remedy offered, never the
     * verdict.
     *
     * ⛔ WHAT THE OTHER HOOKS ARE IS NOT KNOWN HERE AND NEVER WILL BE. The leg counts the repo's
     * hooks and never reads their delivery URLs out of the box — that list is the whole fleet's
     * (DL-368 Decision 6) — so this state is *the repo carries hooks, none of them ours* and
     * NOT *another install serves this repo*. The second is a conclusion only a person with
     * sight of the repo's settings, or of the fleet's install list, can reach.
     */
    case GithubWebhookOtherHooksOnly = 'github_webhook_other_hooks_only';

    /**
     * A github subscription this agent DECLARES has gone quiet by its OWN DELIVERY RECORD — the receiver has recorded
     * no delivery for that scope at all, or none within the silence threshold derived from the scope's own gaps
     * between deliveries (DL-382). Keyed to a SCOPE like {@see self::GithubWebhookMissing}, and
     * {@see NextStep::$scope} carries it. Command: `bridge:check`, once someone who can see the repo's webhook
     * settings has looked.
     *
     * ⛔ IT IS AN INFERENCE FROM SILENCE, NOT A MEASURED FAULT, and the difference from {@see self::GithubWebhookMissing}
     * is the whole state: that one READ the repo's hook list and points at a `fail`; this one reads only what arrived,
     * points at a `warn`, and a genuinely quiet repo produces it too. It needs no token and no repo admin, which is
     * why it exists — it is the only github state a seat that does not administer the repo can reach.
     *
     * ⛔ IT WITNESSES THE DELIVERY SIDE ONLY: deliveries that arrive and are dropped before any agent wakes read as
     * healthy to the leg behind it, so the ABSENCE of this entry is not evidence an agent is being woken.
     *
     * ⚑ NEVER BESIDE {@see self::GithubWebhookMissing} FOR THE SAME SCOPE. Where this run read the hook list and found
     * the hook gone, that is the cause and already carries the remedy, so the silent record adds no entry of its own.
     */
    case GithubDeliverySilent = 'github_delivery_silent';
}
