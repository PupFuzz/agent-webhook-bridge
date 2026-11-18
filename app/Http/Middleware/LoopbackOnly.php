<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loopback-only ingress gate for the board-tools endpoint (DL-217). The webhook
 * ingress sets an HMAC-over-raw-body bar; a bearer-only route on the same
 * internet-reachable vhost would sit strictly below it, so this route is
 * NETWORK-gated first: the TCP peer (`$request->ip()`) must be loopback
 * (`127.0.0.0/8` or `::1`). The per-agent bearer (resolved in the controller) is
 * defense-in-depth ON TOP of this, not instead of it.
 *
 * This is deliberately NOT App\Bridge\Validation\LocalhostUrl::assertValid — that
 * validates an OUTBOUND URL host STRING (an SSRF gate for a URL the bridge is
 * about to call); testing the inbound TCP peer is a different check on a
 * different value (round-2 category-error fix). It also does NOT accept the
 * hostname "localhost" — a peer IP is always numeric.
 *
 * ⚠ SOUND ONLY WHILE NO PROXY IS TRUSTED. Apache + mod_proxy_fcgi passes the real
 * REMOTE_ADDR, and bootstrap/app.php removes the framework's global TrustProxies, so
 * $request->ip() is that peer and X-Forwarded-For is never read. Removing it is what
 * makes this hold: left in place with no proxies configured, TrustProxies trusts every
 * proxy on requests it takes for Laravel Forge, Vapor or Cloud (DL-502). Trusting any
 * proxy makes $request->ip() header-controlled and this gate bypassable.
 */
final class LoopbackOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isLoopback($request->ip())) {
            abort(403, 'this endpoint is reachable from loopback only');
        }

        return $next($request);
    }

    public static function isLoopback(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }
        // IPv4 loopback is the WHOLE 127.0.0.0/8 block, not just 127.0.0.1.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '127.');
        }
        // IPv6: canonicalize (::1, and the IPv4-mapped ::ffff:127.0.0.1 form).
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if ($packed === inet_pton('::1')) {
            return true;
        }
        $mapped = @inet_pton('::ffff:127.0.0.1');
        // An IPv4-mapped loopback (::ffff:127.0.0.0/8) — compare the trailing 4 bytes.
        if ($mapped !== false && strlen($packed) === 16 && substr($packed, 0, 12) === substr($mapped, 0, 12)) {
            return $packed[12] === "\x7f";   // 0x7f == 127
        }

        return false;
    }
}
