<?php

use App\Http\Controllers\AgentTools\AgentToolsController;
use App\Http\Middleware\LoopbackOnly;
use Illuminate\Support\Facades\Route;

// The two-way board tools ingress (DL-217). Registered OUTSIDE the `web` group
// (see bootstrap/app.php) — no CSRF, no session. The LoopbackOnly middleware is
// the NETWORK gate (the TCP peer must be loopback); the per-agent bearer is
// checked in the controller as defense-in-depth on top of it.
//
// URL: POST /agent-tools/call   body: {"tool": "...", "args": {...}}
Route::post('/agent-tools/call', [AgentToolsController::class, 'call'])
    ->middleware([LoopbackOnly::class]);
