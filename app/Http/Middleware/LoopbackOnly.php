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
 * A REQUEST CARRYING A FORWARDING HEADER IS REFUSED, whatever its peer (DL-503, DL-504). A
 * reverse proxy or tunnel on the same box (nginx, cloudflared) reaches Apache over loopback, so
 * the peer test alone would admit every caller it forwards. Any `X-Forwarded-*` header, RFC 7239
 * `Forwarded`, `Via`, `X-Real-IP` or `CF-Connecting-IP` marks the call as proxied, and a proxied
 * call is not a same-box call. The bound: a proxy that adds none of these headers (a stock nginx
 * `proxy_pass`, or one using a vendor header not listed here) is indistinguishable from a local
 * client.
 *
 * The peer test relies on no proxy being trusted: Apache + mod_proxy_fcgi passes the real
 * REMOTE_ADDR, and bootstrap/app.php removes the framework's global TrustProxies, so
 * $request->ip() is that peer (DL-502). The header refusal does not depend on that: a request
 * whose peer a trusted proxy could rewrite carries one of these headers, and is refused
 * on that before its peer is read.
 */
final class LoopbackOnly
{
    /** Names as Symfony's HeaderBag stores them: lowercased, `-` not `_`. */
    private const FORWARDING_HEADERS = ['forwarded', 'via', 'x-real-ip', 'cf-connecting-ip'];

    /**
     * The refusal's phrase before the header name. `bridge:check --probe-tools` reads the name
     * back out of the 403 page after it, so the phrase is shared rather than copied.
     */
    public const FORWARDING_REFUSAL = 'the request carries the forwarding header';

    public function handle(Request $request, Closure $next): Response
    {
        $forwarding = self::forwardingHeader($request);
        if ($forwarding !== null) {
            abort(403, 'this endpoint is reachable from loopback only: '.self::FORWARDING_REFUSAL." {$forwarding}, so it came through a proxy");
        }
        if (! self::isLoopback($request->ip())) {
            abort(403, 'this endpoint is reachable from loopback only');
        }

        return $next($request);
    }

    private static function forwardingHeader(Request $request): ?string
    {
        foreach ($request->headers->keys() as $name) {
            if (in_array($name, self::FORWARDING_HEADERS, true) || str_starts_with($name, 'x-forwarded-')) {
                return $name;
            }
        }

        return null;
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
