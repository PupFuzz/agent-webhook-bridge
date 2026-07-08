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

    /**
     * httpUrl + a transport floor for SECRET-BEARING endpoints (the kanban
     * api_base_url carries the writeback bearer token and, at provision time,
     * the freshly-minted webhook HMAC secret): cleartext http is rejected
     * unless the host is loopback (a local dev rig — no wire exposure). No
     * env escape hatch by design: an internal-network hostname is exactly the
     * case where "it's private anyway" quietly ships credentials in cleartext.
     */
    public static function secureHttpUrl(mixed $value, string $field): string
    {
        $value = self::httpUrl($value, $field);
        $parts = parse_url($value);
        $scheme = $parts['scheme'] ?? '';
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($scheme === 'http' && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new ConfigException("{$field} '{$value}' must use https — this endpoint receives the bearer token/webhook secret, and cleartext http would expose them on the wire (http is allowed only for loopback hosts)");
        }

        return $value;
    }
}
