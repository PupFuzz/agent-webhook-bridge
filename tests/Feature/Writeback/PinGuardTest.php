<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\PinGuard;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PinGuardTest extends TestCase
{
    public function test_non_empty_block_reason_is_pinned(): void
    {
        $this->assertTrue(PinGuard::isPinned(['block_reason' => 'parked by human']));
    }

    public function test_whitespace_only_block_reason_is_not_pinned(): void
    {
        $this->assertFalse(PinGuard::isPinned(['block_reason' => '   ']));
    }

    public function test_no_automove_tag_is_pinned(): void
    {
        $this->assertTrue(PinGuard::isPinned(['tags' => ['ci', 'no-automove']]));
    }

    public function test_clean_card_is_not_pinned(): void
    {
        $this->assertFalse(PinGuard::isPinned(['tags' => ['ci'], 'block_reason' => null]));
    }

    /**
     * card#8523 R1 / DL-340 — THE DEGRADED-ROW DETECTOR, and the reason it can exist at all:
     * kanban's `TaskResource` emits `block_reason` and `tags` on every row it serves, so a row
     * reaching this predicate carrying NEITHER is a read that degraded (the far end slimmed its
     * projection, or a caller handed in a row it never read) and the predicate is about to
     * answer "not pinned" for a card nobody could read — degrading toward WRITING.
     *
     * DL-340's first draft asserted no bridge-side check could see this. This is the check.
     */
    public function test_a_row_carrying_neither_pin_field_is_reported_as_a_degraded_read(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $m, array $c) => str_contains($m, 'NEITHER block_reason NOR tags')
                && $c['card_id'] === 9
                && $c['reason'] === PinGuard::UNREADABLE_ROW_REASON
        );

        $this->assertFalse(PinGuard::isPinned(['id' => 9]));   // still "not pinned" — LOUD, not refusing
    }

    /**
     * The control that makes the leg above evidence rather than a decoration: an ORDINARY
     * readable row — the shape every healthy kanban read produces — is silent. Without it the
     * detector could be firing on every consult in the bridge and the test above would not know.
     */
    public function test_an_ordinary_readable_row_is_silent(): void
    {
        Log::shouldReceive('warning')->never();

        $this->assertFalse(PinGuard::isPinned(['id' => 9, 'block_reason' => null, 'tags' => []]));
        $this->assertTrue(PinGuard::isPinned(['id' => 9, 'block_reason' => 'parked', 'tags' => []]));
    }

    /**
     * ⚑ EITHER key present is enough to be silent, and that bound is deliberate: `TaskResource`
     * emits the pair together, so a row with exactly one is a partial nothing in this repo
     * builds — while an OR-shaped detector would fire on every legitimately untagged card whose
     * `block_reason` a caller had projected away, and a detector that cries wolf gets muted.
     */
    public function test_a_row_carrying_either_field_alone_is_not_reported(): void
    {
        Log::shouldReceive('warning')->never();

        $this->assertFalse(PinGuard::isPinned(['id' => 9, 'tags' => ['ci']]));
        $this->assertFalse(PinGuard::isPinned(['id' => 9, 'block_reason' => null]));
    }

    /** A degraded row with no readable `id` still reports — the fact is the SHAPE, not the id. */
    public function test_the_detector_reports_a_degraded_row_that_carries_no_id(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $m, array $c) => $c['card_id'] === null && $c['reason'] === PinGuard::UNREADABLE_ROW_REASON
        );

        $this->assertFalse(PinGuard::isPinned([]));
    }
}
