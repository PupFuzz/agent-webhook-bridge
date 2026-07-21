<?php

namespace App\Http\Controllers\AgentTools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Tools\BoardToolAgentResolver;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Writeback\WritebackClientFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
    public function call(Request $request, BoardToolsRegistry $tools): JsonResponse
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

        $toolName = $request->input('tool');
        if (! is_string($toolName) || $toolName === '') {
            return $this->refuse(422, 'request must carry a non-empty `tool`');
        }
        $tool = $tools->resolve($toolName);
        if ($tool === null) {
            return $this->refuse(422, "unknown tool `{$toolName}` (known: ".implode(', ', $tools->known()).')');
        }

        $args = $request->input('args', []);
        if (! is_array($args)) {
            return $this->refuse(422, '`args` must be an object');
        }

        try {
            $client = WritebackClientFactory::make();   // ConfigException on a missing/insecure writeback token
        } catch (ConfigException $e) {
            Log::warning('agent-tools: writeback client unavailable', ['agent' => $agent->agentName, 'tool' => $toolName, 'error' => $e->getMessage()]);

            return $this->refuse(503, 'board tools are not fully configured on this bridge (writeback token)');
        }

        try {
            $result = $tool->call($args, $agent->config, $client, $agent->agentName);
        } catch (ToolRefusalException $e) {
            Log::info('agent-tools: refused', ['agent' => $agent->agentName, 'tool' => $toolName, 'reason' => $e->getMessage()]);

            return $this->refuse(422, $e->getMessage());
        } catch (RequestException $e) {
            // A kanban error (4xx/5xx from upstream) — the caller may retry; do not
            // leak the upstream body.
            Log::warning('agent-tools: upstream kanban error', ['agent' => $agent->agentName, 'tool' => $toolName, 'status' => $e->response->status()]);

            return $this->refuse(502, 'upstream board error');
        }

        Log::info('agent-tools: ok', ['agent' => $agent->agentName, 'tool' => $toolName]);

        return response()->json(['ok' => true, 'tool' => $toolName, 'result' => $result]);
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
