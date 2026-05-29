<?php

namespace App\Bridge\Adapters;

/**
 * The provider-agnostic envelope an adapter extracts from a verified webhook.
 *
 * Carries only the bridge's required routing/dedup/attribution fields; the
 * raw parsed body is persisted separately (the controller has the request
 * body). scopeId/eventType are always present; actorId is null for
 * system-emitted events.
 */
final class EventDto
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $scopeId,
        public readonly string $eventType,
        public readonly ?string $actorId,
    ) {}
}
