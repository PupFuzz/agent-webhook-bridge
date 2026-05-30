<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * Shared http(s) URL validation for config values (the receiver base URL, a
 * provider API base URL, a channel URL). One home so every URL-shaped config
 * field rejects whitespace / non-http schemes / hostless values with the same
 * actionable message naming the field.
 */
final class UrlValidator
{
    public static function httpUrl(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new ConfigException("{$field} must be a non-empty string URL");
        }
        if (preg_match('/\s/', $value) === 1) {
            throw new ConfigException("{$field} '{$value}' contains whitespace; check for paste errors");
        }
        $parts = parse_url($value);
        if ($parts === false) {
            throw new ConfigException("{$field} '{$value}' is not a valid URL");
        }
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new ConfigException("{$field} '{$value}' must use http or https");
        }
        if (($parts['host'] ?? '') === '') {
            throw new ConfigException("{$field} '{$value}' must have a host component");
        }

        return $value;
    }
}
