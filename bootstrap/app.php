<?php

use App\Bridge\Console\StrippingConsoleKernel;
use App\Bridge\Support\RedactedErrorText;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Webhook routes load OUTSIDE the web group — no CSRF, no session.
        // They carry only their own HMAC + size-limit middleware. The board-tools
        // ingress (DL-217) loads the same way, carrying its own loopback gate.
        then: function (): void {
            Route::group([], base_path('routes/webhooks.php'));
            Route::group([], base_path('routes/agent-tools.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Registered FIRST and returning void, so reporting continues: a RequestException carried as
        // ANY exception's previous is rewritten before the default log line or a renderer prints
        // the chain, which the typed callback below cannot see (card#9486, DL-389).
        $exceptions->report(function (Throwable $e): void {
            RedactedErrorText::replaceMessagesInChain($e);
        });

        // ⛔ A RequestException that escapes the bridge — a durable writeback handler rethrows a
        // transient 5xx on purpose, so kanban re-delivers — reaches this handler with Laravel's
        // message, a body summary already cut at RequestException::$truncateAt. Its text is
        // replaced IN PLACE before anything renders it (the console renderer does not run map()),
        // and it is logged here in the one log-context shape, which stops the default log line
        // (card#9486, DL-389). Class, status, response and trace are untouched.
        $exceptions->report(function (RequestException $e): false {
            RedactedErrorText::replaceMessage($e);
            Log::error($e->getMessage(), RedactedErrorText::logContext($e));

            return false;
        });
    })->create();

// ⛔ The console output choke (card#9251, DL-393). Bound HERE, after create(), and never in a
// service provider: `Application::handleCommand()` resolves the kernel before providers are
// registered, so a provider re-bind would leave the unwrapped kernel running, silently.
$app->singleton(ConsoleKernel::class, StrippingConsoleKernel::class);

return $app;
