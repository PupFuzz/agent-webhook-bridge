<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentKanbanUsers;
use App\Bridge\Support\CoordConfigFile;
use App\Bridge\Support\Finding;
use App\Bridge\Support\KanbanInstanceKey;
use App\Bridge\Support\ProcessIdentity;
use App\Bridge\Support\RosterKanbanUser;

/**
 * The coord roster as the bridge's RUNTIME source of each agent's kanban user id (card#11172 /
 * DL-450): can it be read at `BRIDGE_COORD_CONFIG_PATH`, does every board-tools agent's seat
 * resolve to an id on this kanban host, and what should the operator do with a retired
 * `identity.kanban_user_id` still in a YAML.
 *
 * ⭐ IT READS WHAT THE RUNTIME READS, THROUGH THE SAME PRIMITIVES. {@see CoordConfigFile::configured}
 * — the setting alone, never the ambient `$COORD_CONFIG` the writeback compares fall back to — and
 * {@see AgentKanbanUsers}, the one resolution the take, the correction and event attribution all
 * use. A leg that found the roster another way would pass an install whose receiver reads nothing.
 *
 * THE SETTING IS REQUIRED ("default on, require setup") wherever a kanban id is read at runtime —
 * an agent subscribed to kanban events, or with board tools; an install with neither is not asked
 * for it. Unset or relative FAILS with the `.env` line to add; a file that is absent, not a
 * regular file (a symlink, a directory, a FIFO, past the read bound) or not a JSON object FAILS,
 * because every reader gets the same answer; so does a kanban API base naming no host, which
 * leaves the ids unkeyable.
 * ⚠ Only a PERMISSION refusal (or a path this process could not resolve) is UNVALIDATED, never a
 * pass and never a fail: readability is relative to the OS user, and the receiver reads the file
 * as its PHP-FPM pool user, which a CLI run is not. For the same reason a READABLE roster's first
 * line names the OS user that read it and says what it did not measure — the pool user — and how
 * to measure that (run this command as the pool user). What even that does not reproduce — an
 * `open_basedir` in the pool's php.ini, a service-unit sandbox — is named in
 * `docs/config-schema.md`, not re-measured here.
 *
 * PER AGENT. The seat is `identity.coord_seat`, else the agent name; the host is
 * {@see KanbanInstanceKey} of the kanban API base; the lookup is {@see RosterKanbanUser}.
 *  - A board-tools agent FAILS when every take of it refuses: its seat absent or with no id, its
 *    id the roster gives another seat this install serves, or another board-tools agent here on
 *    the same seat — each naming the take's refusal code.
 *  - Any other agent: an id is reported; a seat present with no id WARNS (its events are not
 *    attributed to it and its own writes are not suppressed as its echoes); a declared
 *    `coord_seat` the roster lacks WARNS; an agent subscribed to kanban events that is no seat at
 *    all WARNS, naming the three ways to give it a kanban user. Silent only for a non-seat that
 *    subscribes to nothing kanban.
 *  - `identity.peer_kanban_user_id` — the attribution-only id of an agent that is NOT a seat of
 *    this roster (a cross-install peer) — FAILS where it could carry a seat's id: on an agent
 *    whose name or coord_seat IS a seat, beside any `coord_seat` at all, or when it equals ANY
 *    roster seat's id on this host ({@see RosterKanbanUser::seatIds}). Otherwise it is reported
 *    in an `ok` line. The runtime uses it only in that last case ({@see AgentKanbanUsers::peerOf}).
 *
 * THE MIGRATION, for an agent whose YAML still carries `identity.kanban_user_id`, which nothing
 * reads any more: equal to the id the bridge now uses (the roster's, or a non-seat's peer id)
 * WARNS "remove it"; different FAILS, naming the id the bridge acts as; no id at all FAILS — that
 * agent lost its kanban user on upgrade — and says where to put it: the roster for a seat, the
 * peer field for an agent that is no seat. Absent says nothing.
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
        if (array_filter($ctx->configs, self::needsKanbanUserIds(...)) === []) {
            yield Silence::because('no agent subscribes to kanban events or has board_tools enabled, so nothing on this install reads a kanban user id from the coord roster');

            return;
        }

        $file = CoordConfigFile::configured();
        if ($file->fault === CoordConfigFile::UNSET || $file->fault === CoordConfigFile::NOT_ABSOLUTE) {
            yield Finding::fail('agent roster: '.$file->faultClause().' — the bridge reads every agent\'s kanban user id from the coord roster at runtime (DL-450), so until it names the file board_take_card refuses every call and a kanban delivery that needs attribution answers 5xx. Add '.CoordConfigFile::SETTING.'=<absolute path to coordination.config.json> to this install\'s .env (then php artisan config:cache, if this install caches its config).');

            return;
        }
        if (in_array($file->fault, [CoordConfigFile::MALFORMED, CoordConfigFile::NOT_A_FILE, CoordConfigFile::ABSENT], true)) {
            // The same answer for every reader, the receiver's pool user included — a measured
            // fault, not a uid-relative one.
            yield Finding::fail('agent roster: '.$file->faultClause().' — every reader of it, the receiver included, gets the same answer, so board_take_card refuses every call and a kanban delivery that needs attribution answers 5xx until it is fixed.');

            return;
        }
        if ($file->fault === CoordConfigFile::UNREADABLE) {
            yield Finding::unvalidated('agent roster: CANNOT VERIFY the kanban user ids the bridge reads at runtime — '.$file->faultClause().'. This run\'s OS user is '.$this->whoAmI().'; the receiver reads the file as its PHP-FPM pool user, which may read it fine — or may not, in which case board_take_card refuses every call and a kanban delivery that needs attribution answers 5xx. Run this command as the pool user (sudo -u <pool user> php artisan bridge:check) to measure it there.');

            return;
        }

        yield Finding::ok("agent roster: the coord roster at {$file->shownPath()} is readable by this run's OS user ".$this->whoAmI().'. ⚠ The receiver reads it as its PHP-FPM pool user, which this run does not measure: run sudo -u <pool user> php artisan bridge:check to measure that user (a pool user that cannot read it refuses every take as install_fault.coord_config_unreadable and answers 5xx to kanban deliveries).');

        $users = AgentKanbanUsers::of($ctx->configs, $file);
        if (! $users->readable()) {
            yield Finding::fail('agent roster: '.$users->faultClause().' — so no agent has a kanban user id: board_take_card refuses every call and a kanban delivery that needs attribution answers 5xx. Set BRIDGE_KANBAN_API_BASE_URL.');

            return;
        }

        $takersBySeat = [];
        foreach ($ctx->configs as $agent) {
            if ($agent->boardTools?->enabled === true) {
                $takersBySeat[(string) $users->seatOf($agent->agentName)][] = $agent->agentName;
            }
        }

        yield from $this->sharedSeats($ctx->configs, $users, $takersBySeat);

        foreach ($ctx->configs as $agent) {
            yield from $this->agent($agent, $users, $takersBySeat);
        }
    }

    /**
     * The agents a kanban user id is READ for at runtime: one subscribed to a kanban-shaped
     * provider (attribution and self echo-suppression run on every such event — the same
     * non-github split `AgentRegistry::actorFromEvent` draws) or with board tools enabled (the
     * take and the correction). An install with neither never reads the roster, so this leg asks
     * it for nothing.
     */
    private static function needsKanbanUserIds(AgentConfig $agent): bool
    {
        return $agent->boardTools?->enabled === true || self::subscribesToKanban($agent);
    }

    /**
     * @param  array<string, list<string>>  $takersBySeat  seat → the board-tools agents on it
     * @return iterable<Finding>
     */
    private function agent(AgentConfig $agent, AgentKanbanUsers $users, array $takersBySeat): iterable
    {
        $name = $agent->agentName;
        $seat = (string) $users->seatOf($name);
        $host = $users->host;
        $verdict = $users->verdictFor($name);
        $takes = $agent->boardTools?->enabled === true;
        $isSeat = $verdict->why !== RosterKanbanUser::ABSENT;
        $peer = $agent->identity->peerKanbanUserId;

        if ($peer !== null && $agent->identity->coordSeat !== null) {
            yield Finding::fail("agent {$name}: declares both identity.coord_seat and identity.peer_kanban_user_id — a coord_seat is a claim that the agent IS a seat of this roster, whose id is the roster's alone, and the peer field is only for an agent that is NOT (DL-450). Keep one: coord_seat for a seat of this roster, peer_kanban_user_id for a peer whose seat another roster owns. The peer id is not used meanwhile.");
        } elseif ($peer !== null && ! $isSeat && ($sameId = $users->rosterSeatsWithId($peer)) !== []) {
            yield Finding::fail("agent {$name}: identity.peer_kanban_user_id {$peer} is the kanban user the coord roster gives seat ".implode(', ', array_map(static fn (string $s): string => "'{$s}'", $sameId))." on '{$host}' — carried under another agent's name it would collide with that seat and take its attribution away (DL-450). If this agent IS that seat, set identity.coord_seat to it and remove the peer field; otherwise correct the id. The peer id is not used meanwhile.");
        }

        if ($peer !== null && $isSeat) {
            yield Finding::fail("agent {$name}: declares identity.peer_kanban_user_id {$peer}, but it IS coord roster seat '{$seat}' — a seat's kanban user id is the roster's alone, so this would be a second copy of it (DL-450). Remove identity.peer_kanban_user_id from {$name}.yml; the field is only for an agent that is no seat of this roster.");
        }

        if ($verdict->userId !== null) {
            $otherSeats = $takes ? $users->otherSeatsWithId($verdict->userId, $seat) : [];
            if ($otherSeats !== []) {
                yield Finding::fail("agent {$name}: has board_tools enabled, and the coord roster gives its seat '{$seat}''s kanban user {$verdict->userId} to ".(count($otherSeats) === 1 ? 'seat ' : 'seats ').implode(', ', array_map(static fn (string $s): string => "'{$s}'", $otherSeats)).' as well, which this install also serves — so the id does not say which seat holds a card, and board_take_card refuses every call from it (install_fault.shared_kanban_user). Give each seat its own kanban user in the roster.');
            } elseif ($takes && count($takersBySeat[$seat] ?? []) > 1) {
                yield Finding::fail("agent {$name}: has board_tools enabled, and so do ".implode(', ', array_values(array_diff($takersBySeat[$seat], [$name]))).", on the same coord roster seat '{$seat}' — so the seat's kanban user {$verdict->userId} does not say which agent holds a card, and board_take_card refuses every call from each of them (install_fault.shared_kanban_user). Keep board_tools on one agent per seat (look for a copied identity.coord_seat).");
            } else {
                yield Finding::ok("agent {$name}: kanban user {$verdict->userId} (seat '{$seat}' on '{$host}' in the coord roster)");
            }
        } elseif ($takes) {
            yield Finding::fail("agent {$name}: has board_tools enabled, but ".(! $isSeat
                ? "the coord roster has no seat named '{$seat}' (its identity.coord_seat, else its agent name) — so board_take_card refuses every call from it (install_fault.roster_seat_absent)".($peer !== null ? '; identity.peer_kanban_user_id is attribution only and never take authority' : '').'. Set identity.coord_seat to this agent\'s roster seat, or add the seat to the roster.'
                : "its coord roster seat '{$seat}' carries no kanban user id for this kanban instance ('{$host}') — ".self::missingClause($verdict, $host).' So board_take_card refuses every call from it (install_fault.no_kanban_user).'.self::whoWritesIt()));
        } elseif (! $isSeat) {
            // The coord_seat-absent WARN first and whatever the peer field says: a mistyped
            // coord_seat must not be hidden by a peer id beside it.
            if ($agent->identity->coordSeat !== null) {
                yield Finding::warn("agent {$name}: declares identity.coord_seat '{$seat}', but the coord roster has no seat named '{$seat}' — so it has no kanban user: kanban events are not attributed to it, and its own kanban writes are not suppressed as its echoes. Correct identity.coord_seat, or add the seat to the roster.");
            } elseif (($usable = $users->peerOf($name)) !== null) {
                yield Finding::ok("agent {$name}: attribution-only kanban user {$usable} (identity.peer_kanban_user_id — no seat of this coord roster, and no seat's id in it; never take, start or correction authority)");
            } elseif ($peer === null && self::subscribesToKanban($agent)) {
                yield Finding::warn("agent {$name}: subscribes to kanban events but is no seat of the coord roster (no seat named '{$name}', and no identity.coord_seat) — so it has no kanban user: kanban events from its own account are not attributed to it or suppressed as its echoes. If it is a seat, set identity.coord_seat to the seat (or add the seat to the roster); if its seat belongs to ANOTHER roster, set identity.peer_kanban_user_id to its kanban user id.");
            }
        } else {
            yield Finding::warn("agent {$name}: its coord roster seat '{$seat}' carries no kanban user id for this kanban instance ('{$host}') — ".self::missingClause($verdict, $host).' Until it does, kanban events are not attributed to this agent and its own kanban writes are not suppressed as its echoes.'.self::whoWritesIt());
        }

        yield from $this->migration($agent, $users, $verdict, $seat, $host);
    }

    /**
     * The retired `identity.kanban_user_id`, where a YAML still carries it.
     *
     * @return iterable<Finding>
     */
    private function migration(AgentConfig $agent, AgentKanbanUsers $users, RosterKanbanUser $verdict, string $seat, string $host): iterable
    {
        $retired = $agent->identity->retiredKanbanUserId;
        if ($retired === null) {
            return;
        }
        $name = $agent->agentName;
        $peer = $users->peerOf($name);
        $used = $verdict->userId ?? $peer;
        $where = $verdict->userId !== null ? "seat '{$seat}'s id in the coord roster" : 'its identity.peer_kanban_user_id';

        if ($used === $retired) {
            yield Finding::warn("agent {$name}: identity.kanban_user_id {$retired} is no longer read — the bridge reads {$where}, which is the same {$retired} (DL-450). Nothing changes when you remove it from {$name}.yml, so remove it.");
        } elseif ($used !== null) {
            yield Finding::fail("agent {$name}: identity.kanban_user_id is {$retired}, but the bridge reads {$where} (DL-450), which is {$used} on '{$host}' — so the bridge acts as kanban user {$used}. Correct whichever is wrong THERE, then remove the key from {$name}.yml.");
        } elseif ($verdict->why === RosterKanbanUser::ABSENT && $agent->identity->coordSeat === null) {
            yield Finding::fail("agent {$name}: identity.kanban_user_id {$retired} is no longer read (DL-450), and this agent is no seat of the coord roster (no seat named '{$name}') — so it has NO kanban user now: kanban events from {$retired} are not attributed to it or suppressed as its echoes".($agent->boardTools?->enabled === true ? ', and board_take_card refuses every call from it' : '').". If it is a peer whose seat ANOTHER roster owns, move the id to identity.peer_kanban_user_id: {$retired}; if it IS a seat of this roster, set identity.coord_seat to that seat and give the seat its id in the roster. Then remove identity.kanban_user_id from {$name}.yml.");
        } else {
            yield Finding::fail("agent {$name}: identity.kanban_user_id {$retired} is no longer read (DL-450), and the coord roster gives seat '{$seat}' no kanban user id on '{$host}' — so this agent has NO kanban user now: kanban events are not attributed to it, its own kanban writes are not suppressed as its echoes".($agent->boardTools?->enabled === true ? ', and board_take_card refuses every call from it' : '').". If {$retired} is that seat's kanban user, write it into the roster (\"{$host}\": {$retired} in seat '{$seat}''s kanban_user_id; if this agent is not its own seat, set identity.coord_seat to the seat it is), then remove the key from {$name}.yml.");
        }
    }

    /**
     * One seat has one kanban user, so two agents resolving to a seat with an id are two agents on
     * one kanban user: `AgentRegistry` attributes that user's events to NEITHER by name (its raw-id
     * self echo-suppression still holds for both) — a WARN, named here so the collision line is not
     * the first sign of it. Where two of them have board tools the takes refuse, which the
     * per-agent line FAILS on; this line is then not repeated.
     *
     * @param  list<AgentConfig>  $configs
     * @param  array<string, list<string>>  $takersBySeat
     * @return iterable<Finding>
     */
    private function sharedSeats(array $configs, AgentKanbanUsers $users, array $takersBySeat): iterable
    {
        $bySeat = [];
        foreach ($configs as $agent) {
            if ($users->verdictFor($agent->agentName)->userId !== null) {
                $bySeat[(string) $users->seatOf($agent->agentName)][] = $agent->agentName;
            }
        }
        ksort($bySeat);
        foreach ($bySeat as $seat => $agents) {
            if (count($agents) > 1 && count($takersBySeat[$seat] ?? []) < 2) {
                sort($agents);
                $id = $users->verdictFor($agents[0])->userId;
                yield Finding::warn('agent roster: agents '.implode(', ', $agents)." all resolve to coord roster seat '{$seat}' (kanban user {$id}), so kanban events from that user are attributed to none of them by name — a name-based treat_as_echo or treat_as_signal naming any of them does not match those events. A seat has ONE kanban user: keep one bridge agent per seat (remove identity.coord_seat '{$seat}' from the others, or give each its own seat).");
            }
        }
    }

    private static function subscribesToKanban(AgentConfig $agent): bool
    {
        foreach ($agent->subscriptions as $subscription) {
            if ($subscription->provider !== 'github') {
                return true;
            }
        }

        return false;
    }

    /**
     * The OS user this run reads files as. By uid, not name: the uid is what the golden harness
     * normalizes, and `id <pool user>` maps it for an operator comparing the two.
     */
    private function whoAmI(): string
    {
        $uid = app(ProcessIdentity::class)->euid();

        return $uid === null ? 'an OS user this run could not identify (no posix extension)' : "(uid {$uid})";
    }

    private static function missingClause(RosterKanbanUser $roster, string $host): string
    {
        return match ($roster->why) {
            RosterKanbanUser::NO_FIELD => "the entry has no `kanban_user_id` object (roster verdict `nofield`); it is written as \"kanban_user_id\": {\"{$host}\": <kanban user id>}.",
            RosterKanbanUser::NO_HOST => "its `kanban_user_id` has no entry for '{$host}' (roster verdict `nohost`); add \"{$host}\": <kanban user id>.",
            default => "its `kanban_user_id[\"{$host}\"]` is ".json_encode($roster->found).', not a positive integer kanban user id (roster verdict `bad`); nothing is coerced.',
        };
    }

    private static function whoWritesIt(): string
    {
        return ' The id is never guessed or defaulted. The coord framework\'s install/upgrade writes it for pm and solo seats only, once the seat has its own kanban account (kanban card#10867); any other seat\'s roster id is set by hand.';
    }
}
