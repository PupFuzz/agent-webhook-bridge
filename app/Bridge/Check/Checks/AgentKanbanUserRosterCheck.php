<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\CoordConfigPath;
use App\Bridge\Support\Finding;
use App\Bridge\Support\KanbanInstanceKey;
use App\Bridge\Support\RosterKanbanUser;
use App\Bridge\Writeback\CoordConfigTerminals;

/**
 * Hold each agent's `identity.kanban_user_id` against the coord roster (card#10869).
 *
 * ⭐ THE ROSTER IS THE STORE, THE YAML KEY IS ITS CACHE. The operator ruled the roster in
 * `coordination.config.json` the SINGLE store of each agent's kanban user id (card#10868 Q1).
 * The bridge cannot read that file where the id is used — every consumer of it (event
 * attribution, self echo-suppression, `board_take_card`, `board_correct_card`) runs under
 * PHP-FPM, whose environment has no `$COORD_CONFIG` and is not the operator's
 * ({@see CoordConfigPath}) — so the YAML key stays as the runtime copy and THIS is the drift
 * check the ruling requires of a copy: a YAML id that disagrees with the roster FAILS.
 *
 * ⚠ A MISSING ROSTER ID WARNS, IT DOES NOT FAIL — and that is a release decision, not the end
 * state. No roster carries `kanban_user_id` yet (kanban card#10867 must first give agents their
 * own accounts; the framework's install/upgrade then writes the field), so a fail here would
 * redden every install with a seat. It flips to fail once the ids exist; `docs/config-schema.md`
 * § identity carries that reopen condition.
 *
 * THE POPULATION is every agent whose kanban id is, or must be, a seat's: one that declares
 * `identity.kanban_user_id`, or has `board_tools` enabled (it can take cards, so it needs a seat's
 * id). `identity.coord_seat` alone does not admit an agent — it only says WHICH seat a member is —
 * so a second bridge identity for a seat (a `kanban-solo.yml` beside the seat's own) stays out
 * of the comparison until it carries an id or board tools. One seat has one kanban user, so two
 * members resolving to one seat is a fault: after the roster carries the id, the one without it
 * FAILS as drift, and two carrying it are the kanban-axis collision.
 *
 * The seat is `identity.coord_seat`, else the agent name; the board instance is the host of
 * `bridge.providers.kanban.api_base_url` ({@see KanbanInstanceKey}); the roster read is
 * {@see RosterKanbanUser} — both ports of the toolkit's reader, held to
 * `docs/kb-owner-parity-corpus.json`.
 *
 * It runs in the roster slot, after the per-agent loop, because it compares agents with each
 * other (two agents resolving to one seat) as well as with the roster.
 */
