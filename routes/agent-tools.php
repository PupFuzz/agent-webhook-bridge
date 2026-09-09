<?php

use App\Http\Controllers\AgentTools\AgentToolsController;
use App\Http\Middleware\LoopbackOnly;
use Illuminate\Support\Facades\Route;

// The two-way board tools ingress (DL-217). Registered OUTSIDE the `web` group
// (see bootstrap/app.php) — no CSRF, no session. The LoopbackOnly middleware is
// the NETWORK gate (the TCP peer must be loopback); the per-agent bearer is
// checked in the controller as defense-in-depth on top of it.
//
// URL: POST /agent-tools/call   body: {"tool": "...", "args": {...}, "client_version": "..."}
// `client_version` is OPTIONAL and cannot refuse a call (card#8974 / DL-364): it is the
// calling channel server's own snapshot version, recorded beside the call and read by
// nothing on the request path — absent, or of any shape App\Bridge\Tools\ClientVersion
// will not take, the call is accepted exactly as it was before the field existed.
Route::post('/agent-tools/call', [AgentToolsController::class, 'call'])
    ->middleware([LoopbackOnly::class]);
