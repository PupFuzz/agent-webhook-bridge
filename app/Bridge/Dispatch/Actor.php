<?php

namespace App\Bridge\Dispatch;

/**
 * Who authored an event, normalized. Only `id` is guaranteed non-null for
 * kanban events (the actor_id column); `name`/`isKnownAgent` are best-effort
 * enrichments from the agent registry. `rawEnvelope` carries the full parsed
 * body for custom echo-suppression predicates.
 */
final class Actor
{
    /**
     * @param  array<mixed>  $rawEnvelope
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name = null,
        public readonly bool $isKnownAgent = false,
        public readonly array $rawEnvelope = [],
    ) {}
}
