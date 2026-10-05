<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\AgentKanbanUsers;
use App\Bridge\Support\CoordConfigFile;
use App\Bridge\Support\RosterKanbanUser;
use App\Bridge\Support\SubscriptionRegistry;
use Illuminate\Support\Facades\Log;

/**
 * A ROSTER LOOKUP: the calling seat → its kanban user id in the COORD ROSTER (card#9170; the
 * roster as its source since card#11172 / DL-450) — the server-side resolution that lets
 * {@see BoardTakeCardTool} write an assignee without ever taking a user id from the payload, and
 * that {@see BoardCorrectCardTool} COMPARES with a card's assignee to decide whether a correction
 * is authorized by assignment (DL-376). Both consumers ask about the calling seat and nobody
 * else, which is the only question this answers.
 *
 * ⭐ THE ID HAS ONE SOURCE. The seat is the agent's `identity.coord_seat`, else its agent name, and
 * its id is that seat's `roster[].kanban_user_id[<kanban host>]` in the file
 * `BRIDGE_COORD_CONFIG_PATH` names, read through {@see AgentKanbanUsers} — the same read event
 * attribution uses, so the two cannot disagree about who a seat is. ⛔ `identity.kanban_user_id`
 * in the agent's YAML is NOT read, not even as a fallback when the roster cannot answer: a copy
 * consulted only when the store is broken is a copy that decides exactly when nobody is checking
 * it.
 *
 * ⛔⭐ READ THIS BEFORE YOU CALL IT — WHAT IS AND IS NOT GUARANTEED. There is NO agent-name
 * parameter: {@see forCallingSeat} reads {@see CallingSeat}, the write-once seat the front
 * door sealed at dispatch entry, and there is no expressible call that asks it about anybody
 * else. ⚠ The lookup UNDERNEATH is still a whole-config scan matching on a name — that has
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
 * ⛔ AND IT IS STILL NOT A SEAT MAP the bridge publishes. It answers seat → id for the calling
 * seat only; it has no id → name direction and no enumeration a consumer could key on. The
 * map it reads is the coord roster, whose owner is the framework and whose reader of record is
 * the toolkit — the bridge reads that one store rather than keeping a second.
 *
 * ⚠ IT RE-READS THE CONFIG RATHER THAN BEING THREADED THROUGH {@see Tool::call}, and
 * {@see Tool::call}'s SIGNATURE IS UNTOUCHED — the seat travels in a static, not in a new
 * parameter, so the extension point operators register their own tools against does not move.
 * ⛔ Two of DL-372's three stated reasons for declining a typed door-derivation were measured
 * FALSE and are not repeated here: the name ALREADY travels through `Tool::call` as
 * `$agentName`, and *"documented extension point"* is documented in 0 of the 5 docs consulted.
 * The third — PHP has no friend visibility, so a private constructor only forces minting
 * through a factory whose argument types (`ResolvedBoardToolAgent`, `AgentConfig`) any code in
 * the app can hold — was measured TRUE, and it is why the answer is a one-shot STATE and not a
 * value object. The agent-config read is the SAME authoritative source both front doors already
 * used to authenticate this very call, so it cannot answer about a different config than the
 * one that resolved the agent. What it CAN see is a config that changed between the two reads,
 * which is why {@see NOT_IN_ROSTER} is a real state and not a defensive one.
 *
 * ⛔ AND AN ID THE ROSTER GIVES MORE THAN ONE SEAT IS ONE OF THOSE FAULTS, because an id that
 * names two seats does not identify the CALLER. `assigned_user_id` is a kanban USER, not a
 * seat: nothing downstream of this method can tell two seats sharing an id apart, so
 * `board_take_card` would answer seat `a` `taken: true, already_held: true` for a card seat `b`
 * is working — the loser believing it holds claimed work, which is the one state that tool
 * exists to make visible. ⭐ TWO BRIDGE AGENTS ON ONE SEAT are not that fault while at most one of
 * them can take cards — the id names the seat, and only one agent claims as it (`bridge:check`
 * warns on the layout, and `AgentRegistry` attributes that seat's events to neither agent by
 * name). ⛔ Two BOARD-TOOLS agents on one seat ARE: either could claim, and the id cannot say
 * which did, so both are refused as `install_fault.shared_kanban_user`.
 *
 * ⚠ AND THE REFUSAL'S GUARANTEE IS INSTALL-LOCAL — a bound on the check, not a hole in it. The
 * seats compared are the ones THIS bridge's agents serve ({@see AgentKanbanUsers::otherSeatsWithId}),
 * so two seats on SEPARATE installs that the roster gives one id are invisible to each other
 * here, as two installs' YAML ids were before DL-450. Within one install the refusal is
 * fail-closed.
 *
 * ⛔ THERE IS NO SUPPORTED WAY TO DECLARE A SHARED KANBAN USER DELIBERATE, AND THAT IS A RULING
 * RATHER THAN A MISSING FEATURE (card#9170 operator gate). `shared-identities.json` declares a
 * shared **github** account and there is deliberately no kanban analogue: the github case is
 * declarable because something else supplies the attribution afterwards (a custom classifier
 * re-attributes, which is why a shared github account resolves to a null name ON PURPOSE).
 * Nothing re-attributes on the kanban axis, so a declaration could only record that the
 * brokenness is intended. The remedy is one kanban user per seat in the roster.
 * `docs/config-schema.md` § identity OWNS that position; it is pointed at rather than restated.
 *
 * EVERY FAILURE IS AN INSTALL FAULT, NAMED AS ONE, AND PERMANENT. A seat cannot fix any of
 * them by changing its arguments, so each is a {@see ToolRefusalException} (422-class)
 * carrying its own `install_fault.*` reason and the setting or file the operator must go and
 * look at — never a bare refusal and never the dispatcher's retryable 502, which would send the
 * seat into the DL-020 retry loop for a cause no retry can clear.
 */
