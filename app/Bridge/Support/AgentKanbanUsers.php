<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\CoordRosterUnreadableException;

/**
 * Each agent's kanban user id, as the COORD ROSTER says it — the bridge's one source of it since
 * card#11172 / DL-450. `identity.kanban_user_id` in an agent's YAML is not read here or anywhere
 * at runtime; `bridge:check` alone reads it, to tell the operator to remove it.
 *
 * An agent's SEAT is `identity.coord_seat`, else its agent name; its id is that seat's
 * `roster[].kanban_user_id[<host>]`, where the host is this install's kanban instance key
 * ({@see KanbanInstanceKey} of `bridge.providers.kanban.api_base_url`) and the lookup is
 * {@see RosterKanbanUser} — both ports of the toolkit's reader, held to published corpora.
 *
 * EVERY RUNTIME READER GOES THROUGH HERE, so they cannot disagree about which file, which host or
 * which seat: `SeatKanbanUser` in the tools layer (the take, the start form, the correction's
 * assignee arm — NAMED, never `{@see}`-linked: pint turns a docblock FQCN into a real `use`, and
 * Support importing Tools would invert the layer) and {@see AgentRegistry}'s kanban axis
 * (attribution and self echo-suppression).
 *
 * ⛔ NOT READABLE IS NOT "NO IDS". When the file has a fault, or the install names no kanban host
 * to key the ids by, {@see ids} THROWS rather than answering an empty map — an empty map would
 * read as "no agent has a kanban user", which attributes an agent's own writes to nobody and
 * lets them wake it. A caller that can name the fault instead (a tool refusal, a check finding)
 * asks {@see readable} first.
 */
final class AgentKanbanUsers
{
    /**
     * @param  array<string, RosterKanbanUser>  $verdicts  agent name → the roster's answer (readable only)
     * @param  array<string, string>  $seats  agent name → its seat
     * @param  array<string, int>  $peers  agent name → its `identity.peer_kanban_user_id`
     */
    private function __construct(
        public readonly CoordConfigFile $file,
        public readonly string $host,
        private readonly array $verdicts,
        private readonly array $seats,
        private readonly array $peers = [],
    ) {}

    /**
     * @param  list<AgentConfig>  $configs
     */
    public static function of(array $configs, ?CoordConfigFile $file = null): self
    {
        $file ??= CoordConfigFile::configured();
        $host = KanbanInstanceKey::of((string) config('bridge.providers.kanban.api_base_url'));

        $verdicts = [];
        $seats = [];
        $peers = [];
        foreach ($configs as $config) {
            $seat = $config->identity->seatName($config->agentName);
            $seats[$config->agentName] = $seat;
            if ($config->identity->peerKanbanUserId !== null) {
                $peers[$config->agentName] = $config->identity->peerKanbanUserId;
            }
            if ($file->readable() && $host !== '') {
                $verdicts[$config->agentName] = RosterKanbanUser::lookUp($file->config(), $seat, $host);
            }
        }

        return new self($file, $host, $verdicts, $seats, $peers);
    }

    /** Whether the roster could be asked at all: the file read, and a host to key ids by. */
    public function readable(): bool
    {
        return $this->file->readable() && $this->host !== '';
    }

    /** Why it could not be asked — the file's own clause, or the missing host. */
    public function faultClause(): string
    {
        return $this->file->readable()
            ? 'bridge.providers.kanban.api_base_url (BRIDGE_KANBAN_API_BASE_URL) names no kanban host, and the coord roster keys each seat\'s id by the host it is valid on'
            : $this->file->faultClause();
    }

    /**
     * The roster's answer for one agent, which must be one of the configs this was built from.
     *
     * @throws \LogicException when the roster could not be asked ({@see readable})
     */
    public function verdictFor(string $agentName): RosterKanbanUser
    {
        if (! $this->readable()) {
            throw new \LogicException('AgentKanbanUsers::verdictFor() asked of a roster that could not be read');
        }

        return $this->verdicts[$agentName] ?? throw new \LogicException("AgentKanbanUsers: no agent named '{$agentName}' was given");
    }

    public function seatOf(string $agentName): ?string
    {
        return $this->seats[$agentName] ?? null;
    }

    /**
     * The OTHER seats — among the seats this install's agents serve — that the roster gives
     * $userId on this host. Sorted; empty when, here, the id is $seat's alone.
     *
     * ⚠ INSTALL-LOCAL, the bound `board_take_card`'s shared-id refusal has always had: a seat the
     * roster names that no agent of THIS bridge serves is not compared, so two installs whose
     * seats the roster gives one id do not see each other here. Widening it to the whole roster
     * would newly refuse takes on installs that run in that state today.
     *
     * @return list<string>
     *
     * @throws \LogicException when the roster could not be asked ({@see readable})
     */
    public function otherSeatsWithId(int $userId, string $seat): array
    {
        if (! $this->readable()) {
            throw new \LogicException('AgentKanbanUsers::otherSeatsWithId() asked of a roster that could not be read');
        }

        $others = [];
        foreach ($this->verdicts as $agent => $verdict) {
            $other = $this->seats[$agent];
            if ($other !== $seat && $verdict->userId === $userId && ! in_array($other, $others, true)) {
                $others[] = $other;
            }
        }
        sort($others);

        return $others;
    }

    /**
     * Every agent's kanban user id FOR ATTRIBUTION AND ECHO/SIGNAL MATCHING: agent name → id. A
     * roster seat's id is the roster's; an agent that is NO seat of this roster contributes its
     * `identity.peer_kanban_user_id`, if it declares one — never a seat, whose id is the roster's
     * alone. An agent with neither is not in the map: it has no kanban user. ⛔ Take, start and
     * correction authority never read this map; they read {@see verdictFor}, the roster alone.
     *
     * @return array<string, int>
     *
     * @throws CoordRosterUnreadableException when the roster could not be asked
     */
    public function ids(): array
    {
        if (! $this->readable()) {
            throw new CoordRosterUnreadableException('agent registry: cannot attribute kanban events to agents, because their kanban user ids live in the coord roster and '.$this->faultClause().' — the delivery is refused so kanban redelivers it once the install is fixed (DL-450). Run php artisan bridge:check.');
        }

        $ids = [];
        foreach ($this->verdicts as $agent => $verdict) {
            if ($verdict->userId !== null) {
                $ids[$agent] = $verdict->userId;
            } elseif ($verdict->why === RosterKanbanUser::ABSENT && isset($this->peers[$agent])) {
                $ids[$agent] = $this->peers[$agent];
            }
        }

        return $ids;
    }
}
