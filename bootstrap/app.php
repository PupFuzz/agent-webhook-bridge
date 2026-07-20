<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
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
    })->create();
