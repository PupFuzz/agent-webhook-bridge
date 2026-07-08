<?php

namespace App\Bridge\Adapters;

use App\Bridge\Exceptions\InvalidEnvelopeException;
use Illuminate\Http\Request;

/**
 * GitHub webhook adapter (2026 delivery contract).
 *
 *   X-Hub-Signature-256: sha256=<HMAC-SHA256 of body bytes, hex>
 *   X-GitHub-Event:      pull_request | push | issues | ping | ...
 *   X-GitHub-Delivery:   <uuid>
 *
 * event_type → "<event>.<body.action>" when an action is present, else
 * "<event>" (push/ping have none). scope_id → repository.full_name (org/repo),
 * actor_id → sender.id (the IMMUTABLE numeric account id — usernames rename;
 * see DL-002). A `ping` is GitHub's connectivity test.
 *
 * delivery_id (the at-least-once dedup key) is sha256 of the SIGNED body, NOT
 * the X-GitHub-Delivery header (DL-176): the header is outside the HMAC, so a
 * captured validly-signed body resent with a fresh header would otherwise mint
 * a new dedup key and re-dispatch. Binding the key to signed bytes collapses
 * any replay to the original row. Consequence: GitHub's operator "Redeliver"
 * button is deduped too — the sanctioned reprocess path is `bridge:replay <id>`.
 * The header is still REQUIRED (envelope contract) for parity with GitHub's
 * documented delivery shape, but its value is untrusted and unused.
 */
final class GitHubAdapter extends AbstractWebhookAdapter
{
    protected function signatureHeader(): string
    {
        return 'X-Hub-Signature-256';
    }

    public function parse(Request $request, string $body): EventDto
    {
        $headerDeliveryId = $request->header('X-GitHub-Delivery');
        $eventName = $request->header('X-GitHub-Event');

        if (! is_string($headerDeliveryId) || $headerDeliveryId === '') {
            throw new InvalidEnvelopeException('missing_header:X-GitHub-Delivery');
        }
        // Dedup key from the HMAC-verified body, never the unsigned header (DL-176).
        $deliveryId = hash('sha256', $body);
        if (! is_string($eventName) || $eventName === '') {
            throw new InvalidEnvelopeException('missing_header:X-GitHub-Event');
        }

        $decoded = $this->decodeJson($body);

        // Composite event_type: "<header_event>.<body_action>" when both present.
        $action = $this->optionalScalar($decoded, 'action');
        $eventType = $action !== null ? "{$eventName}.{$action}" : $eventName;

        // A ping body carries no repository; isPing() short-circuits the
        // downstream scope check, so a synthetic empty scope_id is fine.
        $scopeId = $this->nestedScalar($decoded, 'repository', 'full_name') ?? '';
        $actorId = $this->nestedScalar($decoded, 'sender', 'id');

        $event = new EventDto($deliveryId, $scopeId, $eventType, $actorId);
        $this->assertFieldLengths($event);

        return $event;
    }

    public function isPing(EventDto $event): bool
    {
        return $event->eventType === 'ping';
    }

    /**
     * Read a scalar from a nested object (`$decoded[$outer][$inner]`),
     * returning null if any level is absent or not the expected shape.
     *
     * @param  array<mixed>  $decoded
     */
    private function nestedScalar(array $decoded, string $outer, string $inner): ?string
    {
        $object = $decoded[$outer] ?? null;
        if (! is_array($object)) {
            return null;
        }

        $value = $object[$inner] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
