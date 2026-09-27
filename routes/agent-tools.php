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
// calling channel server's own snapshot version, recorded beside the call. A request that
// omits it, or carries any value App\Bridge\Tools\ClientVersion will not take, is accepted
// exactly as it was before the field existed — what it may change is a refusal's TEXT, which
// App\Bridge\Tools\ClientUpdateClause owns (card#10566 / DL-426; not restated here).
Route::post('/agent-tools/call', [AgentToolsController::class, 'call'])
    ->middleware([LoopbackOnly::class]);

// URL: POST /agent-tools/client   body: {"op": "client_manifest"} | {"op": "client_pack", "bridge_release": "X.Y.Z"}
// The client-update door (DL-430): a seat's channel-server updater asks its own bridge for the
// published client pack. Same loopback gate and bearer as /agent-tools/call; never dispatched
// as a board tool. App\Bridge\ClientUpdate\ClientUpdateDoor owns the ops and their answers.
Route::post('/agent-tools/client', [AgentToolsController::class, 'client'])
    ->middleware([LoopbackOnly::class]);
