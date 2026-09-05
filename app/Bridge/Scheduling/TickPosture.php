<?php

namespace App\Bridge\Scheduling;

use Illuminate\Support\Carbon;

/**
 * The install's tick liveness, resolved (card#8425 / DL-325) — what `bridge:jobs --json`
 * publishes for a session-start hook to assert on, and what `bridge:check` reads.
 *
 * ⭐ IT PUBLISHES THE INPUTS ALONGSIDE THE VERDICT. `state` alone would make the consumer
 * trust a judgement it cannot check; with `expected_every_s`, `last_at` and `age_s` beside
 * it, a hook can apply its own rule and a human can see why the verdict is what it is.
 */
final class TickPosture
{
    /**
     * Slack added to the declared horizon before a tick reads as stale. One whole extra
     * interval plus a minute: cron fires on a wall clock the bridge does not share, a
     * 10-minute line legitimately lands 10 minutes and change apart, and an alarm that
     * fires on ordinary jitter is an alarm that gets muted. A MISSED tick still shows.
     */
    private const GRACE_S = 60;

    private function __construct(
        /** Whether this install DECLARED that it runs the tick (the adoption knob). */
        public readonly bool $adopted,
        /** The declared horizon in seconds, or null when nothing was declared. */
        public readonly ?int $expectedEveryS,
        public readonly ?Carbon $lastAt,
        public readonly ?int $ageS,
        public readonly TickState $state,
    ) {}

    public static function resolve(?Carbon $lastAt, ?int $expectedEveryS): self
    {
        $adopted = $expectedEveryS !== null && $expectedEveryS > 0;
        $ageS = $lastAt === null ? null : max(0, now()->getTimestamp() - $lastAt->getTimestamp());

        $state = match (true) {
            // Absence is UNMEASURED in both directions — including when a horizon WAS
            // declared. A declared install that has never ticked is a real problem, but it
            // is the problem "this was never observed", not "the tick died": the check that
            // reports it says so in those words rather than announcing a death it cannot
            // establish.
            $lastAt === null => TickState::Unmeasured,
            ! $adopted => TickState::Undeclared,
            $ageS > ($expectedEveryS * 2) + self::GRACE_S => TickState::Stale,
            default => TickState::Fresh,
        };

        return new self($adopted, $adopted ? $expectedEveryS : null, $lastAt, $ageS, $state);
    }

    /**
     * Whether an asserting caller (a session-start hook, `bridge:jobs --assert-tick`) should
     * treat this as a failure.
     *
     * ⛔ ONLY A DECLARED HORIZON CAN FAIL. An install that never adopted the tick is not
     * failing by not ticking, and an unmeasured tick on an undeclared install is the normal
     * state of every no-cron install in the fleet. That is the whole reason this subsystem
     * cannot be adopted by accident.
     */
    public function failsAssertion(): bool
    {
        return $this->adopted && $this->state !== TickState::Fresh;
    }

    /** One line, operator vocabulary. */
    public function summary(): string
    {
        return match ($this->state) {
            TickState::Unmeasured => $this->adopted
                ? 'tick: NEVER OBSERVED — this install declares a tick every '.$this->expectedEveryS
                    .'s and the bridge has no record of one. UNMEASURED, not dead: either the crontab line was never added, or nothing it runs has reached this install\'s cache store yet.'
                : 'tick: not adopted, and none recorded — the registry runs from the after-response event gate only.',
            TickState::Undeclared => 'tick: last seen '.$this->ageS.'s ago, but this install declares no expected interval (BRIDGE_JOBS_TICK_EXPECTED_EVERY), so no freshness verdict is claimed.',
            TickState::Fresh => 'tick: fresh — last seen '.$this->ageS.'s ago, expected every '.$this->expectedEveryS.'s.',
            TickState::Stale => 'tick: STALE — last seen '.$this->ageS.'s ago against a declared '.$this->expectedEveryS
                .'s interval. Every periodic job on this install has stopped running on the clock; check the crontab line and its account\'s mail.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'adopted' => $this->adopted,
            'expected_every_s' => $this->expectedEveryS,
            'last_at' => $this->lastAt?->toIso8601String(),
            'age_s' => $this->ageS,
            'state' => $this->state->value,
        ];
    }
}
