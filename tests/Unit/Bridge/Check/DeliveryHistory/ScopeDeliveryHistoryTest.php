<?php

namespace Tests\Unit\Bridge\Check\DeliveryHistory;

use App\Bridge\Check\DeliveryHistory\DeliveryHistoryState;
use App\Bridge\Check\DeliveryHistory\ScopeDeliveryHistory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The per-scope threshold `github.delivery_history` derives from a scope's own delivery gaps (DL-382).
 *
 * ⭐ EVERY STATE IS ASSERTED WITH A CONTROL ON THE OTHER SIDE OF ITS EDGE, because the derivation is a comparison
 * and a comparison tested from one side passes on an implementation that always answers that side.
 */
class ScopeDeliveryHistoryTest extends TestCase
{
    private const T0 = 1_786_000_000;

    private const HOUR = 3600;

    private const DAY = 86400;

    public function test_a_scope_with_no_recorded_delivery_is_never_delivered_and_derives_nothing(): void
    {
        $history = ScopeDeliveryHistory::fromAscendingTimestamps([]);

        $this->assertSame(0, $history->deliveries);
        $this->assertFalse($history->isDerivable());
        $this->assertNull($history->derivedThreshold());
        $this->assertSame(DeliveryHistoryState::NeverDelivered, $history->stateAt(self::T0));
        $this->assertTrue(DeliveryHistoryState::NeverDelivered->isLoud());
    }

    public function test_a_busy_scope_heard_from_recently_is_within_its_threshold_and_the_floor_sets_it(): void
    {
        $history = ScopeDeliveryHistory::fromAscendingTimestamps($this->every(6 * self::HOUR, 15 * self::DAY));

        $this->assertTrue($history->isDerivable());
        $this->assertSame(6 * self::HOUR, $history->secondLongestGap);
        // 2 x 6h is under the floor, so the floor is the threshold.
        $this->assertSame(ScopeDeliveryHistory::FLOOR_SECONDS, $history->derivedThreshold());
        $this->assertSame(DeliveryHistoryState::WithinThreshold, $history->stateAt($history->lastAt + self::HOUR));
        $this->assertFalse(DeliveryHistoryState::WithinThreshold->isLoud());
    }

    public function test_the_same_scope_past_its_threshold_is_loud_and_the_edge_itself_is_not(): void
    {
        $history = ScopeDeliveryHistory::fromAscendingTimestamps($this->every(6 * self::HOUR, 15 * self::DAY));
        $threshold = (int) $history->derivedThreshold();

        $this->assertSame(DeliveryHistoryState::WithinThreshold, $history->stateAt($history->lastAt + $threshold));
        $this->assertSame(DeliveryHistoryState::PastThreshold, $history->stateAt($history->lastAt + $threshold + 1));
        $this->assertTrue(DeliveryHistoryState::PastThreshold->isLoud());
    }

    public function test_a_sparse_scope_derives_a_threshold_above_the_floor_from_its_own_gaps(): void
    {
        // ⭐ THE CONTROL THAT THE DERIVATION IS PER SCOPE AT ALL. A scope that delivers every three days would be
        // called deaf by the floor alone on every quiet stretch it routinely has.
        $history = ScopeDeliveryHistory::fromAscendingTimestamps($this->every(3 * self::DAY, 21 * self::DAY));

        $this->assertSame(ScopeDeliveryHistory::GAP_FACTOR * 3 * self::DAY, $history->derivedThreshold());
        $this->assertGreaterThan(ScopeDeliveryHistory::FLOOR_SECONDS, $history->derivedThreshold());
        $this->assertSame(DeliveryHistoryState::WithinThreshold, $history->stateAt($history->lastAt + 4 * self::DAY));
        $this->assertSame(DeliveryHistoryState::PastThreshold, $history->stateAt($history->lastAt + 6 * self::DAY + 1));
    }

    public function test_one_past_outage_in_the_record_does_not_set_the_threshold(): void
    {
        // The single longest gap is excluded, so a scope that recovered from a 20-day outage is not thereafter
        // allowed 40 days of silence before this leg speaks.
        $before = $this->every(6 * self::HOUR, 10 * self::DAY);
        $resumed = end($before) + 20 * self::DAY;
        $after = $this->every(6 * self::HOUR, 10 * self::DAY, $resumed);
        $history = ScopeDeliveryHistory::fromAscendingTimestamps([...$before, ...$after]);

        $this->assertSame(20 * self::DAY, $history->longestGap);
        $this->assertSame(6 * self::HOUR, $history->secondLongestGap);
        $this->assertSame(ScopeDeliveryHistory::FLOOR_SECONDS, $history->derivedThreshold());
    }

