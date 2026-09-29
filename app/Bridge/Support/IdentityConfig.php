<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * The `identity` section of a per-agent config — the agent's own IMMUTABLE
 * upstream ids. These build the AgentRegistry (recognition keys on numeric
 * ids, DL-002) and auto-seed self echo-suppression (DL-007). githubLogin is a
 * display-only label (GitHub usernames are renameable, so they are never a
 * matching key). Grouped into one DTO (DL-017) so AgentConfig's constructor
 * doesn't carry three loose identity args — the same medicine DL-008 applied
 * to the channel tuple and EchoSuppressionConfig to the echo lists.
 */
final class IdentityConfig
{
    /**
     * @param  ?string  $coordSeat  `identity.coord_seat` — the coord roster seat (`roster[].name`)
     *                              this agent serves, for an agent whose name is not that seat's
     *                              (card#10869). Null ⇒ the agent name is the seat name. Read only
     *                              by `bridge:check`'s roster compare ({@see seatName}).
     */
    public function __construct(
        public readonly ?int $kanbanUserId = null,
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
            kanbanUserId: isset($data['kanban_user_id']) && is_numeric($data['kanban_user_id']) ? (int) $data['kanban_user_id'] : null,
            githubUserId: isset($data['github_user_id']) && is_numeric($data['github_user_id']) ? (int) $data['github_user_id'] : null,
            githubLogin: isset($data['github_login']) && is_scalar($data['github_login']) ? (string) $data['github_login'] : null,
            coordSeat: self::coordSeat($data['coord_seat'] ?? null),
        );
    }

    /**
     * The roster seat name this agent's `identity.kanban_user_id` is compared against: the
     * declared `coord_seat`, else the agent name.
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
     * The agent's own ids as strings, for seeding self echo-suppression.
     *
     * @return list<string>
     */
    public function selfIds(): array
    {
        return array_values(array_filter([
            $this->kanbanUserId !== null ? (string) $this->kanbanUserId : null,
            $this->githubUserId !== null ? (string) $this->githubUserId : null,
        ], fn (?string $x): bool => $x !== null));
    }
}
