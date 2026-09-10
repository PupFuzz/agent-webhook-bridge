<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\SubscriptionRegistry;
use Illuminate\Support\Facades\Log;

/**
 * THE CALLING SEAT'S OWN KANBAN USER ID, RESOLVED SERVER-SIDE FROM THE AGENT REGISTRY
 * (card#9170) — the whole of what makes {@see BoardTakeCardTool} safe to expose on a
 * door driven by a lower-trust principal.
 *
 * ⛔ THE ID NEVER COMES FROM THE PAYLOAD, AND THAT IS A CONSTRUCTION RATHER THAN A
 * VALIDATION. This class takes ONE argument — the agent name the door DERIVED from the
 * bearer (http) or from the pinned forced command (ssh), never from the request body —
 * and answers with that agent's own `identity.kanban_user_id`. There is no parameter a
 * caller can influence, so "a seat may assign only itself" is not a rule anything has to
 * remember to check: there is no expressible call that writes another seat's id. A
 * validated `assigned_user_id` argument would have been the wrong shape for exactly the
 * reason validation is weaker than construction — it puts the property one forgotten
 * branch away from being false.
 *
 * ⭐ AND IT RESOLVES ONE SEAT: ITS OWN. Nothing here can look up another agent, by name
 * or by id, so the bridge never needs — and must never grow — a fleet-wide seat→kanban-user
 * map. That is the cross-repo shape canon #7 warns about: a table two products both key on
 * is a guarantee whose population neither repo contains, and there is nothing to drift when
 * each side resolves only what it owns (the toolkit's `kbcard` reads its own board env to
 * RENDER a name for an id; this reads its own roster to WRITE its own id).
 *
 * ⚠ IT RE-READS THE ROSTER RATHER THAN BEING THREADED THROUGH {@see Tool::call}. The
 * alternative — an extra parameter on the tool interface — is a breaking change to a
 * contract operators register their own tools against, for a value only this tool needs;
 * and the read is the SAME authoritative source both front doors already used to
 * authenticate this very call, so it cannot answer about a different roster than the one
 * that resolved the agent. What it CAN see is a roster that changed between the two reads,
 * which is why {@see NOT_IN_ROSTER} is a real state and not a defensive one.
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

    /**
     * This agent's own kanban user id, or a named INSTALL-fault refusal.
     *
     * @param  string  $tool  the tool name every message is prefixed with, so the seat reads
     *                        a refusal from the tool it called rather than from a helper
     *
     * @throws ToolRefusalException
     */
    public static function resolve(string $agentName, string $tool): int
    {
        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (ConfigException $e) {
            Log::warning('board tools: could not read the agent roster to resolve the calling seat\'s own kanban user', [
                'agent' => $agentName, 'tool' => $tool, 'error' => $e->getMessage(),
            ]);

            throw new ToolRefusalException("{$tool}: the bridge could not read its own agent configuration, so it cannot establish WHICH kanban user you are — and this door writes only the calling seat's own id, never one from your arguments. NOTHING WAS WRITTEN. This is an INSTALL fault, not something your arguments can fix; report it to your operator.");
        }

        foreach ($configs as $config) {
            // Case-SENSITIVE, the same narrow direction `board_correct_card`'s mint-stamp
            // compare takes: agent names are filesystem-cased config names, so `me` and `ME`
            // can be two seats, and a casefolded compare would resolve one seat's identity
            // for the other's call.
            if ($config->agentName !== $agentName) {
                continue;
            }

            $kanbanUserId = $config->identity->kanbanUserId;
            if ($kanbanUserId === null) {
                Log::warning('board tools: the calling agent declares no identity.kanban_user_id, so it has no id to assign itself', [
                    'agent' => $agentName, 'tool' => $tool,
                ]);

                throw new ToolRefusalException("{$tool}: this bridge's config for agent `{$agentName}` declares no `identity.kanban_user_id`, so there is no kanban user for the bridge to record as YOU — and this door writes only your own id, never one from your arguments. NOTHING WAS WRITTEN. This is an INSTALL fault: add `identity.kanban_user_id` to that agent's YAML (it is the same numeric id the board shows for your account) and report it to your operator.");
            }

            return $kanbanUserId;
        }

        Log::warning('board tools: the agent this call authenticated as is no longer in the roster', [
            'agent' => $agentName, 'tool' => $tool, 'reason' => self::NOT_IN_ROSTER,
        ]);

        throw new ToolRefusalException("{$tool}: this bridge has no configuration for agent `{$agentName}`, so it cannot establish which kanban user you are — the roster this call authenticated against no longer carries you (a YAML removed or renamed under the running bridge). NOTHING WAS WRITTEN. This is an INSTALL fault; report it to your operator.");
    }
}
