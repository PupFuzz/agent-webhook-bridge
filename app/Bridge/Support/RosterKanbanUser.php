<?php

namespace App\Bridge\Support;

/**
 * What a coord roster says ONE seat's kanban user is on ONE board instance — the store the
 * operator ruled single (card#10868 Q1): `coordination.config.json` `roster[].kanban_user_id`,
 * an object keyed by board instance ({@see KanbanInstanceKey}) whose value is that seat's kanban
 * user id there.
 *
 * A PORT OF THE TOOLKIT'S READER, not a second rule: `KB_JQ_ROSTER`'s `roster_seats` and
 * `seat_uid_verdict` in `bin/_kb-board-lib.sh` (toolkit 11a6d150). A seat is a `roster[]` entry
 * that is an object with a string `name`, and the FIRST such entry with the name decides. The
 * id must be a JSON INTEGER literal, at least 1 — a string `"7"` is refused, not coerced,
 * because the framework writes this field and a shape nobody declared is a question for it.
 * ⛔ AND SO IS AN INTEGRAL FLOAT (`7.0`, `7.00`, `7e0`), which PHP decodes to a float: the
 * toolkit at 11a6d150 accepted one, and its string compare then read the seat's own card as
 * another holder's — a false self-takeover (toolkit card#10868 comment 7670). Its fix refuses
 * any value that is not a plain integer literal, verdict `bad`; this port refuses it now, and
 * the corpus publishes those cases so the two ends are held to the same answer.
 *
 * ⚠ The verdict names are the toolkit's (`absent` / `nofield` / `nohost` / `bad`), so a line
 * from either tool can be read against the other's.
 */
final class RosterKanbanUser
{
    public const ABSENT = 'absent';

    public const NO_FIELD = 'nofield';

    public const NO_HOST = 'nohost';

    public const BAD = 'bad';

    /**
     * @param  ?int  $userId  the seat's kanban user id on the instance, or null with $why set
     * @param  ?string  $why  one of the constants above when there is no usable id
     * @param  mixed  $found  the value that made it `nofield` / `bad` (for the message)
     */
    private function __construct(
        public readonly ?int $userId,
        public readonly ?string $why,
        public readonly mixed $found = null,
    ) {}

    /**
     * @param  array<mixed>  $config  the decoded coordination.config.json
     */
    public static function lookUp(array $config, string $seat, string $host): self
    {
        $roster = $config['roster'] ?? null;
        foreach (is_array($roster) && array_is_list($roster) ? $roster : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || $entry['name'] !== $seat) {
                continue;
            }

            return self::verdict($entry, $host);
        }

        return new self(null, self::ABSENT);
    }

    /**
     * @param  array<mixed>  $entry
     */
    private static function verdict(array $entry, string $host): self
    {
        $map = $entry['kanban_user_id'] ?? null;
        // `{}` and `[]` decode alike; both carry no id for any instance, and the toolkit reads
        // them as `nohost` and `nofield` respectively — either way the id is MISSING.
        if (! is_array($map) || (array_is_list($map) && $map !== [])) {
            return new self(null, self::NO_FIELD, $map);
        }
        if (! array_key_exists($host, $map)) {
            return new self(null, self::NO_HOST);
        }
        $value = $map[$host];
        if (is_int($value) && $value >= 1) {
            return new self($value, null);
        }

        return new self(null, self::BAD, $value);
    }
}
