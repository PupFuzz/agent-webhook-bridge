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
 */
final class GitHubAdapter extends AbstractWebhookAdapter
{
    protected function signatureHeader(): string
    {
        return 'X-Hub-Signature-256';
    }

    public function parse(Request $request, string $body): EventDto
    {
        $deliveryId = $request->header('X-GitHub-Delivery');
        $eventName = $request->header('X-GitHub-Event');

        if (! is_string($deliveryId) || $deliveryId === '') {
            throw new InvalidEnvelopeException('missing_header:X-GitHub-Delivery');
        }
        if (! is_string($eventName) || $eventName === '') {
            throw new InvalidEnvelopeException('missing_header:X-GitHub-Event');
        }
        $this->assertDeliveryIdLength($deliveryId);

        $decoded = $this->decodeJson($body);

        // Composite event_type: "<header_event>.<body_action>" when both present.
        $action = $this->optionalScalar($decoded, 'action');
        $eventType = $action !== null ? "{$eventName}.{$action}" : $eventName;

        // A ping body carries no repository; isPing() short-circuits the
        // downstream scope check, so a synthetic empty scope_id is fine.
        $scopeId = $this->nestedScalar($decoded, 'repository', 'full_name') ?? '';
        $actorId = $this->nestedScalar($decoded, 'sender', 'id');

        return new EventDto($deliveryId, $scopeId, $eventType, $actorId);
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
