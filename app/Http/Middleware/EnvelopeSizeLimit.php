<?php

namespace App\Http\Middleware;

use App\Bridge\Http\PlainTextResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject oversize bodies with 413 BEFORE the HMAC middleware reads + hashes
 * them, so an attacker can't make the receiver HMAC-compute over a multi-MB
 * payload only to reject the signature. Checks the operator-supplied
 * Content-Length first (cheap), then the actual body length (belt-and-braces
 * in case the header lies/is missing).
 */
class EnvelopeSizeLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('bridge.max_body_bytes', 256 * 1024);

        $contentLength = (int) $request->server('CONTENT_LENGTH', '0');
        if ($contentLength > $max) {
            return $this->tooLarge();
        }

        if (strlen($request->getContent()) > $max) {
            return $this->tooLarge();
        }

        return $next($request);
    }

    private function tooLarge(): Response
    {
        return PlainTextResponse::make('body_too_large', 413);
    }
}
