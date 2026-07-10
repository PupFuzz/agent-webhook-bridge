<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\PrOutcome;
use Tests\TestCase;

class PrOutcomeTest extends TestCase
{
    public function test_merge_to_release_base_is_merged_to_main(): void
    {
        $this->assertSame('merged_to_main', PrOutcome::forMergedBase('main'));
    }

    public function test_merge_to_any_other_base_is_merged(): void
    {
        $this->assertSame('merged', PrOutcome::forMergedBase('dev'));
        $this->assertSame('merged', PrOutcome::forMergedBase('integration'));
        $this->assertSame('merged', PrOutcome::forMergedBase(''));
    }
}
