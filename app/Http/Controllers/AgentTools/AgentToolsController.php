<?php

namespace App\Http\Controllers\AgentTools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Tools\BoardToolAgentResolver;
use App\Bridge\Tools\BoardToolDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /agent-tools/call — the Laravel side of the two-way board tools (DL-217).
 * Reached only from loopback (the LoopbackOnly middleware is the network gate);
 * this controller adds the defense-in-depth per-agent bearer, resolves the
 * caller's agent from the token (the agent name is DERIVED from the bearer, never
 * from the body), and dispatches the named tool through the ToolRegistry onto the
 * shared least-privilege writeback client — the kanban token never leaves the box.
 *
 * This is a NEW ingress, not the webhook path: it does not pass the HMAC gate,
 * the dedupCreate ledger, or the classifier. Its audit trail is its own — one
 * structured log line per call (agent, tool, outcome). The single-writer
 * invariant it preserves is precise: the kanban token + writer stay on the box.
 */
final class AgentToolsController
{
    public function call(Request $request, BoardToolDispatcher $dispatcher): JsonResponse
    {
        $bearer = $this->bearer($request);
        if ($bearer === null) {
            return $this->refuse(401, 'missing bearer token');
        }

        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (ConfigException $e) {
            // A malformed agent YAML is fail-closed everywhere else too — surface it
            // as a service fault, not a caller error.
            return $this->refuse(503, 'agent config error');
        }
        $resolver = new BoardToolAgentResolver($configs);
        $agent = $resolver->resolve($bearer);
        if ($agent === null) {
            // Do not distinguish "unknown token" from "collided/unreadable token" to
            // the caller — both are "you are not an authenticated board-tools agent".
            return $this->refuse(401, 'unrecognized bearer token');
        }

        // The tool key is extracted from the HTTP body here (this door's transport
        // shape); everything after agent-resolution — tool resolution, args
        // validation, writeback, invocation, exception→status mapping — lives in
        // the shared dispatcher so the ssh door yields the byte-identical body.
        $toolName = $request->input('tool');
        if (! is_string($toolName)) {
            $toolName = '';
        }
        $outcome = $dispatcher->dispatch($toolName, $request->input('args', []), $agent->config, $agent->agentName);

        return response()->json($outcome->body(), $outcome->status);
    }

    private function bearer(Request $request): ?string
    {
        $token = $request->bearerToken();

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function refuse(int $status, string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $message], $status);
    }
}
