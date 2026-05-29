<?php

namespace App\Bridge\Dispatch;

/**
 * A signal that should reach the agent's conversation context (the inbox),
 * as opposed to a ReactionTarget (an automated dispatch). A classifier may
 * emit either or both per event.
 *
 * No freeze/thaw machinery (obsolete under PHP): payload is a plain
 * array. toArray() is the canonical JSONL/channel-wire shape (the old
 * intent_to_dict): actor is flattened to its three public fields.
 */
final class Intent
{
    /**
     * @param  array<mixed>  $payload
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $subjectId,
        public readonly string $provider,
        public readonly Actor $actor,
        public readonly string $summary,
        public readonly array $payload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'subject_id' => $this->subjectId,
            'provider' => $this->provider,
            'actor' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'is_known_agent' => $this->actor->isKnownAgent,
            ],
            'summary' => $this->summary,
            'payload' => $this->payload,
        ];
    }
}
