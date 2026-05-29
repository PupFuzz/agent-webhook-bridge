<?php

namespace App\Bridge\Dispatch;

/**
 * One classifier invocation's complete output. Both may be empty (the event
 * was noise / an echo / an unhandled type). intents are ordered (the inbox
 * surface preserves event order); targets are deduped by debounceKey at
 * dispatch time.
 */
final class ClassifyResult
{
    /**
     * @param  list<ReactionTarget>  $targets
     * @param  list<Intent>  $intents
     */
    public function __construct(
        public readonly array $targets = [],
        public readonly array $intents = [],
    ) {}
}
