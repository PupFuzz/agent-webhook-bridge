<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\SubscriptionRegistry;
use Illuminate\Support\Facades\Log;

/**
 * A ROSTER LOOKUP: agent name → that agent's `identity.kanban_user_id` (card#9170) — the
 * server-side resolution that lets {@see BoardTakeCardTool} write an assignee without ever
 * taking a user id from the payload.
 *
 * ⛔⭐ READ THIS BEFORE YOU CALL IT — WHAT IS AND IS NOT GUARANTEED. There is NO agent-name
 * parameter: {@see forCallingSeat} reads {@see CallingSeat}, the write-once seat the front
 * door sealed at dispatch entry, and there is no expressible call that asks it about anybody
 * else. ⚠ The lookup UNDERNEATH is still a whole-roster scan matching on a name — that has
 * not changed and is not a defect; what changed is that no caller supplies the name.
 *
 * ⛔⭐ AND THAT IS A REVERSAL OF DL-372 DECISION 7, MADE ON MEASUREMENT AND APPROVED BY THE
 * OPERATOR — the correction is the useful part. Until this change the self-only property was
 * a property of the CALL GRAPH, held by a source-code census asserting that every call site
 * passed the door-derived `$agentName`. **That census could not hold.** Measured, executing,
 * with the suite green: a reference alias (`$ref = &$agentName; $ref = $args['agent'];`)
 * defeats the rebind leg, and eleven further shapes do too — `extract()`, variable variables,
 * by-reference out-params (`preg_match`, `sscanf`), `foreach` binding, destructuring, `??=`,
 * a closure parameter of the same name — several of which leave NO `$agentName =` token in
 * the body at all. Closing that textually is data-flow analysis, and there is no grammar that
 * bounds it. A parameter that must not be poisoned is the wrong shape for the guarantee, so
 * the parameter is GONE.
 *
 * ⭐ THE PROPERTY IS NOW A ONE-SHOT STATE'S, AND THE REGRESS BOTTOMS OUT ON IT. DL-372 was
 * right that no VALUE can carry this guarantee in PHP — whatever mints a value, something
 * else can mint another. A state that may be written ONCE PER PROCESS is not a value: a
 * second write is a THROW, both orderings fail closed, and the forger's problem stops being
 * "can I construct one" and becomes "can I be first" — which it can only lose loudly.
 * {@see CallingSeat} owns that argument and its bounds (reflection, the process model);
 * they are stated there and deliberately not restated here.
 *
 * ⛔ AND IT IS STILL NOT A SEAT MAP, in the sense that matters across the repo boundary
 * (canon #7). It answers name → id, from THIS install's own roster, for a name this install
 * derived; it has no id → name direction, no enumeration, and no second product keying on
 * it. The toolkit's `kbcard` resolves its own board env to RENDER a name for an id; this
 * reads its own roster to WRITE its own id. Nothing crosses, so there is nothing to drift —
 * and a change that gave this class an enumeration or a reverse lookup would be minting
 * exactly the shared table the design exists to avoid.
 *
 * ⚠ IT RE-READS THE ROSTER RATHER THAN BEING THREADED THROUGH {@see Tool::call}, and
 * {@see Tool::call}'s SIGNATURE IS UNTOUCHED BY THIS CHANGE — the seat travels in a static,
 * not in a new parameter, so the extension point operators register their own tools against
 * does not move. ⛔ Two of DL-372's three stated reasons for declining a typed
 * door-derivation were measured FALSE and are not repeated here: the name ALREADY travels
 * through `Tool::call` as `$agentName`, and *"documented extension point"* is documented in
 * 0 of the 5 docs consulted. The third — PHP has no friend visibility, so a private
 * constructor only forces minting through a factory whose argument types
 * (`ResolvedBoardToolAgent`, `AgentConfig`) any code in the app can hold — was measured TRUE,
 * and it is why the answer is a one-shot STATE and not a value object. The read itself is the
 * SAME authoritative source both front doors already used to authenticate this very call, so
 * it cannot answer about a different roster than the one that resolved the agent. What it CAN
 * see is a roster that changed between the two reads, which is why {@see NOT_IN_ROSTER} is a
 * real state and not a defensive one.
 *
 * ⛔ AND A `kanban_user_id` DECLARED BY MORE THAN ONE AGENT IS ONE OF THOSE FAULTS, because
 * an id that names two seats does not identify the CALLER. `assigned_user_id` is a kanban
 * USER, not a seat: nothing downstream of this method can tell two seats sharing an id apart,
 * so `board_take_card` would answer seat `a` `taken: true, already_held: true` for a card
 * seat `b` is working — the loser believing it holds claimed work, which is the one state
 * that tool exists to make visible. ⚠ The install state is REACHABLE: `AgentRegistry` WARNS
 * on a shared id and excludes it from attribution rather than refusing, and `bridge:check`
 * surfaces that at exit 0, so an install runs in it. This class is the door's last chance to
 * say so, and it refuses rather than write a claim it cannot attribute.
 *
 * ⛔ THERE IS NO SUPPORTED WAY TO DECLARE THE SHARING DELIBERATE, AND THAT IS A RULING RATHER
 * THAN A MISSING FEATURE (card#9170 operator gate). `shared-identities.json` declares a shared
 * **github** account and there is deliberately no kanban analogue: the github case is
 * declarable because something else supplies the attribution afterwards (a custom classifier
 * re-attributes, which is why a shared github account resolves to a null name ON PURPOSE).
 * Nothing re-attributes on the kanban axis, so a declaration could only record that the
 * brokenness is intended — and the brokenness is not local to this door: `AgentRegistry`
 * excludes BOTH colliding agents from `byKanbanUid`, so a shared id already resolves to
 * NOBODY on every consumer of it, kanban wake-routing included. The remedy is one line and it
 * is the same one the registry's own warning gives: a distinct `identity.kanban_user_id` per
 * agent. `docs/config-schema.md`'s `kanban_user_id` row OWNS that position; it is pointed at
 * rather than restated.
 *
 * ⚠ AND THE REFUSAL'S GUARANTEE IS INSTALL-LOCAL — a bound on the check, not a hole in it.
 * The scan is one roster: {@see SubscriptionRegistry} globs a single `config_dir`, so two
 * SEPARATE bridge installs whose YAMLs declare the same `kanban_user_id` collide on the board
 * and are invisible to each other here. Nothing in this tree can see that, which is why it is
 * stated rather than implied; within one roster the refusal is fail-closed.
 *
 * EVERY FAILURE IS AN INSTALL FAULT, NAMED AS ONE, AND PERMANENT. A seat cannot fix any of
 * them by changing its arguments, so each is a {@see ToolRefusalException} (422-class)
 * carrying the config key or file the operator must go and look at — never a bare refusal
 * and never the dispatcher's retryable 502, which would send the seat into the DL-020
 * retry loop for a cause no retry can clear.
 */
