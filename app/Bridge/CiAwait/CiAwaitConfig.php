<?php

namespace App\Bridge\CiAwait;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Validation\ScopeId;

/**
 * The one reader of `bridge.ci_await.*` (card#11200 / DL-452). A value this install cannot use is
 * REFUSED rather than clamped: a clamped value is a setting the operator did not choose, with
 * nothing saying so, where a refusal is one install fault `ci_await` and `bridge:check` both name.
 */
final class CiAwaitConfig
{
    public const TTL_MIN = 60;

    public const TTL_MAX = 604800;

    public const MAX_PER_SEAT_MAX = 10000;

    public const READ_COOLDOWN_MAX = 3600;

    public const SWEEP_READS_MAX = 100;

    public const SEAT_READS_PER_HOUR_MAX = 10000;

    /** @throws ConfigException naming the value read */
    public static function ttlSeconds(): int
    {
        return self::int('ttl', 'BRIDGE_CI_AWAIT_TTL', self::TTL_MIN, self::TTL_MAX, '21600, 6 h');
    }

    /** How many heads one seat may await at once. @throws ConfigException naming the value read */
    public static function maxPerSeat(): int
    {
        return self::int('max_per_seat', 'BRIDGE_CI_AWAIT_MAX_PER_SEAT', 1, self::MAX_PER_SEAT_MAX, '50');
    }

    /**
     * How recently an ANSWERED read of a head lets a registration skip its own read, in seconds;
     * 0 always reads. @throws ConfigException naming the value read
     */
    public static function readCooldownSeconds(): int
    {
        return self::int('read_cooldown', 'BRIDGE_CI_AWAIT_READ_COOLDOWN', 0, self::READ_COOLDOWN_MAX, '60');
    }

    /**
     * How many heads one `ci-await-sweep` pass may read, install-wide. It bounds the sweep's GitHub
     * cost per hour at this times 3600 over the sweep's interval. @throws ConfigException naming the value read
     */
    public static function sweepReads(): int
    {
        return self::int('sweep_reads', 'BRIDGE_CI_AWAIT_SWEEP_READS', 1, self::SWEEP_READS_MAX, '10');
    }

    /**
     * How many GitHub runs reads ONE agent's registrations may cause per FIXED one-hour window,
     * opened by that agent's first counted read (card#11283) — not a rolling hour: up to twice this
     * can land around a window boundary.
     * Past it a registration is still stored — only its own read is skipped, the sweep and a
     * `workflow_run` delivery settle it — so a seat looping register/cancel, or registering fresh
     * SHAs, cannot spend the install's GitHub quota. @throws ConfigException naming the value read
     */
    public static function seatReadsPerHour(): int
    {
        return self::int('seat_reads_per_hour', 'BRIDGE_CI_AWAIT_SEAT_READS_PER_HOUR', 1, self::SEAT_READS_PER_HOUR_MAX, '60');
    }

    /**
     * How long after registration a wait on a repo with too little CI history is overdue, in
     * seconds (card#11674): {@see OverdueDeadline} derives the deadline from the repo's own recent
     * heads, and falls back to this. @throws ConfigException naming the value read
     */
    public static function overdueDefaultSeconds(): int
    {
        return self::int('overdue_default', 'BRIDGE_CI_AWAIT_OVERDUE_DEFAULT', self::TTL_MIN, self::TTL_MAX, '1800, 30 min');
    }

    /**
     * The received repos declared to have NO CI (card#11696), lower-cased ({@see CiAwaitService::key()}):
     * `ci_await` refuses them as `repo_not_ci` and `bridge:check` does not read their workflow runs.
     * A declaration, never derived — a repo whose token cannot read its Actions looks the same as one
     * with no Actions, and only the operator knows which it is.
     *
     * @return list<string>
     *
     * @throws ConfigException naming an entry that is not `owner/name`
     */
    public static function noCiRepos(): array
    {
        $raw = config('bridge.ci_await.no_ci_repos');
        $entries = is_array($raw) ? $raw : [];
        $repos = [];
        foreach ($entries as $entry) {
            if (! is_string($entry) || substr_count($entry, '/') !== 1 || ! ScopeId::matches($entry)) {
                $shown = is_scalar($entry) ? var_export($entry, true) : get_debug_type($entry);

                throw new ConfigException("BRIDGE_CI_AWAIT_NO_CI_REPOS lists {$shown} — each entry must be a GitHub repo as owner/name");
            }
            $repos[] = CiAwaitService::key($entry);
        }

        return $repos;
    }

    private static function int(string $key, string $env, int $min, int $max, string $default): int
    {
        $raw = config("bridge.ci_await.{$key}");
        $value = is_int($raw) ? $raw : (is_string($raw) && preg_match('/\A[0-9]{1,9}\z/', $raw) === 1 ? (int) $raw : null);
        if ($value === null || $value < $min || $value > $max) {
            $shown = is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw);

            throw new ConfigException("{$env} is {$shown} — it must be a whole number from {$min} to {$max} (default {$default})");
        }

        return $value;
    }
}
