<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * Shared http(s) URL validation for the two INSTALL ENDPOINT config values: the
 * receiver base URL and a provider API base URL. One home so both reject
 * whitespace / non-http schemes / hostless values with the same actionable
 * message naming the field.
 *
 * A channel URL is NOT one of them. `channel.url` is shape-checked at its own
 * parse site in `AgentConfig` and its loopback gate belongs to the `channel_push`
 * handler; `alert_channel.url` has `LocalhostUrl`. Listing a caller this class
 * does not have is what sent a reader here looking for the https floor on it.
 *
 * ⛔ EVERY MESSAGE QUOTES {@see SecretScrubber::url()}'s OUTPUT, NEVER `$value` (card#8433).
 * These messages are rendered verbatim by `bridge:check` (`App\Bridge\Check\Checks\InstallEndpointUrlsCheck`)
 * and re-wrapped by `App\Bridge\Writeback\WritebackClientFactory::make()`, and
 * {@see self::secureHttpUrl()}'s own text says this field *receives the bearer
 * token/webhook secret* — so an operator who put a credential in the userinfo or the query
 * string had it echoed back by the validator that exists to protect it. ⚑ A redactor
 * reading the thrown MESSAGE cannot close this: once we have interpolated the value,
 * nothing marks which substring was the secret. It has to happen HERE, at the
 * interpolation (canon #20).
 */
final class UrlValidator
{
    public static function httpUrl(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new ConfigException("{$field} must be a non-empty string URL");
        }
        // Bound ONCE, before the first branch that quotes it, so no later branch can be
        // added that reaches for the raw `$value` because it was the variable in scope.
        $safe = SecretScrubber::url($value);
        if (preg_match('/\s/', $value) === 1) {
            throw new ConfigException("{$field} '{$safe}' contains whitespace; check for paste errors");
        }
        $parts = parse_url($value);
        if ($parts === false) {
            throw new ConfigException("{$field} '{$safe}' is not a valid URL");
        }
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new ConfigException("{$field} '{$safe}' must use http or https");
        }
        if (($parts['host'] ?? '') === '') {
            throw new ConfigException("{$field} '{$safe}' must have a host component");
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
        if ($scheme === 'http' && ! LoopbackHost::matches((string) ($parts['host'] ?? ''))) {
            throw new ConfigException("{$field} '".SecretScrubber::url($value)."' must use https — this endpoint receives the bearer token/webhook secret, and cleartext http would expose them on the wire (http is allowed only for loopback hosts)");
        }

        return $value;
    }
}
