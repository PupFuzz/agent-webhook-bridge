<?php

namespace App\Bridge\Support;

/**
 * Single source of truth for "is this host the loopback interface" — the
 * allowlist plus the normalization (lowercase, strip IPv6 brackets) that the
 * SSRF gates share. Extracted so the loopback-only alert/channel gate
 * (LocalhostUrl::assertValid) and the http-loopback exception for
 * secret-bearing endpoints (UrlValidator::secureHttpUrl) cannot drift on what
 * counts as loopback.
 */
final class LoopbackHost
{
    public static function matches(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
