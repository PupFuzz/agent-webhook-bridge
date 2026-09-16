<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * Shared http(s) URL validation for the two INSTALL ENDPOINT config values: the
 * receiver base URL and a provider API base URL. One home so both reject
 * whitespace / a userinfo carrying characters RFC 3986 does not allow unencoded /
 * non-http schemes / hostless values with the same actionable message naming the field.
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
        // ⛔ BEFORE `parse_url()`, DELIBERATELY. These characters are illegal unencoded in a
        // userinfo, and `parse_url()` has no opinion about that — it accepted
        // `https://svc:pw"tail@host/webhooks` and handed back a host, so the value travelled on
        // to whatever would echo it (card#9528 (b): kanban's create refusal quoted the URL it
        // was given, as `…pw\"tail@…`, a form no reader of a finished string can recognise).
        // Asking here means the verdict is about the credential rather than about whatever
        // `parse_url()` made of the rest.
        if (self::userinfoCarriesAnIllegalCharacter($value)) {
            throw new ConfigException("{$field} '{$safe}' has a userinfo — the credential in front of the '@' — containing a character that is illegal unencoded in a URL (RFC 3986 allows only unreserved characters, the sub-delims !\$&'()*+,;= , ':' and percent-encoding there). Percent-encode it, or keep the credential out of the URL entirely");
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
     * Does this value's USERINFO carry a character RFC 3986 does not allow there unencoded?
     *
     * RFC 3986 § 3.2.1: `userinfo = *( unreserved / pct-encoded / sub-delims / ":" )`. So the
     * legal set is `A-Za-z0-9-._~`, `!$&'()*+,;=`, `:` and `%`. Everything else — a quote, a
     * backslash, a pipe, a bracket, a control byte — has to be percent-encoded to appear there,
     * and a value carrying one raw was never a valid URL.
     *
     * ⛔ THE USERINFO IS BOUND TO THE AUTHORITY, WHICH IS *NOT* THE BINDING
     * {@see SecretScrubber} USES, and the difference is deliberate rather than a drift. The
     * redactor binds at the LAST `@` in the whole value so that it over-removes; erring that way
     * costs a diagnostic. A VALIDATOR erring that way costs an operator a working install — it
     * would refuse `https://board.example/api?to=a@b.example`, where the `@` is a legal query
     * character and there is no userinfo at all. So the authority ends at the first `/`, `?` or
     * `#` after the scheme, exactly as the RFC says, and only an `@` INSIDE it delimits a
     * userinfo. `UrlValidatorTest::acceptedUserinfoValues` is the control for that.
     *
     * ⚠ IT CHECKS CHARACTERS, NOT PERCENT-ESCAPE WELL-FORMEDNESS. A lone `%` not followed by two
     * hex digits is also illegal under the RFC and is NOT refused here; stating the bound rather
     * than implying the check is wider than it is.
     */
    private static function userinfoCarriesAnIllegalCharacter(string $value): bool
    {
        $offset = preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $value, $m) === 1 ? strlen($m[0]) : 0;
        $authority = substr($value, $offset, strcspn($value, '/?#', $offset));
        $at = strrpos($authority, '@');
        if ($at === false) {
            return false;
        }

        return preg_match('/[^A-Za-z0-9\-._~!$&\'()*+,;=:%]/', substr($authority, 0, $at)) === 1;
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
