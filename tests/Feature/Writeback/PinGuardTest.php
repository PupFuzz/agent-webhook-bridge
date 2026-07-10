<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\PinGuard;
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
        $this->assertFalse(PinGuard::isPinned([]));
    }
}
