<?php

namespace App\Bridge\CiAwait;

use App\Bridge\Exceptions\ConfigException;

/**
 * The one reader of `bridge.ci_await.*` (card#11200 / DL-452). A TTL this install cannot use is
 * REFUSED rather than clamped: a clamped value is a wait the seat did not get, with nothing saying
 * so, where a refusal is one install fault `ci_await` and `bridge:check` both name.
 */
final class CiAwaitConfig
{
    public const TTL_MIN = 60;

    public const TTL_MAX = 604800;

    /** @throws ConfigException naming the value read when it is not a whole number of seconds in range */
    public static function ttlSeconds(): int
    {
        $raw = config('bridge.ci_await.ttl');
        $ttl = is_int($raw) ? $raw : (is_string($raw) && preg_match('/\A[0-9]+\z/', $raw) === 1 ? (int) $raw : null);
        if ($ttl === null || $ttl < self::TTL_MIN || $ttl > self::TTL_MAX) {
            $shown = is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw);

            throw new ConfigException('BRIDGE_CI_AWAIT_TTL is '.$shown.' — it must be a whole number of seconds from '.self::TTL_MIN.' to '.self::TTL_MAX.' (default 21600, 6 h)');
        }

        return $ttl;
    }
}
