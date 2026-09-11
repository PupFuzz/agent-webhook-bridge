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
 * ⛔⭐ READ THIS BEFORE YOU CALL IT — WHAT IS AND IS NOT GUARANTEED, because an earlier
 * revision of this docblock got it WRONG and the correction is the useful part. It said
 * *"there is no parameter a caller can influence"* and *"nothing here can look up another
 * agent"*. **Both were false OF THIS CLASS.** {@see forCallingAgent} iterates the whole
 * roster and matches on the name it is handed, so handed any agent's name it returns that
 * agent's id — measured on a three-agent roster, it answers 111, 222 and 333 for `me`,
 * `other` and `pm`. What does not exist is a fleet-wide seat→kanban-user MAP ARTIFACT; what
 * this class is, is a lookup that would serve one.
 *
 * ⭐ SO THE SELF-ONLY PROPERTY IS A PROPERTY OF THE CALL GRAPH, NOT OF THIS SIGNATURE, and
 * saying so plainly is the point: it holds because EVERY call site passes the `$agentName`
 * the DOOR derived (from the bearer on http, from the pinned ssh forced command) and never a
 * value from the request body. That is one site today —
 * {@see BoardTakeCardTool::call} — and one site is exactly the distance between the property
 * and being false. So it is not left to be remembered:
 * `Tests\Feature\AgentTools\SeatIdentityCallSiteGuardTest` GUARDS it — and that class OWNS
 * the census. What it derives, which SPELLINGS of a call its predicate can see and which one
 * it refuses outright, and what it still cannot reach, are stated THERE and deliberately not
 * restated here: this paragraph used to carry a copy, and the copy went on asserting the
 * census was complete while the predicate was blind to two of the three ways a second caller
 * can be spelled. A second caller is a REVIEW EVENT, not a silence — which is
 * what DL-372's own argument demands, having rejected a validated `assigned_user_id`
 * argument for putting the property *"one forgotten branch away from being false"*.
 *
 * ⛔ AND IT IS STILL NOT A SEAT MAP, in the sense that matters across the repo boundary
 * (canon #7). It answers name → id, from THIS install's own roster, for a name this install
 * derived; it has no id → name direction, no enumeration, and no second product keying on
 * it. The toolkit's `kbcard` resolves its own board env to RENDER a name for an id; this
 * reads its own roster to WRITE its own id. Nothing crosses, so there is nothing to drift —
 * and a change that gave this class an enumeration or a reverse lookup would be minting
 * exactly the shared table the design exists to avoid.
 *
 * ⚠ IT RE-READS THE ROSTER RATHER THAN BEING THREADED THROUGH {@see Tool::call}. The
 * alternative — an extra parameter on the tool interface, or a value object carrying the
 * door-derivation in its TYPE — is a breaking change to a contract operators register their
 * own tools against, for a value only this tool needs; and PHP has no friend visibility, so
 * a "only the doors may mint it" type is not expressible anyway: a private constructor only
 * forces minting through a factory whose argument types (`ResolvedBoardToolAgent`,
 * `AgentConfig`) any code in the app can hold. The guard above is falsifiable and has been
 * watched fail; that type would not have been. And
 * and the read is the SAME authoritative source both front doors already used to
 * authenticate this very call, so it cannot answer about a different roster than the one
 * that resolved the agent. What it CAN see is a roster that changed between the two reads,
 * which is why {@see NOT_IN_ROSTER} is a real state and not a defensive one.
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
     * The kanban user id declared for $callingAgentName, or a named INSTALL-fault refusal.
     *
     * ⛔ IT ANSWERS ABOUT WHATEVER NAME IT IS HANDED. The method is named for its ONE
     * legitimate argument rather than for what it mechanically does, so a call site passing
     * anything else READS wrong — but a name is not a check, and the check is the call-site
     * guard the class docblock names.
     *
     * @param  string  $callingAgentName  the agent name the DOOR derived — the bearer's
     *                                    (http) or the pinned forced command's (ssh). Never
     *                                    a value read out of the request body, and never
     *                                    another agent's.
     * @param  string  $tool  the tool name every message is prefixed with, so the seat reads
     *                        a refusal from the tool it called rather than from a helper
     *
     * @throws ToolRefusalException
     */
    public static function forCallingAgent(string $callingAgentName, string $tool): int
    {
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
