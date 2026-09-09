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
     * The FIXED part of the slack, on top of one whole extra interval. Cron fires on a wall
     * clock the bridge does not share, a 10-minute line legitimately lands 10 minutes and
     * change apart, and an alarm that fires on ordinary jitter is an alarm that gets muted. A
     * MISSED tick still shows.
     *
     * ⚠ It is a JUDGEMENT, not a measurement, which is why {@see self::summary()} prints the
     * slack it bought straight out of {@see self::graceS()} rather than leaving a reader of
     * `stale` to open this file — an unexplained constant stops being examined the moment the
     * person who chose it stops reading the thread (rt#341, sola-pm).
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
            $ageS > $expectedEveryS + self::graceS($expectedEveryS) => TickState::Stale,
            default => TickState::Fresh,
        };

        return new self($adopted, $adopted ? $expectedEveryS : null, $lastAt, $ageS, $state);
    }

    /**
     * The slack this verdict allows ON TOP OF the declared horizon before a tick reads as
     * stale: one whole extra interval plus {@see self::GRACE_S}.
     *
     * ⭐ ONE DERIVATION, TWO CONSUMERS — the threshold in {@see self::resolve()} and the
     * sentence in {@see self::summary()}. A `stale` line that hand-typed the slack would be a
     * figure a program derives, restated as prose (`CLAUDE_CONVENTIONS.md` § *Derived
     * figures*): the constant moves, the message keeps printing the old number, and the
     * reader it was added for is misled with more confidence than before it existed.
     */
    public static function graceS(int $expectedEveryS): int
    {
        return $expectedEveryS + self::GRACE_S;
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
            TickState::Stale => $this->staleSummary(),
        };
    }

    /**
     * ⛔ AND THE REMEDIATION NAMES A CHANNEL THAT HAS SOMETHING IN IT. It used to end *"check the
     * crontab line and its account's mail"* — but the line this repo now offers ends `2>&1` into a
     * log file ({@see TickAdoptionNotice::crontabLine()}), so cron mails NOTHING, ever. A
     * remediation step that sends the reader to a guaranteed-empty inbox spends the one action a
     * `stale` verdict buys.
     *
     * ⭐ THE GRACE IS PRINTED WHERE THE VERDICT IS READ (rt#341, sola-pm). A reader who sees
     * `stale` should not have to open this file to learn what was assumed on their behalf —
     * an assumption nobody can see is one nobody re-examines, and the slack is a judgement
     * rather than a measurement. Both figures come out of {@see self::graceS()}, the same
     * derivation {@see self::resolve()} compares the age against, so the sentence cannot
     * describe a threshold the code no longer applies.
     */
    private function staleSummary(): string
    {
        $expected = (int) $this->expectedEveryS;
        $grace = self::graceS($expected);

        return 'tick: STALE — last seen '.$this->ageS.'s ago against a declared '.$expected
            .'s interval, which this verdict allows '.$grace.'s of jitter grace on top of (one extra interval + '
            .self::GRACE_S.'s), so a tick older than '.($expected + $grace)
            .'s reads as stale. Every periodic job on this install has stopped running on the clock; '
            .'read `php artisan bridge:jobs` for what the registry last managed to do, then the crontab line itself '
            .'and whatever its redirect writes (the offered line truncates storage/logs/tick.log on every run).';
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
