<?php

namespace App\Http\Controllers\Webhook;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Exceptions\InvalidEnvelopeException;
use App\Bridge\Http\PlainTextResponse;
use App\Bridge\Retention\RetentionGate;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook receiver entry point. Runs AFTER VerifyHmacSignature +
 * EnvelopeSizeLimit, so the request is already trusted and size-bounded.
 * Parses the envelope (malformed → 400), short-circuits ping events, and
 * enforces the payload-scope vs URL-scope double-check (scope_mismatch → 401).
 *
 * The synchronous dispatch (store event → classify → stage inbox → run
 * handlers) runs inline via DispatchService and returns 200 only when every
 * subscribed agent is processed. A transient/durability failure inside dispatch
 * propagates to a 5xx (kanban-board redelivers); a deterministic classifier/
 * handler failure is recorded and still acks 200.
 *
 * Retention (DL-199) is queued here rather than in DispatchService::dispatch():
 * dispatch() has a second, non-inbound caller — `bridge:replay` — where the gate
 * would fire for no benefit. `receive` is the shared inbound entry across every
 * provider, which is exactly the arrival the gate keys off.
 */
class WebhookController extends Controller
{
    public function __construct(
        private DispatchService $dispatcher,
        private RetentionGate $retentionGate,
    ) {}

    public function receive(Request $request): Response
    {
        $provider = (string) $request->attributes->get('bridge.provider');
        $scopeId = (string) $request->attributes->get('bridge.scope_id');
        $body = (string) $request->attributes->get('bridge.body');

        $adapter = WebhookAdapterFactory::for($provider);

        try {
            $event = $adapter->parse($request, $body);
        } catch (InvalidEnvelopeException) {
            return $this->plain('invalid_envelope', 400);
        }

        if ($adapter->isPing($event)) {
            return $this->plain('pong', 200);
        }

        // Defense against a holder of one scope's secret posting events
        // claiming a different scope: the URL scope (used to find the secret)
        // must match the payload's claimed scope.
        if ($event->scopeId !== $scopeId) {
            return $this->plain('scope_mismatch', 401);
        }

        $payload = json_decode($body, true);
        $this->dispatcher->dispatch($provider, $scopeId, $event, is_array($payload) ? $payload : []);

        // Only this path stores an event, so only this path can have grown the
        // stores. The pass itself runs after the response below is sent.
        $this->retentionGate->schedule();

        return $this->plain('ok', 200);
    }

    private function plain(string $body, int $code): Response
    {
        return PlainTextResponse::make($body, $code);
    }
}
