<?php

namespace App\Bridge\IdleNudge;

/**
 * One parsed seat offer record, schema v1 (rt#562; `wake-decide.py`'s `build_offer()` in the
 * coord framework owns the field meanings). Only the members the nudge acts on are kept:
 * `waivers` and `reason` are informational on the wire and nothing here reads them.
 */
final class SeatOffer
{
    /**
     * @param  list<array{lane: string, detail: string}>|null  $lanes  null = the census could not
     *                                                                 measure; [] = nothing to offer
     */
    public function __construct(
        public readonly ?string $sessionId,
        /** `turn_ended_at`, epoch milliseconds, on the SEAT's clock. */
        public readonly int $turnEndedAtMs,
        public readonly int $horizonS,
        public readonly int $cooldownS,
        public readonly ?array $lanes,
        /** Non-null exactly when `lanes` is a non-empty list — the reader refuses anything else. */
        public readonly ?string $prompt,
    ) {}
}
