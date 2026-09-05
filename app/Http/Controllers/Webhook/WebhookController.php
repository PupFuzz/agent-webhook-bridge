<?php

namespace App\Http\Controllers\Webhook;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Exceptions\InvalidEnvelopeException;
use App\Bridge\Http\PlainTextResponse;
use App\Bridge\Retention\RetentionGate;
use App\Bridge\Scheduling\JobSchedulerGate;
use App\Bridge\Standup\StandupGate;
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
 * provider, which is exactly the arrival the gate keys off. The opt-in standup
 * digest (DL-306) rides the same arrival for the same reason — a report is not a
 * good enough reason to add a scheduler.
 *
 * The periodic-job registry (card#8425 / DL-325) rides it too, and is the reason the
 * sentence above no longer says "this design has no cron". It says something narrower
 * and truer: the EVENT GATE IS STILL THE DEFAULT AND STILL COMPLETE ON ITS OWN. An
 * install that adds no crontab line runs its whole job registry from this arrival,
 * exactly as it runs retention. An install that additionally adopts `bridge:tick`
 * gains the one thing no arrival can provide — periodic work when nothing arrives
 * (DL-306's documented dead end). Both ingresses share one bounded, non-blocking,
 * after-response pass, so nothing here reaches the client's request.
 */
class WebhookController extends Controller
{
    public function __construct(
        private DispatchService $dispatcher,
        private RetentionGate $retentionGate,
        private StandupGate $standupGate,
        private JobSchedulerGate $jobGate,
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

        // NO `is_array(...) ? ... : []` FALLBACK (DL-315): `$adapter->parse($request, $body)` above
        // has already decoded THIS SAME STRING and is CONTRACTUALLY REQUIRED to have thrown
        // InvalidEnvelopeException — returned as 400 — on anything that does not decode to an
        // array. A second decode of the same bytes cannot answer differently, so the ternary was
        // a read-time fallback for a state that has already been refused, and it read as an
        // endorsed pattern in the file a maintainer greps first.
        //
        // The obligation is the INTERFACE's, not AbstractWebhookAdapter's: an adapter may skip
        // that base class (docs/provider-adapters.md tells a non-sha256= provider to), and one
        // that also skips the refusal reaches the dispatch call below with a non-array — a
        // TypeError, i.e. a 500 on a deterministically-bad body the upstream then redelivers
        // forever. WebhookReceiveTest::test_every_supported_provider_refuses_a_scalar_json_body
        // is what keeps that contract true of every registered provider.
        /** @var array<mixed> $payload */
        $payload = json_decode($body, true);
        $this->dispatcher->dispatch($provider, $scopeId, $event, $payload);

        // Only this path stores an event, so only this path can have grown the
        // stores. The pass itself runs after the response below is sent.
        $this->retentionGate->schedule();

        // Off unless the install opts in; both passes run after the response below.
        $this->standupGate->schedule();

        // The periodic-job registry's EVENT ingress (DL-325). Registered on every install:
        // with no rows it costs one indexed query per `jobs.min_pass_interval` and does
        // nothing else, which is what makes adopting the tick opt-in rather than required.
        $this->jobGate->schedule();

        return $this->plain('ok', 200);
    }

    private function plain(string $body, int $code): Response
    {
        return PlainTextResponse::make($body, $code);
    }
}
