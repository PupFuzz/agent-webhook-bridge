<?php

use App\Http\Controllers\Webhook\WebhookController;
use App\Http\Middleware\EnvelopeSizeLimit;
use App\Http\Middleware\RecordWebhookOutcome;
use App\Http\Middleware\VerifyHmacSignature;
use Illuminate\Support\Facades\Route;

// Registered outside the `web` group (see bootstrap/app.php) so webhook
// deliveries are NOT subject to CSRF or session middleware. The {provider}
// segment is intentionally unconstrained: an invalid/unknown provider is a
// 400 from VerifyHmacSignature (preserving the receiver's status contract),
// not a 404 from route matching.
//
// RecordWebhookOutcome is listed first so its handle() is outermost. Its terminate() sees the
// final status regardless of order — Laravel gathers terminable middleware from the ROUTE, not
// from whatever short-circuited the request — so the ORDER here is readability and the ROUTE is
// the load-bearing part: it scopes the record to this route's 5xx and no other's (card#10158).
//
// URL: POST /webhooks/<provider>?b=<scope_id>
Route::post('/webhooks/{provider}', [WebhookController::class, 'receive'])
    ->middleware([RecordWebhookOutcome::class, EnvelopeSizeLimit::class, VerifyHmacSignature::class]);
