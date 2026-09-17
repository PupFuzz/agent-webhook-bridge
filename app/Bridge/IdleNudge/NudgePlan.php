<?php

namespace App\Bridge\IdleNudge;

/**
 * One nudge the evaluator decided is owed: the facts the pushed intent carries.
 */
final class NudgePlan
{
    /**
     * @param  list<array{id: string, kind: mixed, subject_id: mixed, summary: mixed, ts: float}>  $pending  oldest first, capped
     */
    public function __construct(
        public readonly string $agent,
        public readonly string $installId,
        public readonly string $seatId,
        public readonly int $idleSinceMs,
        public readonly int $serverTimeMs,
        public readonly int $idleAgeS,
        public readonly int $horizonS,
        /** True when the seat declared no horizon and the install default was used. */
        public readonly bool $suspect,
        public readonly int $pendingTotal,
        public readonly array $pending,
    ) {}
}