    public function test_a_record_shorter_than_the_minimum_span_cannot_derive_and_is_not_healthy(): void
    {
        $history = ScopeDeliveryHistory::fromAscendingTimestamps($this->every(6 * self::HOUR, 2 * self::DAY));

        $this->assertFalse($history->isDerivable());
        $this->assertNull($history->derivedThreshold());
        $this->assertSame(DeliveryHistoryState::UnderivedWithinFloor, $history->stateAt($history->lastAt + self::HOUR));
        $this->assertNotSame(DeliveryHistoryState::WithinThreshold, $history->stateAt($history->lastAt + self::HOUR));
        // Not loud — but not a healthy verdict either; the check renders it `unvalidated`.
        $this->assertFalse(DeliveryHistoryState::UnderivedWithinFloor->isLoud());
    }

    public function test_a_record_that_cannot_derive_is_still_loud_past_the_floor(): void
    {
        $history = ScopeDeliveryHistory::fromAscendingTimestamps($this->every(6 * self::HOUR, 2 * self::DAY));

        $this->assertSame(DeliveryHistoryState::UnderivedWithinFloor, $history->stateAt($history->lastAt + ScopeDeliveryHistory::FLOOR_SECONDS));
        $this->assertSame(DeliveryHistoryState::UnderivedPastFloor, $history->stateAt($history->lastAt + ScopeDeliveryHistory::FLOOR_SECONDS + 1));
        $this->assertTrue(DeliveryHistoryState::UnderivedPastFloor->isLoud());
    }

    public function test_a_long_record_with_fewer_gaps_than_the_minimum_cannot_derive(): void
    {
        // Spanning the minimum is not enough on its own: with one gap there is no SECOND-longest to take.
        $history = ScopeDeliveryHistory::fromAscendingTimestamps([self::T0, self::T0 + 15 * self::DAY]);

        $this->assertGreaterThanOrEqual(ScopeDeliveryHistory::MIN_SPAN_SECONDS, $history->span());
        $this->assertFalse($history->isDerivable());

        $witness = ScopeDeliveryHistory::fromAscendingTimestamps([self::T0, self::T0 + 7 * self::DAY, self::T0 + 15 * self::DAY]);
        $this->assertTrue($witness->isDerivable(), 'the control: one more delivery makes the same span derivable');
    }

    public function test_a_weekly_bursty_scope_needs_two_between_burst_gaps_before_it_derives_a_threshold(): void
    {
        // ⭐ R1 finding 1. With only two bursts, the single between-burst gap IS the longest gap and gets
        // excluded — leaving nothing but the 2-minute intra-burst gaps to derive a threshold from, which reads a
        // routine ~7-day silence as PAST_THRESHOLD after 3 days. The fix requires a record spanning two weekly
        // cycles, so a THIRD burst is needed before there are two between-burst gaps to take a second-longest from.
        $burst1 = $this->burst(self::T0, 30, 2 * 60);
        $burst2 = $this->burst(self::T0 + 7 * self::DAY, 30, 2 * 60);
        $burst3 = $this->burst(self::T0 + 14 * self::DAY, 30, 2 * 60);

        $twoBursts = ScopeDeliveryHistory::fromAscendingTimestamps([...$burst1, ...$burst2]);
        $this->assertFalse(
            $twoBursts->isDerivable(),
            'two bursts hold only ONE between-burst gap, excluded as the longest — there is no second-longest routine gap to take yet',
        );
        // The routine ~7-day silence must never be read PAST a derived threshold this record has no basis for.
        $this->assertNotSame(DeliveryHistoryState::PastThreshold, $twoBursts->stateAt(end($burst2) + 4 * self::DAY));

        $threeBursts = ScopeDeliveryHistory::fromAscendingTimestamps([...$burst1, ...$burst2, ...$burst3]);
        $this->assertTrue($threeBursts->isDerivable(), 'a third burst supplies the second between-burst gap');
        $betweenBurstGap = 7 * self::DAY - 29 * (2 * 60);
        $this->assertSame($betweenBurstGap, $threeBursts->secondLongestGap);
        $this->assertSame(ScopeDeliveryHistory::GAP_FACTOR * $betweenBurstGap, $threeBursts->derivedThreshold());
        $this->assertGreaterThan(ScopeDeliveryHistory::FLOOR_SECONDS, $threeBursts->derivedThreshold());
    }

    public function test_timestamps_out_of_order_are_refused_rather_than_read_as_negative_gaps(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScopeDeliveryHistory::fromAscendingTimestamps([self::T0 + 10, self::T0]);
    }

    /**
     * One delivery every `$step` seconds for `$span` seconds, inclusive of both ends.
     *
     * @return list<int>
     */
    private function every(int $step, int $span, int $from = self::T0): array
    {
        return range($from, $from + $span, $step);
    }

    /**
     * `$count` deliveries `$step` seconds apart, starting at `$from`.
     *
     * @return list<int>
     */
    private function burst(int $from, int $count, int $step): array
    {
        return array_map(static fn (int $i): int => $from + $i * $step, range(0, $count - 1));
    }
}
