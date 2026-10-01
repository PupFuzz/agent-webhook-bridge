<?php

namespace App\Bridge\Support;

use App\Bridge\Check\Checks\InstallFlagValuesCheck;
use Illuminate\Support\Env;
use LogicException;

/**
 * The ONE reader of an on/off `.env` setting (card#11029). Every on/off key a config file reads
 * goes through {@see self::get()}; `BoolEnvGuardTest` reds on a raw `(bool) env(` in config/.
 *
 * ⛔ WHY NOT `(bool) env(...)`: Laravel's env repository converts only `true`/`false` (and their
 * parenthesised forms) to bools, so `no`, `off` and every typo reach the cast as a non-empty
 * string, and the cast makes each of them TRUE — `BRIDGE_SPAWN_ENABLED=no` turned spawn on, and
 * `APP_DEBUG=no` turned debug on.
 *
 * THE VOCABULARY, case-insensitive and trimmed: `true`/`1`/`yes`/`on` are on;
 * `false`/`0`/`no`/`off` and the empty value are off. Unset is the setting's default. Anything
 * else is UNREADABLE: the setting takes its default — the bridge keeps serving, it never refuses
 * to boot over a flag — and {@see self::unreadable()} records the key and the value it could not
 * read, which `bridge:check` reports as a `fail` ({@see InstallFlagValuesCheck}). Laravel's own
 * `null`/`(null)` spelling reaches this as null and is therefore read as UNSET, not unreadable.
 *
 * ⛔ CALL IT FROM config/ ONLY — both {@see self::get()} and {@see self::unreadable()}. Each reads
 * the env repository directly, which holds the `.env` values only while the config files are
 * being evaluated; once `config:cache` has run, the `.env` is never loaded and either would answer
 * from the process environment alone. Larastan's `noEnvCallsOutsideOfConfig` cannot see an
 * `Env::get()` call, so `BoolEnvGuardTest` pins that nothing under app/ makes any static call on
 * this class.
 *
 * ⭐ WHY THE UNREADABLE RECORD IS A CONFIG VALUE: `config/bridge.php` stores
 * {@see self::unreadable()} as `bridge.unreadable_flags`, so `config:cache` freezes the record
 * together with the very values it describes, and `bridge:check` on a cached install reports the
 * value that install is actually running with — not a `.env` it can no longer read, or that was
 * edited after the cache was built.
 */
final class BoolEnv
{
    /**
     * Every key read through {@see self::get()}. {@see self::unreadable()} walks this list, so a
     * key read but not listed would never be reported — which is why {@see self::get()} refuses an
     * unlisted key, and `BoolEnvGuardTest` reds on a listed key no config file reads.
     */
    public const KEYS = [
        'APP_DEBUG',
        'BRIDGE_RETENTION_ENABLED',
        'BRIDGE_STANDUP_ENABLED',
        'BRIDGE_IDLE_NUDGE_ENABLED',
        'BRIDGE_JOBS_ENABLED',
        'BRIDGE_OWED_WRITE_RETRY_DISABLED',
        'BRIDGE_SPAWN_ENABLED',
    ];

    public static function get(string $key, bool $default): bool
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new LogicException("{$key} is read as an on/off setting but is not in BoolEnv::KEYS, so an unreadable value would never be reported");
        }

        $raw = Env::get($key);
        if (! is_bool($raw) && ! is_string($raw)) {
            return $default;
        }

        return self::parse($raw) ?? $default;
    }

    /**
     * The registered keys whose value could not be read, mapped to that value.
     *
     * @return array<string, string>
     */
    public static function unreadable(): array
    {
        $unreadable = [];
        foreach (self::KEYS as $key) {
            $raw = Env::get($key);
            if (is_string($raw) && self::parse($raw) === null) {
                $unreadable[$key] = $raw;
            }
        }

        return $unreadable;
    }

    /**
     * True or false for a readable value, null for an unreadable one. A bool is what the env
     * repository already converted (`true`, `(false)`, …) and is taken as it is.
     *
     * The vocabulary is PHP's `FILTER_VALIDATE_BOOL` with `FILTER_NULL_ON_FAILURE`, which trims,
     * ignores case, reads the empty string as false and answers null for anything else.
     */
    private static function parse(bool|string $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }
}
