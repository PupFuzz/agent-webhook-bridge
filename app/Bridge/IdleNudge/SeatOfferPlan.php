<?php

namespace App\Bridge\IdleNudge;

/**
 * One notice the evaluator decided a seat-record agent is owed: the facts the pushed intent
 * carries, and the slot the dedupe record takes before the push.
 */
final class SeatOfferPlan
{
    /**
     * @param  list<array{lane: string, detail: string}>  $lanes  capped at {@see IdleNudgeEvaluator::PENDING_CAP}
     */
    public function __construct(
        public readonly string $agent,
        public readonly ?string $sessionId,
        public readonly int $turnEndedAtMs,
        /** The bridge's own clock when the pass decided — the slot's `nudged_at`. */
        public readonly int $decidedAtMs,
        public readonly int $idleAgeS,
        public readonly int $horizonS,
        public readonly int $cooldownS,
        public readonly int $lanesTotal,
        public readonly array $lanes,
        public readonly string $prompt,
    ) {}
}
