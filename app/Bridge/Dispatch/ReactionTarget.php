<?php

namespace App\Bridge\Dispatch;

/**
 * One automated reaction the dispatcher should run via a registered handler.
 *
 *  - handler         registered handler name (channel_push, log_intent, ...)
 *  - targetId        opaque to the bridge; meaningful to the handler
 *  - debounceKey     coalescing bucket; defaults to targetId
 *  - debounceSeconds null = the consumer's default
 *  - payload         handler-scoped args (plain array; no freeze/thaw)
 *
 * Same-pass dedup is by debounceKey (last-wins associative-array key) at
 * dispatch time — no frozen/hashable requirement (obsolete in PHP).
 */
final class ReactionTarget
{
    /**
     * @param  array<mixed>  $payload
     */
    public function __construct(
        public readonly string $handler,
        public readonly string $targetId,
        public readonly string $debounceKey,
        public readonly ?int $debounceSeconds = null,
        public readonly array $payload = [],
    ) {}

    /**
     * Friendly constructor: defaults debounceKey to targetId.
     *
     * @param  array<mixed>  $payload
     */
    public static function make(
        string $handler,
        string $targetId,
        ?string $debounceKey = null,
        ?int $debounceSeconds = null,
        array $payload = [],
    ): self {
        return new self(
            handler: $handler,
            targetId: $targetId,
            debounceKey: $debounceKey ?? $targetId,
            debounceSeconds: $debounceSeconds,
            payload: $payload,
        );
    }
}
