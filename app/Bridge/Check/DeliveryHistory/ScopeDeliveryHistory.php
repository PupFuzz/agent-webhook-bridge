<?php

namespace App\Bridge\Check\DeliveryHistory;

use InvalidArgumentException;

/**
 * One declared github scope's delivery record, reduced to what its silence threshold is derived from (DL-382).
 *
 * ⭐ THE DERIVATION, and why each part is there:
 *
 *     threshold = max(FLOOR_SECONDS, GAP_FACTOR × the SECOND-longest gap between consecutive deliveries)
 *
 *  - **A gap, not a rate.** The question is "how long does this scope ordinarily go without a delivery", and that is
 *    what a gap measures directly. A per-day rate cannot tell a scope that delivers once a week from one that went
 *    deaf for a week.
 *  - **An order statistic, not a count-weighted percentile.** Traffic is bursty: a scope that receives thirty
 *    deliveries every Tuesday and nothing else has twenty-nine short gaps per long one, so a 95th percentile of its
 *    gaps is minutes and would call every Wednesday deaf. The longest gaps ARE the routine silences.
 *  - **The SECOND-longest, not the longest.** A scope that recovered from an outage has that outage in its record as
 *    its longest gap. Taking the maximum would teach the leg that deafness is routine and hold it quiet through the
 *    next outage for GAP_FACTOR times as long. Excluding the single longest means one outage cannot set the threshold; it is
 *    bounded by that, and two outages in one retained record can.
 *  - **`GAP_FACTOR`** is the margin over the routine silence: a silence must exceed the scope's second-longest
 *    recorded one by that factor before the leg speaks. It is a judgement, stated as one.
 *  - **`FLOOR_SECONDS`** — no threshold is ever shorter than it, because the ordinary cadence of a repo is human and
 *    weekly: a Friday-evening-to-Monday-morning silence is routine for ANY repo whatever its record says, and the
 *    floor is a weekend plus a day, the day for the day-bucket boundary effects the incident fixture carries.
 *  - **`MIN_SPAN_SECONDS` and `MIN_GAPS`** decide whether a threshold can be derived at all: a record must span one
 *    weekly cycle, so the scope's ordinary quiet has had the chance to appear in it, and must hold a second-longest
 *    gap to take. Short of either, the record states a named "cannot derive" state rather than a threshold.
 *
 * ⚠ IT SEES WHAT THE RECORD SEES. The record is whatever the caller hands it — for `bridge:check` the retained
 * `webhook_events` rows for one scope spelling — so retention bounds how far back a gap can be, and a silence older
 * than the retention window reads as a scope with no delivery at all.
 */
final class ScopeDeliveryHistory
{
    public const FLOOR_SECONDS = 3 * 86400;

    public const GAP_FACTOR = 2;

    public const MIN_SPAN_SECONDS = 7 * 86400;

    public const MIN_GAPS = 2;

    private function __construct(
        public readonly int $deliveries,
        public readonly ?int $firstAt,
        public readonly ?int $lastAt,
        public readonly ?int $longestGap,
        public readonly ?int $secondLongestGap,
    ) {}

    /**
     * Fold a stream of delivery instants (unix seconds, ascending) into the record, holding O(1) of it.
     *
     * ⛔ OUT-OF-ORDER INPUT IS REFUSED, never sorted or clamped: a descending pair is a caller that did not order its
     * read, and a negative gap folded in silently would shrink the threshold.
     *
     * @param  iterable<int>  $ascending
     */
    public static function fromAscendingTimestamps(iterable $ascending): self
    {
        $count = 0;
        $first = null;
        $previous = null;
        $longest = null;
        $second = null;

        foreach ($ascending as $at) {
            if ($previous !== null) {
                if ($at < $previous) {
                    throw new InvalidArgumentException("delivery instants must be ascending: {$at} follows {$previous}");
                }
                $gap = $at - $previous;
                if ($longest === null || $gap > $longest) {
                    $second = $longest;
                    $longest = $gap;
                } elseif ($second === null || $gap > $second) {
                    $second = $gap;
                }
            }
            $first ??= $at;
            $previous = $at;
            $count++;
        }

        return new self($count, $first, $previous, $longest, $second);
    }

    /** Seconds between the first and last recorded delivery; 0 for a record of fewer than two. */
    public function span(): int
    {
        return $this->firstAt === null || $this->lastAt === null ? 0 : $this->lastAt - $this->firstAt;
    }

    public function gaps(): int
    {
        return max(0, $this->deliveries - 1);
    }

    public function isDerivable(): bool
    {
        return $this->gaps() >= self::MIN_GAPS && $this->span() >= self::MIN_SPAN_SECONDS;
    }

    /** The derived silence threshold in seconds, or null where {@see self::isDerivable()} is false. */
    public function derivedThreshold(): ?int
    {
        if (! $this->isDerivable() || $this->secondLongestGap === null) {
            return null;
        }

        return max(self::FLOOR_SECONDS, self::GAP_FACTOR * $this->secondLongestGap);
    }

    /**
     * Seconds since the last recorded delivery at `$now`, or null for an empty record.
     *
     * Floored at zero: `$now` and the record come off one database clock, but a row stamped inside the same second
     * as the read can still compare a fraction ahead of it.
     */
    public function silenceAt(int $now): ?int
    {
        return $this->lastAt === null ? null : max(0, $now - $this->lastAt);
    }

    /** A silence EQUAL to the threshold is within it: the leg speaks only once the silence exceeds it. */
    public function stateAt(int $now): DeliveryHistoryState
    {
        $silence = $this->silenceAt($now);
        if ($silence === null) {
            return DeliveryHistoryState::NeverDelivered;
        }

        $threshold = $this->derivedThreshold();
        if ($threshold === null) {
            return $silence > self::FLOOR_SECONDS
                ? DeliveryHistoryState::UnderivedPastFloor
                : DeliveryHistoryState::UnderivedWithinFloor;
        }

        return $silence > $threshold ? DeliveryHistoryState::PastThreshold : DeliveryHistoryState::WithinThreshold;
    }
}
