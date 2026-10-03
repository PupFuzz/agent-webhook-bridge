<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * The `identity` section of a per-agent config — the agent's own IMMUTABLE
 * github ids, and the coord roster seat it serves. The github ids build the
 * AgentRegistry's github axis (recognition keys on numeric ids, DL-002) and
 * auto-seed self echo-suppression (DL-007). githubLogin is a display-only label
 * (GitHub usernames are renameable, so they are never a matching key). Grouped
 * into one DTO (DL-017) so AgentConfig's constructor doesn't carry loose
 * identity args — the same medicine DL-008 applied to the channel tuple and
 * EchoSuppressionConfig to the echo lists.
 *
 * ⛔ THE KANBAN USER ID IS NOT HERE (card#11172 / DL-450). It lives in the coord
 * roster, read by {@see AgentKanbanUsers} for the seat {@see seatName} names.
 * A YAML that still carries `identity.kanban_user_id` is parsed into
 * {@see $retiredKanbanUserId} for ONE reader — `bridge:check`'s roster leg, which
 * tells the operator to remove it — and `RetiredKanbanUserIdReaderTest` holds that
 * set of readers.
 */
final class IdentityConfig
{
    /**
     * @param  ?int  $retiredKanbanUserId  the RETIRED `identity.kanban_user_id`, as written — read by
     *                                     nothing at runtime; `bridge:check` compares it with the
     *                                     roster only to say "remove it" (DL-450)
     * @param  ?string  $coordSeat  `identity.coord_seat` — the coord roster seat (`roster[].name`)
     *                              this agent serves, for an agent whose name is not that seat's
     *                              (card#10869). Null ⇒ the agent name is the seat name. Read
     *                              through {@see seatName} by every kanban-id reader, and by
     *                              `board_take_card`'s legacy-tag holder test.
     */
    public function __construct(
        public readonly ?int $retiredKanbanUserId = null,
        public readonly ?int $githubUserId = null,
        public readonly ?string $githubLogin = null,
        public readonly ?string $coordSeat = null,
    ) {}

    /**
     * @param  array<mixed>  $data  the parsed `identity:` mapping
     */
    public static function fromArray(array $data): self
    {
        return new self(
            retiredKanbanUserId: isset($data['kanban_user_id']) && is_numeric($data['kanban_user_id']) ? (int) $data['kanban_user_id'] : null,
            githubUserId: isset($data['github_user_id']) && is_numeric($data['github_user_id']) ? (int) $data['github_user_id'] : null,
            githubLogin: isset($data['github_login']) && is_scalar($data['github_login']) ? (string) $data['github_login'] : null,
            coordSeat: self::coordSeat($data['coord_seat'] ?? null),
        );
    }

    /**
     * The coord roster seat this agent is — whose `kanban_user_id` is this agent's: the declared
     * `coord_seat`, else the agent name.
     */
    public function seatName(string $agentName): string
    {
        return $this->coordSeat ?? $agentName;
    }

    /**
     * Absent ⇒ null; surrounding whitespace trimmed; a non-string or blank value THROWS, the
     * shape rule every string key naming a seat follows (`idle_nudge.seat_agent` too) — a
     * value that could only ever fail to match is refused where it is written.
     */
    private static function coordSeat(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (! is_string($raw) || trim($raw) === '') {
            throw new ConfigException('identity.coord_seat must be a non-empty coord roster seat name');
        }

        return trim($raw);
    }

    /**
     * The agent's own GITHUB id as a string, for seeding self echo-suppression. Its kanban id is
     * seeded at dispatch instead, from the roster and for kanban events only (DL-450).
     *
     * @return list<string>
     */
    public function selfGithubIds(): array
    {
        return $this->githubUserId !== null ? [(string) $this->githubUserId] : [];
    }
}