final class AgentKanbanUserRosterCheck implements Check
{
    public function id(): string
    {
        return 'agent.kanban_user_roster';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $population = array_values(array_filter($ctx->configs, self::inPopulation(...)));
        if ($population === []) {
            yield Silence::because('no agent declares identity.kanban_user_id and none has board_tools enabled — no agent carries or needs a seat\'s kanban user id');

            return;
        }

        $path = CoordConfigPath::resolve();
        $takers = array_values(array_filter($population, static fn (AgentConfig $a): bool => $a->boardTools?->enabled === true));
        if ($path === null && $takers === []) {
            // Nothing names a coordination project and no agent can take a card: this install
            // has no roster to hold its ids against, and none of its ids is a card owner. An
            // install that DOES run seats names the config (or runs board tools) and reaches
            // the CANNOT-VERIFY line below instead.
            yield Silence::because('no coordination config is named (bridge.writeback.coord_config_path / $COORD_CONFIG) and no agent has board_tools enabled, so there is no roster to hold identity.kanban_user_id against and no agent that writes a card owner');

            return;
        }
        $config = CoordConfigTerminals::load($path);
        if ($config === null) {
            yield Finding::unvalidated('agent roster: CANNOT VERIFY any agent\'s identity.kanban_user_id against the coord roster — '
                .CoordConfigPath::unreadableClause($path).'. The roster is the single store of each seat\'s kanban user id and the YAML key is only its runtime copy, so until this is readable the copy is unchecked. Point bridge.writeback.coord_config_path (or $COORD_CONFIG) at coordination.config.json.');

            return;
        }

        $host = KanbanInstanceKey::of((string) config('bridge.providers.kanban.api_base_url'));
        if ($host === '') {
            yield Finding::unvalidated('agent roster: CANNOT VERIFY any agent\'s identity.kanban_user_id against the coord roster — bridge.providers.kanban.api_base_url names no host, and the roster keys each seat\'s id by the kanban host it is valid on.');

            return;
        }

        yield from $this->sharedSeats($population);

        $notSeats = [];
        foreach ($population as $agent) {
            $seat = $agent->identity->seatName($agent->agentName);
            $roster = RosterKanbanUser::lookUp($config, $seat, $host);
            $mine = $agent->identity->kanbanUserId;
            $name = $agent->agentName;

            if ($roster->why === RosterKanbanUser::ABSENT) {
                if ($agent->identity->coordSeat !== null || $agent->boardTools?->enabled === true) {
                    $why = $agent->identity->coordSeat !== null
                        ? "declares identity.coord_seat '{$seat}'"
                        : "has board_tools enabled, so it can take cards as seat '{$seat}'";
                    yield Finding::warn("agent {$name}: {$why}, but the coord roster has no seat named '{$seat}' — so its kanban user id cannot be held against the roster, which is the single store of it. Set identity.coord_seat to this agent's roster seat name, or add the seat to the roster.");
                } else {
                    $notSeats[] = $name;
                }

                continue;
            }

            if ($roster->userId === null) {
                yield Finding::warn("agent {$name}: its coord roster seat '{$seat}' carries no kanban user id for this kanban instance ('{$host}') — ".self::missingClause($roster, $seat, $host)
                    .($mine === null
                        ? ' Until it does, board_take_card refuses every call from this agent (it has no id to record).'
                        : " This agent's identity.kanban_user_id ({$mine}) is therefore UNVERIFIED against the roster: board_take_card still writes it as this seat's owner id (operator ruling C, card#10869), and nothing confirms it is this seat's user. This warn becomes a fail once the roster carries the ids.")
                    .' The id is never guessed or defaulted; the framework\'s install/upgrade writes it once the seat has its own kanban account (kanban card#10867).');

                continue;
            }

            if ($mine === null) {
                yield Finding::fail("agent {$name}: the coord roster says seat '{$seat}' is kanban user {$roster->userId} on '{$host}', but this agent's identity.kanban_user_id is not set — the bridge's copy of the roster is empty, so board_take_card refuses every call and kanban events from that user are not attributed to this agent. Set identity.kanban_user_id: {$roster->userId}.");

                continue;
            }

            if ($mine !== $roster->userId) {
                yield Finding::fail("agent {$name}: identity.kanban_user_id is {$mine}, but the coord roster says seat '{$seat}' is kanban user {$roster->userId} on '{$host}'. The roster is the single store; this YAML key is only the bridge's copy of it, and a copy that disagrees writes the wrong owner on every take. Set identity.kanban_user_id: {$roster->userId}.");

                continue;
            }

            yield Finding::ok("agent {$name}: identity.kanban_user_id {$mine} matches the coord roster (seat '{$seat}' on '{$host}')");
        }

        if ($notSeats !== []) {
            sort($notSeats);
            yield Finding::ok('agent roster: '.implode(', ', $notSeats).(count($notSeats) === 1 ? ' carries' : ' carry').' identity.kanban_user_id but serve'.(count($notSeats) === 1 ? 's' : '').' no coord roster seat, so '.(count($notSeats) === 1 ? 'its id is' : 'their ids are').' attribution-only and not held against the roster (set identity.coord_seat on any that IS a seat).');
        }
    }

    private static function inPopulation(AgentConfig $agent): bool
    {
        return $agent->identity->kanbanUserId !== null
            || $agent->boardTools?->enabled === true;
    }

    /**
     * One seat has one kanban user, so at most one population member may resolve to it (class
     * docblock). Each is still compared below; this line names the pairing so a drift fail or an
     * `AgentIdentityCollisionsCheck` line is not the first sign of it.
     *
     * @param  list<AgentConfig>  $population
     * @return iterable<Finding>
     */
    private function sharedSeats(array $population): iterable
    {
        $bySeat = [];
        foreach ($population as $agent) {
            $bySeat[$agent->identity->seatName($agent->agentName)][] = $agent->agentName;
        }
        ksort($bySeat);
        foreach ($bySeat as $seat => $agents) {
            if (count($agents) > 1) {
                sort($agents);
                yield Finding::warn('agent roster: agents '.implode(', ', $agents)." all resolve to coord roster seat '{$seat}' and each carries identity.kanban_user_id or board_tools. A seat has ONE kanban user, so only one bridge agent may: keep identity.kanban_user_id and board_tools on one of them and remove both from the others (identity.coord_seat alone is fine).");
            }
        }
    }

    private static function missingClause(RosterKanbanUser $roster, string $seat, string $host): string
    {
        return match ($roster->why) {
            RosterKanbanUser::NO_FIELD => "the entry has no `kanban_user_id` object (roster verdict `nofield`); it is written as \"kanban_user_id\": {\"{$host}\": <kanban user id>}.",
            RosterKanbanUser::NO_HOST => "its `kanban_user_id` has no entry for '{$host}' (roster verdict `nohost`); add \"{$host}\": <kanban user id>.",
            default => "its `kanban_user_id[\"{$host}\"]` is ".json_encode($roster->found).', not a positive integer kanban user id (roster verdict `bad`); nothing is coerced.',
        };
    }
}
