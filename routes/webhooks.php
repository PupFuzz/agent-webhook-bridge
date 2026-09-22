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
// RecordWebhookOutcome is FIRST so its terminate() sees every final status on this route,
// the gates' own 5xx included — and only this route's (card#10158).
//
// URL: POST /webhooks/<provider>?b=<scope_id>
Route::post('/webhooks/{provider}', [WebhookController::class, 'receive'])
    ->middleware([RecordWebhookOutcome::class, EnvelopeSizeLimit::class, VerifyHmacSignature::class]);
