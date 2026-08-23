<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\PrOutcome;
use App\Bridge\Writeback\WritebackConfig;
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

    public function test_exactly_the_merge_outcomes_require_a_closing_form(): void
    {
        // card#7348 / DL-305. The gated set is asserted BOTH WAYS against the writeback's
        // full outcome vocabulary, so a future outcome added to `WritebackConfig::OUTCOMES`
        // cannot quietly land on either side of this boundary unexamined — it lands here
        // as a red test that makes someone decide.
        $this->assertSame(['merged', 'merged_to_main'], PrOutcome::MERGE_OUTCOMES);

        foreach (PrOutcome::MERGE_OUTCOMES as $gated) {
            $this->assertTrue(PrOutcome::requiresClosure($gated), "{$gated} claims a card is done and must be gated");
        }
        foreach (array_diff(WritebackConfig::OUTCOMES, PrOutcome::MERGE_OUTCOMES) as $ungated) {
            $this->assertFalse(PrOutcome::requiresClosure($ungated), "{$ungated} makes no completion claim and must NOT be gated");
        }
        // `reopened` is a handler-internal outcome with no config stage of its own, so it
        // is absent from OUTCOMES and would be missed by the loop above.
        $this->assertFalse(PrOutcome::requiresClosure('reopened'));
    }
}