final class SeatKanbanUser
{
    /** The state where the agent config no longer carries the agent the door authenticated. */
    private const NOT_IN_ROSTER = 'not_in_roster';

    /** The state where the answer would not IDENTIFY the caller: two seats, one id. */
    private const SHARED_KANBAN_USER_ID = 'shared_kanban_user_id';

    /**
     * The kanban user id the coord roster gives THE SEAT THIS PROCESS IS SERVING, or a named
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
        [$callingAgentName, $kanbanUserId, $seatName, $missing] = self::lookup($tool);
        if ($kanbanUserId !== null) {
            return $kanbanUserId;
        }

        $path = $missing->file->shownPath();
        $verdict = $missing->verdictFor($callingAgentName);
        Log::warning('board tools: the coord roster gives the calling seat no kanban user id, so it has no id to assign itself', [
            'agent' => $callingAgentName, 'tool' => $tool, 'seat' => $seatName, 'roster' => $path, 'verdict' => $verdict->why,
        ]);
        $tail = ' NOTHING WAS WRITTEN — this door writes only your own id, never one from your arguments, and never one from a copy. This is an INSTALL fault, not something your arguments can fix; report it to your operator (`php artisan bridge:check` names it too).';

        if ($verdict->why === RosterKanbanUser::ABSENT) {
            throw new ToolRefusalException("{$tool}: the coord roster at {$path} has no seat named '{$seatName}', which is the seat your agent `{$callingAgentName}` serves (its `identity.coord_seat`, else its agent name) — so there is no kanban user for the bridge to record as YOU.{$tail}", installFault: true, reason: 'install_fault.roster_seat_absent');
        }

        throw new ToolRefusalException("{$tool}: the coord roster at {$path} gives seat '{$seatName}' no kanban user id for this kanban instance ('{$missing->host}') — roster verdict `{$verdict->why}`; the id is written there as \"kanban_user_id\": {\"{$missing->host}\": <id>} once the seat has its own kanban account — so there is no kanban user for the bridge to record as YOU.{$tail}", installFault: true, reason: 'install_fault.no_kanban_user');
    }

    /**
     * The kanban user id the coord roster gives the seat this process is serving, or NULL when the
     * roster gives that seat NONE — the seat is absent from it, or carries no id for this host —
     * for a consumer that only COMPARES a card against the caller (`board_correct_card`'s assignee
     * arm, DL-376) rather than writing the caller's id.
     *
     * ⭐ NULL IS A REAL ANSWER HERE, NOT A FAULT. A seat the roster gives no kanban user is a seat no
     * card can be assigned to, so "compare against nothing" is the true reading. That is the one
     * difference from {@see forCallingSeat}, which must WRITE an id and therefore refuses there.
     * ⛔ Every OTHER state is still a named INSTALL-fault refusal and NEVER null — a roster that
     * cannot be read, an agent the config no longer carries, an id two seats share all mean this
     * run cannot say who the caller is, and a comparer treating that as "unassigned" would be
     * answering a question it could not ask.
     *
     * @param  string  $tool  the tool name every refusal is prefixed with
     *
     * @throws ToolRefusalException
     * @throws \LogicException if no front door established a seat for this process
     */
    public static function declaredForCallingSeat(string $tool): ?int
    {
        return self::lookup($tool)[1];
    }

