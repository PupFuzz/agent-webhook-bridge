<?php

namespace App\Bridge\IdleNudge;

/**
 * What one pass concluded about one DECLARED agent. Exactly one per agent, every pass.
 *
 * ⛔ `code` IS A FIXED VOCABULARY, and the only per-agent text the pass record, the log and
 * `bridge:check` ever print. No snapshot value is carried here.
 */
final class AgentVerdict
{
    /** The verdicts that are NOT measurements. Everything else is one. */
    public const UNMEASURED = [
        'not_push_routed',
        'duplicate_declaration',
        'no_declaring_seat',
        'malformed_seat_identity',
        'unrecognised_state',
        'served_retired',
        'fold_lag_unreadable',
        'fold_lag',
        'no_idle_since',
        'malformed_horizon',
        'inbox_unreadable',
        'push_time_unreadable',
        // A Mezzanine-sourced agent on a pass whose fleet read did not measure.
        'fleet_unmeasured',
        // A seat-record agent (rt#562 consumer contract rule 5, and `lanes: null`).
        'seat_record_absent',
        'seat_record_not_visible',
        'seat_record_unreadable',
        'seat_record_malformed',
        'seat_record_unknown_version',
        'offer_unmeasured',
    ];

    /**
     * The seat-record verdicts `idle_nudge.posture` names per agent: the record the operator
     * declared is not one the pass could act on, or has not moved since its notice.
     */
    public const SEAT_RECORD_FAULTS = [
        'seat_record_absent',
        'seat_record_not_visible',
        'seat_record_unreadable',
        'seat_record_malformed',
        'seat_record_unknown_version',
        'offer_stale',
    ];

    public function __construct(
        public readonly string $agent,
        public readonly string $code,
        public readonly NudgePlan|SeatOfferPlan|null $plan = null,
    ) {}

    public function measured(): bool
    {
        return ! in_array($this->code, self::UNMEASURED, true);
    }
}
