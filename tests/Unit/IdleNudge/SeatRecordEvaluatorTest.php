<?php

namespace Tests\Unit\IdleNudge;

use App\Bridge\IdleNudge\AgentVerdict;
use App\Bridge\IdleNudge\IdleNudgeEvaluator;
use App\Bridge\IdleNudge\SeatOffer;
use App\Bridge\IdleNudge\SeatOfferPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The seat-record branch of the idle nudge (rt#562 consumer contract), one fixture per verdict,
 * plus the Mezzanine branch's answer when no snapshot was read.
 */
class SeatRecordEvaluatorTest extends TestCase
{
    private const TURN_MS = 1_789_387_200_125;

    /** One hour after the turn ended: past the 1800 s horizon. */
    private const NOW_MS = self::TURN_MS + 3_600_000;

    /** @param  list<array{lane: string, detail: string}>|null  $lanes */
    private function offer(?array $lanes = [['lane' => 'impl', 'detail' => 'card#42']], ?string $prompt = 'Continue working.', ?string $session = 'sess-1', int $horizonS = 1800, int $cooldownS = 3600): SeatOffer
    {
        return new SeatOffer($session, self::TURN_MS, $horizonS, $cooldownS, $lanes, $prompt);
    }

    /** @param  array{idle_since: int, nudged_at: ?int, session_id?: ?string}|null  $slot */
    private function judge(SeatOffer|string $offer, ?array $slot = null, int $nowMs = self::NOW_MS): AgentVerdict
    {
        return (new IdleNudgeEvaluator)->seatRecord('pm', $offer, $slot, $nowMs);
    }

    public function test_an_offer_past_its_horizon_is_nudged_with_the_plan_the_intent_carries(): void
    {
        $v = $this->judge($this->offer());

        $this->assertSame('nudge', $v->code);
        $this->assertInstanceOf(SeatOfferPlan::class, $v->plan);
        $this->assertSame('pm', $v->plan->agent);
        $this->assertSame('sess-1', $v->plan->sessionId);
        $this->assertSame(self::TURN_MS, $v->plan->turnEndedAtMs);
        $this->assertSame(self::NOW_MS, $v->plan->decidedAtMs);
        $this->assertSame(3600, $v->plan->idleAgeS);
        $this->assertSame(1800, $v->plan->horizonS);
        $this->assertSame(3600, $v->plan->cooldownS);
        $this->assertSame('Continue working.', $v->plan->prompt);
        $this->assertTrue($v->measured());
    }

    public function test_the_lanes_carried_are_capped_and_the_total_is_the_denominator(): void
    {
        $lanes = array_map(fn (int $i): array => ['lane' => "l{$i}", 'detail' => 'pullable work'], range(1, IdleNudgeEvaluator::PENDING_CAP + 3));

        $plan = $this->judge($this->offer($lanes))->plan;

        $this->assertInstanceOf(SeatOfferPlan::class, $plan);
        $this->assertSame(IdleNudgeEvaluator::PENDING_CAP + 3, $plan->lanesTotal);
        $this->assertSame(array_slice($lanes, 0, IdleNudgeEvaluator::PENDING_CAP), $plan->lanes);
    }

    public function test_a_read_that_reached_no_offer_passes_its_own_verdict_through_as_unmeasured(): void
    {
        foreach (['seat_record_absent', 'seat_record_not_visible', 'seat_record_unreadable', 'seat_record_malformed', 'seat_record_unknown_version'] as $code) {
            $v = $this->judge($code);
            $this->assertSame($code, $v->code);
            $this->assertNull($v->plan);
            $this->assertFalse($v->measured(), $code);
        }
    }

    public function test_an_unmeasured_census_is_unmeasured_never_idle(): void
    {
        $v = $this->judge($this->offer(null, null));

        $this->assertSame('offer_unmeasured', $v->code);
        $this->assertFalse($v->measured());
    }

    public function test_nothing_offered_sends_nothing_and_is_a_measurement(): void
    {
        $v = $this->judge($this->offer([], null));

        $this->assertSame('nothing_pending', $v->code);
        $this->assertTrue($v->measured());
    }

    public function test_an_offer_inside_its_horizon_waits(): void
    {
        $this->assertSame('idle_within_horizon', $this->judge($this->offer(), nowMs: self::TURN_MS + 1_799_999)->code);
        $this->assertSame('nudge', $this->judge($this->offer(), nowMs: self::TURN_MS + 1_800_000)->code);
    }

    public function test_the_same_offer_is_noticed_once(): void
    {
        $slot = ['idle_since' => self::TURN_MS, 'nudged_at' => self::NOW_MS - 60_000, 'session_id' => 'sess-1'];

        $this->assertSame('already_nudged', $this->judge($this->offer(), $slot)->code);
    }

    public function test_the_same_offer_unchanged_a_whole_horizon_after_its_notice_is_stale(): void
    {
        $slot = ['idle_since' => self::TURN_MS, 'nudged_at' => self::NOW_MS - 1_800_000, 'session_id' => 'sess-1'];

        $v = $this->judge($this->offer(), $slot);

        $this->assertSame('offer_stale', $v->code);
        $this->assertNull($v->plan);
        $this->assertContains('offer_stale', AgentVerdict::SEAT_RECORD_FAULTS);
    }

    /** @return array<string, array{array{idle_since: int, nudged_at: ?int, session_id?: ?string}}> */
    public static function otherOffers(): array
    {
        $longAgo = self::NOW_MS - 10 * 3_600_000;

        return [
            'a later turn end' => [['idle_since' => self::TURN_MS - 1, 'nudged_at' => $longAgo, 'session_id' => 'sess-1']],
            'another session' => [['idle_since' => self::TURN_MS, 'nudged_at' => $longAgo, 'session_id' => 'sess-0']],
            'a null session where the offer has one' => [['idle_since' => self::TURN_MS, 'nudged_at' => $longAgo, 'session_id' => null]],
            'a Mezzanine slot with the same instant' => [['idle_since' => self::TURN_MS, 'nudged_at' => null]],
        ];
    }

    /** @param  array{idle_since: int, nudged_at: ?int, session_id?: ?string}  $slot */
    #[DataProvider('otherOffers')]
    public function test_a_different_offer_re_arms(array $slot): void
    {
        $this->assertSame('nudge', $this->judge($this->offer(), $slot)->code);
    }

    public function test_a_null_session_offer_matches_its_own_null_session_slot(): void
    {
        $slot = ['idle_since' => self::TURN_MS, 'nudged_at' => self::NOW_MS - 60_000, 'session_id' => null];

        $this->assertSame('already_nudged', $this->judge($this->offer(session: null), $slot)->code);
    }

    public function test_a_new_offer_inside_the_cooldown_of_the_last_notice_waits(): void
    {
        $slot = ['idle_since' => self::TURN_MS - 600_000, 'nudged_at' => self::NOW_MS - 3_599_000, 'session_id' => 'sess-1'];
        $this->assertSame('cooldown', $this->judge($this->offer(), $slot)->code);

        $slot['nudged_at'] = self::NOW_MS - 3_600_000;
        $this->assertSame('nudge', $this->judge($this->offer(), $slot)->code);
    }

    public function test_the_cooldown_is_the_current_records_own(): void
    {
        $slot = ['idle_since' => self::TURN_MS - 600_000, 'nudged_at' => self::NOW_MS - 120_000, 'session_id' => 'sess-1'];

        $this->assertSame('nudge', $this->judge($this->offer(cooldownS: 60), $slot)->code);
    }

    public function test_a_slot_with_no_notice_time_owes_no_cooldown(): void
    {
        // A slot from a state file written before `nudged_at` existed.
        $this->assertSame('nudge', $this->judge($this->offer(), ['idle_since' => self::TURN_MS - 600_000, 'nudged_at' => null])->code);
    }

    public function test_with_no_snapshot_read_the_mezzanine_agents_are_unrouted_or_fleet_unmeasured(): void
    {
        $e = (new IdleNudgeEvaluator)->evaluate(
            null, 'inst-a', ['quiet' => false, 'impl' => true], [],
            fn (string $a): array => $this->fail('no inbox is read without a snapshot'),
            fn (string $a, array $ids): array => $this->fail('no push time is read without a snapshot'),
            0.0, 1800,
        );

        $this->assertNull($e->seatTally);
        $this->assertSame(['impl' => 'fleet_unmeasured', 'quiet' => 'not_push_routed'], array_column(array_map(fn (AgentVerdict $v): array => [$v->agent, $v->code], $e->verdicts), 1, 0));
        $this->assertFalse($e->verdicts[0]->measured());
    }
}
