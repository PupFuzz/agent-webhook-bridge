<?php

namespace App\Bridge\Validation;

use App\Bridge\Support\LoopbackHost;

/**
 * SSRF gate for a loopback HTTP endpoint: scheme must be http, host must be
 * 127.0.0.1 / localhost / [::1], and no userinfo component. Shared by
 * ChannelPushHandler and WritebackAlertNotifier so the whitelist can't drift.
 *
 * Normalizes scheme + host: PHP's parse_url (unlike Python's urlparse) does not
 * lowercase the scheme and keeps the brackets on an IPv6 host, so `HTTP://` and
 * `[::1]` would otherwise miss the whitelist.
 */
final class LocalhostUrl
{
    /**
     * @param  string  $subject  Message subject (e.g. "channel_push: payload.url").
     *
     * @throws EndpointValidationException
     */
    public static function assertValid(string $url, string $subject): void
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'http') {
            throw new EndpointValidationException("{$subject} must be http:// (loopback only)");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new EndpointValidationException("{$subject} must not contain a userinfo component");
        }
        if (! LoopbackHost::matches($parts['host'] ?? '')) {
            throw new EndpointValidationException("{$subject} must point at 127.0.0.1, localhost, or [::1]");
        }
    }
}
