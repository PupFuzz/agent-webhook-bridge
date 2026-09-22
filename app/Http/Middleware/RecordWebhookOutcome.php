<?php

namespace App\Http\Middleware;

use App\Bridge\Support\FaultMarker;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\WebhookOutageRecord;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Feed each webhook request's FINAL status to {@see WebhookOutageRecord} (card#10158).
 *
 * ⚑ WHY TERMINATE, AND WHY OUTERMOST ON THE ROUTE. `terminate()` receives the response the
 * client was sent — an exception escaping the controller has already been rendered into its
 * 5xx by the routing pipeline — and runs after that response is flushed, so nothing here can
 * alter a status, a body or a delay the upstream's redelivery keys off. Listed first on the
 * webhook route, it sees the gates' own 5xx (an unusable secret) as well as the controller's.
 * The route is the scope: no other route carries this middleware, so no other route's 5xx is
 * counted.
 *
 * ⛔ IT NEVER THROWS. `Http\Kernel::terminate()` calls terminable middleware BEFORE the
 * application's terminating callbacks and has no catch of its own, so a throw here would
 * skip retention and the job registry and die as an unhandled fatal in the FPM worker. A
 * state-file fault is logged — its own message only, never the request's exception — and
 * dropped.
 *
 * ⚠ NOT SEEN: a request that never reaches `terminate()`. PHP-FPM, the vhost or TLS down in
 * front of the app, and a fatal error (memory or time limit) inside it, return an error to
 * the upstream with no record written here.
 */
class RecordWebhookOutcome
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            WebhookOutageRecord::observe(
                $response->getStatusCode(),
                $request->attributes->get(WebhookOutageRecord::NEUTRAL_ATTRIBUTE) === true,
            );
        } catch (Throwable $e) {
            FaultMarker::log('bridge: could not update the webhook 5xx record', [
                'exception' => $e::class,
                'error' => RedactedErrorText::of($e),
            ]);
        }
    }
}
