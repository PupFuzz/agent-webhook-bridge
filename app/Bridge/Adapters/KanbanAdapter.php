<?php

namespace App\Bridge\Adapters;

use Illuminate\Http\Request;

/**
 * kanban-board webhook adapter (kanban-board v0.9.1+ wire shape).
 *
 *   X-Kanban-Signature: sha256=<HMAC-SHA256 of body bytes, hex>
 *   Body: { "event", "board_id", "delivery_id", "user_id"?, "payload", ... }
 *
 * board_id → scope_id, user_id → actor_id (null for system events), the
 * `event` field is the event_type. kanban-board emits no ping event.
 */
final class KanbanAdapter extends AbstractWebhookAdapter
{
    protected function signatureHeader(): string
    {
        return 'X-Kanban-Signature';
    }

    public function parse(Request $request, string $body): EventDto
    {
        $decoded = $this->decodeJson($body);

        $event = new EventDto(
            deliveryId: $this->requireScalar($decoded, 'delivery_id'),
            scopeId: $this->requireScalar($decoded, 'board_id'),
            eventType: $this->requireScalar($decoded, 'event'),
            actorId: $this->optionalScalar($decoded, 'user_id'),
        );
        $this->assertFieldLengths($event);

        return $event;
    }

    public function isPing(EventDto $event): bool
    {
        return false;
    }
}