final class SeatKanbanUser
{
    /** The state where the roster no longer carries the agent the door authenticated. */
    private const NOT_IN_ROSTER = 'not_in_roster';

    /** The state where the answer would not IDENTIFY the caller: two agents, one id. */
    private const SHARED_KANBAN_USER_ID = 'shared_kanban_user_id';

    /**
     * The kanban user id declared for THE SEAT THIS PROCESS IS SERVING, or a named
     * INSTALL-fault refusal.
     *
     * ⛔ THERE IS NO NAME PARAMETER, AND THAT ABSENCE IS THE GUARANTEE. The seat comes from
     * {@see CallingSeat::name()}, which only a front door establishes and only once — so
     * "which seat" is not a value any caller of this method supplies, gets wrong, or can be
     * talked into supplying.
     *
     * ⚠ AND THE RUNTIME DOES NOT ENFORCE THAT — MEASURED, PHP 8.5.9. A call site written
     * `forCallingSeat($args['agent'], 'zz')` does NOT raise `ArgumentCountError`: PHP raises
     * that for too FEW arguments to a userland function and silently IGNORES extra ones, so
     * the payload binds to `$tool` and is spliced into a refusal MESSAGE. The identity is
     * untouched either way — that is the point of the seat not being a parameter — but the
     * rejection comes from **phpstan at level 7** (`arguments.count`) and from
     * `SeatIdentityCallSiteGuardTest`'s set-equality census, never from the language.
     *
     * @param  string  $tool  the tool name every message is prefixed with, so the seat reads
     *                        a refusal from the tool it called rather than from a helper
     *
     * @throws ToolRefusalException
     * @throws \LogicException if no front door established a seat for this process
     */
    public static function forCallingSeat(string $tool): int
    {
        $callingAgentName = CallingSeat::name();

        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (ConfigException $e) {
            Log::warning('board tools: could not read the agent roster to resolve the calling seat\'s own kanban user', [
                'agent' => $callingAgentName, 'tool' => $tool, 'error' => $e->getMessage(),
            ]);

            throw new ToolRefusalException("{$tool}: the bridge could not read its own agent configuration, so it cannot establish WHICH kanban user you are — and this door writes only the calling seat's own id, never one from your arguments. NOTHING WAS WRITTEN. This is an INSTALL fault, not something your arguments can fix; report it to your operator.");
        }

        $mine = null;
        foreach ($configs as $config) {
            // Case-SENSITIVE, the same narrow direction `board_correct_card`'s mint-stamp
            // compare takes: agent names are filesystem-cased config names, so `me` and `ME`
            // can be two seats, and a casefolded compare would resolve one seat's identity
            // for the other's call.
            if ($config->agentName === $callingAgentName) {
                $mine = $config;
                break;
            }
        }

        if ($mine !== null) {
            $kanbanUserId = $mine->identity->kanbanUserId;
            if ($kanbanUserId === null) {
                Log::warning('board tools: the calling agent declares no identity.kanban_user_id, so it has no id to assign itself', [
                    'agent' => $callingAgentName, 'tool' => $tool,
                ]);

                throw new ToolRefusalException("{$tool}: this bridge's config for agent `{$callingAgentName}` declares no `identity.kanban_user_id`, so there is no kanban user for the bridge to record as YOU — and this door writes only your own id, never one from your arguments. NOTHING WAS WRITTEN. This is an INSTALL fault: add `identity.kanban_user_id` to that agent's YAML (it is the same numeric id the board shows for your account) and report it to your operator.");
            }

            $sharing = [];
            foreach ($configs as $config) {
                if ($config->identity->kanbanUserId === $kanbanUserId) {
                    $sharing[] = $config->agentName;
                }
            }
            if (count($sharing) > 1) {
                sort($sharing);
                Log::warning('board tools: the calling agent\'s identity.kanban_user_id is declared by more than one agent, so it does not identify the caller', [
                    'agent' => $callingAgentName, 'tool' => $tool, 'kanban_user_id' => $kanbanUserId,
                    'agents' => $sharing, 'reason' => self::SHARED_KANBAN_USER_ID,
                ]);

                throw new ToolRefusalException("{$tool}: this bridge's config declares `identity.kanban_user_id` {$kanbanUserId} for MORE THAN ONE agent (".implode(', ', $sharing).'), so that id does not say WHICH seat you are — and a card recorded under it would tell every other seat that somebody holds the work without saying who, which is the one question this door exists to answer. NOTHING WAS WRITTEN. This is an INSTALL fault: give each agent a distinct `identity.kanban_user_id` (`bridge:check` already WARNS on this collision — it does not fail, so an install can run in this state for a long time) and report it to your operator.');
            }

            return $kanbanUserId;
        }

        Log::warning('board tools: the agent this call authenticated as is no longer in the roster', [
            'agent' => $callingAgentName, 'tool' => $tool, 'reason' => self::NOT_IN_ROSTER,
        ]);

        throw new ToolRefusalException("{$tool}: this bridge has no configuration for agent `{$callingAgentName}`, so it cannot establish which kanban user you are — the roster this call authenticated against no longer carries you (a YAML removed or renamed under the running bridge). NOTHING WAS WRITTEN. This is an INSTALL fault; report it to your operator.");
    }
}
