<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * Shared http(s) URL validation for the config values this app reads as an http(s) URL: the
 * receiver base URL, a provider API base URL, and the idle-nudge base URL. One home so every
 * one of them rejects whitespace / non-http schemes / hostless values with the same actionable
 * message naming the field.
 *
 * ⛔ NO COUNT OF THOSE CALLERS IS KEPT HERE. The previous revision of this sentence said *the
 * two INSTALL ENDPOINT config values* while three callers read three keys, and a hand-kept
 * figure is a false claim with a maintenance schedule (canon #16). `command grep -rn
 * 'UrlValidator::' app/` prints the live list; read it rather than a number written here.
 *
 * ⛔ TWO TIERS, AND THE SPLIT IS AN OPERATOR RULING (card#9528, 2026-09-16), NOT A STYLE.
 * {@see self::httpUrl()} and {@see self::secureHttpUrl()} are asked at RUNTIME as well as at
 * setup — `WritebackClientFactory` on every writeback, `IdleNudgeConfig` on every nudge pass —
 * so what THEY refuse decides whether an install that works today keeps working. The
 * `configDoor…` pair adds the userinfo-character refusal, and is opted into only where a config
 * value is being JUDGED AND QUOTED for an operator: `bridge:check` and `bridge:provision`. A
 * caller about to USE the value keeps the acceptance it has today (canon #3 — a new need gets a
 * dedicated path, never a widened guard other callers rely on) and loses no protection by it:
 * the credential is kept off the operator's terminal by {@see SecretScrubber}, which runs on
 * every message below whichever entry point composed it.
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
        // ⚠ NO USERINFO-CHARACTER VERDICT HERE, ON PURPOSE. It belongs to
        // {@see self::configDoorHttpUrl()}, which owns why: this entry point is also a RUNTIME
        // gate, and a value it refuses stops an install whose board is moving fine.
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
     * `httpUrl()` + THE CONFIG-DOOR REFUSAL: a userinfo carrying a character RFC 3986 does not
     * allow there unencoded (card#9528 (b)). `httpUrl()` judged no userinfo character at all, so
     * `https://svc:pw"tail@host/webhooks` was accepted, sent upstream, and echoed back inside a
     * kanban 422 as `…pw\"tail@…` — a form neither `ProvisionCommand`'s own substitution nor the
     * scrubber's embedded-URL run could recognise. Such a value was never a valid URL.
     *
     * ⛔ WHY THIS IS ITS OWN ENTRY POINT RATHER THAN A WIDER `httpUrl()` (operator ruling,
     * 2026-09-16). An install carrying such a userinfo WORKS TODAY — measured: Guzzle parses
     * `svc:pw"tail` and normalizes it to `svc:pw%22tail` — so refusing inside `httpUrl()` would
     * break every writeback on an install whose board is moving, to close a surface the redactor
     * already covers. The refusal earns its cost at the DOOR, where the value is being judged and
     * quoted for an operator who can act on it, and nowhere else. Call sites opt in BY NAME, one
     * at a time, so a new caller inherits today's acceptance rather than a refusal nobody chose
     * for it.
     */
    public static function configDoorHttpUrl(mixed $value, string $field): string
    {
        return self::refusingAnIllegalUserinfo(self::httpUrl($value, $field), $field);
    }

    /**
     * {@see self::secureHttpUrl()} + the same config-door refusal. The https floor is asked
     * FIRST and its verdict wins: cleartext puts the credential on the wire, which is the worse
     * of the two faults and the one with a different remedy.
     */
    public static function configDoorSecureHttpUrl(mixed $value, string $field): string
    {
        return self::refusingAnIllegalUserinfo(self::secureHttpUrl($value, $field), $field);
    }

    /**
     * ⛔ THE MESSAGE NAMES THE RULE AND NOT THE OFFENDING CHARACTER. Naming it would put a byte
     * of the credential on the very stream this exists to keep it off (canon #20), and the value
     * is quoted through {@see SecretScrubber::url()} exactly as every other branch quotes it.
     */
    private static function refusingAnIllegalUserinfo(string $value, string $field): string
    {
        if (self::userinfoCarriesAnIllegalCharacter($value)) {
            throw new ConfigException("{$field} '".SecretScrubber::url($value)."' has a userinfo — the credential in front of the '@' — containing a character that is illegal unencoded in a URL (RFC 3986 allows only unreserved characters, the sub-delims !\$&'()*+,;= , ':' and percent-encoding there). Percent-encode it, or keep the credential out of the URL entirely");
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
