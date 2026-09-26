<?php

namespace Tests\Feature\IdleNudge;

use App\Bridge\IdleNudge\IdleNudgeState;
use App\Bridge\IdleNudge\IdleNudgeUnmeasured;
use App\Bridge\IdleNudge\SeatOfferPlan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dedupe record's seat-record members (rt#562): `session_id` and `nudged_at` round-trip, a
 * file written before either existed still loads, and a member outside its shape is refused
 * rather than guessed at.
 */
class IdleNudgeStateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/idle-nudge-state-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.state_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_file_from_before_the_seat_record_members_loads_unchanged(): void
    {
        File::put(IdleNudgeState::path(), json_encode(['nudged' => ['pm' => ['idle_since' => '2026-09-14T11:00:00.000Z']]]));

        $state = IdleNudgeState::load();

        $this->assertSame(['pm' => 1_789_383_600_000], $state->nudged());
        $this->assertSame(['idle_since' => 1_789_383_600_000, 'nudged_at' => null], $state->slotOf('pm'));
    }

    public function test_a_seat_offer_slot_round_trips_with_its_session_and_notice_time(): void
    {
        IdleNudgeState::load()->markOfferAndSave(new SeatOfferPlan('pm', 'sess-1', 1_789_387_200_125, 1_789_390_800_000, 3600, 1800, 3600, 1, [], 'go'));
        IdleNudgeState::load()->markOfferAndSave(new SeatOfferPlan('ops', null, 1_789_387_200_000, 1_789_390_800_000, 3600, 1800, 3600, 1, [], 'go'));

        $this->assertSame(['nudged' => [
            'ops' => ['idle_since' => '2026-09-14T12:00:00.000Z', 'session_id' => null, 'nudged_at' => '2026-09-14T13:00:00.000Z'],
            'pm' => ['idle_since' => '2026-09-14T12:00:00.125Z', 'session_id' => 'sess-1', 'nudged_at' => '2026-09-14T13:00:00.000Z'],
        ]], json_decode((string) File::get(IdleNudgeState::path()), true));

        $state = IdleNudgeState::load();
        $this->assertSame(['idle_since' => 1_789_387_200_125, 'nudged_at' => 1_789_390_800_000, 'session_id' => 'sess-1'], $state->slotOf('pm'));
        $this->assertSame(['idle_since' => 1_789_387_200_000, 'nudged_at' => 1_789_390_800_000, 'session_id' => null], $state->slotOf('ops'));
    }

    public function test_a_mezzanine_slot_written_over_a_seat_slot_carries_neither_member(): void
    {
        $state = IdleNudgeState::load();
        $state->markOfferAndSave(new SeatOfferPlan('pm', 'sess-1', 1_789_387_200_125, 1_789_390_800_000, 3600, 1800, 3600, 1, [], 'go'));
        $state->markAndSave('pm', 1_789_383_600_000);

        $this->assertSame(['nudged' => ['pm' => ['idle_since' => '2026-09-14T11:00:00.000Z']]], json_decode((string) File::get(IdleNudgeState::path()), true));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedSlots(): array
    {
        return [
            'nudged_at not an instant' => [['idle_since' => '2026-09-14T11:00:00.000Z', 'nudged_at' => 'yesterday']],
            'nudged_at null' => [['idle_since' => '2026-09-14T11:00:00.000Z', 'nudged_at' => null]],
            'session_id a number' => [['idle_since' => '2026-09-14T11:00:00.000Z', 'session_id' => 5]],
        ];
    }

    /** @param  array<string, mixed>  $slot */
    #[DataProvider('malformedSlots')]
    public function test_a_seat_member_outside_its_shape_makes_the_file_malformed(array $slot): void
    {
        File::put(IdleNudgeState::path(), json_encode(['nudged' => ['pm' => $slot]]));

        $this->expectException(IdleNudgeUnmeasured::class);
        $this->expectExceptionMessage('is malformed');
        IdleNudgeState::load();
    }
}
