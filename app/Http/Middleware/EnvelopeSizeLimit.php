<?php

namespace App\Http\Middleware;

use App\Bridge\Http\PlainTextResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject oversize bodies with 413 BEFORE the HMAC middleware reads + hashes
 * them, so an attacker can't make the receiver HMAC-compute over a multi-MB
 * payload only to reject the signature. Checks the operator-supplied
 * Content-Length first (cheap), then the actual body length (belt-and-braces
 * in case the header lies/is missing).
 *
 * Every refusal is LOGGED (card#12576): neither GitHub nor kanban-board retries a 413, so
 * without the line a refused delivery leaves no trace on the bridge at all. The line names the
 * delivery — provider, scope, GitHub's event and delivery headers, the size — and never carries
 * the body. Everything in it is unauthenticated (this runs before HMAC), so each value is cut to
 * a bound.
 */
class EnvelopeSizeLimit
{
    private const LOGGED_VALUE_MAX = 200;

    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('bridge.max_body_bytes');

        $contentLength = (int) $request->server('CONTENT_LENGTH', '0');
        if ($contentLength > $max) {
            return $this->tooLarge($request, $contentLength, $max);
        }

        $bytes = strlen($request->getContent());
        if ($bytes > $max) {
            return $this->tooLarge($request, $bytes, $max);
        }

        return $next($request);
    }

    private function tooLarge(Request $request, int $bytes, int $max): Response
    {
        $provider = $request->route('provider');
        Log::warning('bridge receiver: 413 body_too_large — the delivery was refused unread and will not be retried by the sender (raise BRIDGE_MAX_BODY_BYTES to accept it)', [
            'provider' => $this->bounded(is_string($provider) ? $provider : null),
            'scope' => $this->bounded($request->query('b')),
            'event' => $this->bounded($request->header('X-GitHub-Event')),
            'delivery' => $this->bounded($request->header('X-GitHub-Delivery')),
            'bytes' => $bytes,
            'max_bytes' => $max,
        ]);

        return PlainTextResponse::make('body_too_large', 413);
    }

    private function bounded(mixed $value): ?string
    {
        return is_string($value) ? mb_strcut($value, 0, self::LOGGED_VALUE_MAX) : null;
    }
}
