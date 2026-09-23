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
 * ⚑ WHY TERMINATE, AND WHY ON THE ROUTE. `terminate()` receives the response the client was
 * sent — an exception escaping the controller has already been rendered into its 5xx by the
 * routing pipeline — and runs after that response is flushed, so nothing here can alter a
 * status, a body or a delay the upstream's redelivery keys off. It sees the gates' own 5xx
 * (an unusable secret) as well as the controller's, and it sees them whatever ORDER this
 * middleware is declared in: `Http\Kernel::terminateMiddleware()` calls `terminate()` on
 * everything `gatherRouteMiddleware()` returns and never asks which one short-circuited. The
 * ROUTE is the load-bearing half — no other route carries this middleware, so no other
 * route's 5xx is counted.
 *
 * ⛔ IT NEVER THROWS. `Http\Kernel::terminate()` calls terminable middleware BEFORE the
 * application's terminating callbacks and has no catch of its own, so a throw here would
 * skip retention and the job registry and die as an unhandled fatal in the FPM worker. A
 * state-file fault is logged — its own message only, never the request's exception — and
 * dropped.
 *
 * ⚠ WHAT IT SEES IS A 5xx WITH A ROUTE BOUND, NOT "a 5xx from this app".
 * `Kernel::gatherRouteMiddleware()` returns `[]` when `$request->route()` is null, and this
 * middleware is registered on the route, so nothing that answers before routing is recorded:
 *  - MAINTENANCE MODE, on BOTH of its paths, because what they share is answering before the
 *    ROUTER: `public/index.php` requires `storage/framework/maintenance.php`, whose stub
 *    answers before the framework boots ONLY where `php artisan down --render` prerendered a
 *    template (a bare `down` writes no `template` key, so the stub returns and the framework
 *    handles it) — and then the GLOBAL `PreventRequestsDuringMaintenance` throws the
 *    `HttpException($data['status'] ?? 503)`, still ahead of `dispatchToRouter`. Deploy-window
 *    503s are a measured fact on this project, not a hypothetical.
 *  - A BOOTSTRAP, SERVICE-PROVIDER OR CONFIG FAILURE — `Kernel::handle()` bootstraps inside
 *    its own try and renders the throw as a 500 with no route bound, so a broken config cache
 *    or a provider that throws after a deploy 500s EVERY delivery and records none of them.
 *  - PHP-FPM, the vhost or TLS down in front of the app, and a fatal error (memory or time
 *    limit) inside it, which never reach `terminate()` at all.
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
