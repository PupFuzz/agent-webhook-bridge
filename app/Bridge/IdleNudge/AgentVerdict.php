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
    ];

    public function __construct(
        public readonly string $agent,
        public readonly string $code,
        public readonly ?NudgePlan $plan = null,
    ) {}

    public function measured(): bool
    {
        return ! in_array($this->code, self::UNMEASURED, true);
    }
}