    /**
     * {@see declaredForCallingSeat}'s answer WITH the roster's reason when it is null — the seat is
     * absent from the roster ({@see RosterKanbanUser::ABSENT}), or present with no usable id for this
     * kanban host (every other verdict) — for a reader that reports which of the two it was
     * (`board_my_cards`' `selection`, card#11267). The same one lookup, so the id and the reason
     * cannot answer about different configs; every state that is a refusal there is one here.
     *
     * @param  string  $tool  the tool name every refusal is prefixed with
     *
     * @throws ToolRefusalException
     * @throws \LogicException if no front door established a seat for this process
     */
    public static function declaredVerdictForCallingSeat(string $tool): RosterKanbanUser
    {
        [$callingAgentName, , , $roster] = self::lookup($tool);

        return $roster->verdictFor($callingAgentName);
    }

    /**
     * The coord roster SEAT name the calling seat's own YAML says it is — `identity.coord_seat`,
     * else its agent name (card#10869). Read from the same lookup as the id, so the two cannot
     * answer about different configs. `board_take_card` uses it to tell a legacy
     * `owner:<project>/<seat>` tag naming ANOTHER seat from one that may be this seat's own.
     *
     * @throws ToolRefusalException
     * @throws \LogicException if no front door established a seat for this process
     */
    public static function seatNameForCallingSeat(string $tool): string
    {
        return self::lookup($tool)[2];
    }

