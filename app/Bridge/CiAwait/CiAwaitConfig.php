<?php

namespace App\Bridge\CiAwait;

use App\Bridge\Exceptions\ConfigException;

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
