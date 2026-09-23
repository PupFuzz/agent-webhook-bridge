<?php

namespace App\Http\Controllers\AgentTools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Tools\BoardToolAgentResolver;
use App\Bridge\Tools\BoardToolArgs;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\ClientVersion;
use App\Bridge\Tools\DispatchOutcome;
use App\Bridge\Tools\ToolCallBody;
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

        // The body is parsed by the ONE primitive both doors share (card#10106), on the raw
        // bytes, before any field is read: `input()` sits on `Request::json()`, which turns a
        // body that never parsed into `[]` and would answer it with the `tool` refusal below.
        // ⛔ The Content-Type refusal is this door's own and comes first because, without a
        // JSON Content-Type, `input()` reads form fields and the query string rather than the
        // body — so a body that parses would still reach the dispatcher with no `tool`.
        if (! $request->isJson()) {
            return $this->refuse(422, 'request Content-Type must be application/json — the body is read as '.ToolCallBody::SHAPE);
        }
        $parseResult = ToolCallBody::parse($request->getContent());
        if ($parseResult instanceof DispatchOutcome) {
            return response()->json($parseResult->body(), $parseResult->status);
        }
        // ⛔ Only the REFUSAL is used here; the decoded object is deliberately discarded.

        // The fields are still read through `input()`, deliberately: that is the value the
        // global TrimStrings / ConvertEmptyStringsToNull middleware has normalised, which is
        // what {@see BoardToolArgs} reproduces for the ssh door. Everything after this —
        // tool resolution, args validation, writeback, invocation, exception→status mapping —
        // lives in the shared dispatcher so the ssh door yields the byte-identical body.
        $toolName = $request->input('tool');
        if (! is_string($toolName)) {
            $toolName = '';
        }
        // ⛔ STATED, NEVER MEASURED (card#7836 / DL-316). This door cannot discriminate and
        // must not pretend to: `LoopbackOnly` pins the peer to 127.0.0.1 for the seat and
        // for `bridge:check --probe-tools` alike, BY CONSTRUCTION, so there is no observable
        // here that separates them. Reading SSH_CONNECTION at this point would be worse than
        // useless — the PHP process serving this request can inherit one legitimately (an
        // operator running `php artisan serve` inside an ssh session), which would mint the
        // STRONGER client-half verdict out of a variable that says nothing about the caller.
        // ⛔ OPTIONAL, AND IT CANNOT REFUSE (card#8974 / DL-364) — the same contract the ssh
        // door states. `input()` yields null for an absent key and this route validates
        // nothing else about the body, so a caller predating the field, or one sending
        // anything {@see ClientVersion} will not take, reaches the dispatcher unchanged with
        // null recorded. The bearer is what authorizes this call; a version never is.
        $outcome = $dispatcher->dispatch($toolName, $request->input('args', []), $agent->config, $agent->agentName, CallProvenance::NotSshd, ClientVersion::fromCall($request->input('client_version')));

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