    /**
     * The ONE read every answer shares, so they cannot drift on what counts as a fault: the
     * sealed seat's agent name, its roster id (null when the roster gives it none), its seat, and
     * the roster read that answered; or a named refusal for every state in which the id would not
     * identify the caller.
     *
     * @return array{0: string, 1: ?int, 2: string, 3: AgentKanbanUsers}
     *
     * @throws ToolRefusalException
     * @throws \LogicException if no front door established a seat for this process
     */
    private static function lookup(string $tool): array
    {
        $callingAgentName = CallingSeat::name();

        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (ConfigException $e) {
            Log::warning('board tools: could not read the agent configuration to resolve the calling seat\'s own kanban user', [
                'agent' => $callingAgentName, 'tool' => $tool, 'error' => $e->getMessage(),
            ]);

            throw new ToolRefusalException("{$tool}: the bridge could not read its own agent configuration, so it cannot establish WHICH kanban user you are — and this door writes only the calling seat's own id, never one from your arguments. NOTHING WAS WRITTEN. This is an INSTALL fault, not something your arguments can fix; report it to your operator.", installFault: true, reason: 'install_fault.agent_config_unreadable');
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

        if ($mine === null) {
            Log::warning('board tools: the agent this call authenticated as is no longer configured', [
                'agent' => $callingAgentName, 'tool' => $tool, 'reason' => self::NOT_IN_ROSTER,
            ]);

            throw new ToolRefusalException("{$tool}: this bridge has no configuration for agent `{$callingAgentName}`, so it cannot establish which kanban user you are — the agent configuration this call authenticated against no longer carries you (a YAML removed or renamed under the running bridge). NOTHING WAS WRITTEN. This is an INSTALL fault; report it to your operator.", installFault: true, reason: 'install_fault.not_in_roster');
        }

        $seatName = $mine->identity->seatName($callingAgentName);
        $roster = AgentKanbanUsers::of($configs);
        if (! $roster->readable()) {
            self::refuseUnreadable($tool, $callingAgentName, $roster);
        }

        $kanbanUserId = $roster->verdictFor($callingAgentName)->userId;
        if ($kanbanUserId === null) {
            return [$callingAgentName, null, $seatName, $roster];
        }

        // ONE seat, but more than one agent here that can take cards as it — a copied
        // `identity.coord_seat`, typically. The id then names the seat and not WHICH agent holds
        // the card: the same unanswerable claim as one id on two seats, refused the same way.
        $takers = [];
        foreach ($configs as $config) {
            if ($config->boardTools?->enabled === true && $config->identity->seatName($config->agentName) === $seatName) {
                $takers[] = $config->agentName;
            }
        }
        if (count($takers) > 1) {
            sort($takers);
            Log::warning('board tools: more than one board-tools agent serves the calling seat, so its kanban user id does not identify the caller', [
                'agent' => $callingAgentName, 'tool' => $tool, 'seat' => $seatName, 'agents' => $takers, 'reason' => self::SHARED_KANBAN_USER_ID,
            ]);

            throw new ToolRefusalException("{$tool}: this bridge has more than one agent with board tools serving coord roster seat '{$seatName}' (".implode(', ', $takers)."), so the seat's kanban user {$kanbanUserId} does not say WHICH of them holds a card — a claim recorded under it would tell the others they hold work they never took. NOTHING WAS WRITTEN. This is an INSTALL fault: keep board_tools on one agent per seat (look for a copied identity.coord_seat), and report it to your operator.", installFault: true, reason: 'install_fault.shared_kanban_user');
        }

        $sharing = $roster->otherSeatsWithId($kanbanUserId, $seatName);
        if ($sharing !== []) {
            Log::warning('board tools: the coord roster gives the calling seat\'s kanban user id to another seat too, so it does not identify the caller', [
                'agent' => $callingAgentName, 'tool' => $tool, 'seat' => $seatName, 'kanban_user_id' => $kanbanUserId,
                'seats' => $sharing, 'reason' => self::SHARED_KANBAN_USER_ID,
            ]);

            throw new ToolRefusalException("{$tool}: the coord roster at {$roster->file->shownPath()} gives kanban user {$kanbanUserId} to seat '{$seatName}' (yours) AND to ".(count($sharing) === 1 ? 'seat ' : 'seats ').implode(', ', array_map(static fn (string $s): string => "'{$s}'", $sharing)).', which this install also serves, so that id does not say WHICH seat you are — and a card recorded under it would tell every other seat that somebody holds the work without saying who, which is the one question this door exists to answer. NOTHING WAS WRITTEN. This is an INSTALL fault: give each seat its own kanban user in the roster, and report it to your operator.', installFault: true, reason: 'install_fault.shared_kanban_user');
        }

        return [$callingAgentName, $kanbanUserId, $seatName, $roster];
    }

    /**
     * The roster could not be asked at all — a refusal per fault, each with its own reason, and
     * the message names the setting or the path and which case it is.
     *
     * @throws ToolRefusalException always
     */
    private static function refuseUnreadable(string $tool, string $callingAgentName, AgentKanbanUsers $roster): never
    {
        $file = $roster->file;
        Log::warning('board tools: the coord roster could not be read, so the calling seat\'s kanban user id is unknown', [
            'agent' => $callingAgentName, 'tool' => $tool, 'fault' => $file->fault ?? 'no_kanban_host', 'roster' => $file->shownPath(),
        ]);

        $message = "{$tool}: the bridge reads each seat's kanban user id from the coord roster, and ".$roster->faultClause()
            .' — so it cannot establish WHICH kanban user you are. NOTHING WAS WRITTEN, and nothing else is consulted in its place: this door writes only your own id from that one source, never one from your arguments. This is an INSTALL fault, not something your arguments can fix; report it to your operator (`php artisan bridge:check` names it too).';

        throw match ($file->fault) {
            CoordConfigFile::UNSET => new ToolRefusalException($message, installFault: true, reason: 'install_fault.coord_config_unset'),
            CoordConfigFile::NOT_ABSOLUTE => new ToolRefusalException($message, installFault: true, reason: 'install_fault.coord_config_not_absolute'),
            CoordConfigFile::ABSENT, CoordConfigFile::UNREADABLE => new ToolRefusalException($message, installFault: true, reason: 'install_fault.coord_config_unreadable'),
            CoordConfigFile::NOT_A_FILE => new ToolRefusalException($message, installFault: true, reason: 'install_fault.coord_config_not_a_file'),
            CoordConfigFile::MALFORMED => new ToolRefusalException($message, installFault: true, reason: 'install_fault.coord_config_malformed'),
            default => new ToolRefusalException($message, installFault: true, reason: 'install_fault.no_kanban_user'),
        };
    }
}
