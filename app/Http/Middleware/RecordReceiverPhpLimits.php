<?php

namespace App\Http\Middleware;

use App\Bridge\Support\ReceiverPhpLimits;
use App\Bridge\Support\RedactedErrorText;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Has the web process that serves the receiver record its own PHP limits ({@see ReceiverPhpLimits},
 * card#12576), so `bridge:check` can compare the web SAPI's `post_max_size` with
 * `bridge.max_body_bytes`. After the response, on the webhook route only, and it never throws: a
 * state-dir fault here is logged and costs the check its measurement, never a delivery.
 */
class RecordReceiverPhpLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            ReceiverPhpLimits::observeThisProcess();
        } catch (Throwable $e) {
            Log::warning('bridge receiver: could not record the web PHP limits — bridge:check reports post_max_size UNMEASURED', RedactedErrorText::logContext($e));
        }
    }
}
