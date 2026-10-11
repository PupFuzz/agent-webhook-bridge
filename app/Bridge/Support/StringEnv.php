<?php

namespace App\Bridge\Support;

/**
 * A string `.env` setting that takes its default when unset OR set but empty, so the default is
 * written once — in config/bridge.php — and no reader has to re-default an empty value
 * (card#11714). `env('KEY', 'default')` alone applies the default only when the key is unset:
 * `KEY=` reaches the config value as `''`.
 *
 * Anything other than a non-empty string is the default too: Laravel's env repository turns
 * `true`/`false`/`null` into a bool or null, and none of those is a string setting's value.
 *
 * The `env()` read stays in config/bridge.php, as with {@see CsvEnv}: env() is only safe inside
 * the config directory (it returns null once config is cached), so this takes the resolved value.
 */
final class StringEnv
{
    public static function orDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
