<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClosureGrammar;
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

    public function test_exactly_closed_unmerged_moves_no_card(): void
    {
        // card#10850 / DL-436. Asserted over the writeback's full outcome vocabulary plus the
        // handler-internal `reopened`, so a future outcome cannot land on either side of this
        // boundary unexamined. Both consumers (the classifier and `bridge:reconcile`) ask this.
        $this->assertSame('closed_unmerged', PrOutcome::CLOSED_UNMERGED);
        $this->assertContains(PrOutcome::CLOSED_UNMERGED, WritebackConfig::OUTCOMES, 'the config key still parses — it names DL-195\'s abandon stage');
        $this->assertFalse(PrOutcome::movesCard(PrOutcome::CLOSED_UNMERGED));
        foreach ([...array_diff(WritebackConfig::OUTCOMES, [PrOutcome::CLOSED_UNMERGED]), 'reopened'] as $moving) {
            $this->assertTrue(PrOutcome::movesCard($moving), "{$moving} still moves a card");
        }
    }

    public function test_the_operator_sentence_names_the_closing_form_as_the_only_route(): void
    {
        // DL-239. The surfaces that tell an operator what moves a card (`bridge:check`, the
        // withheld-merge warning, the DL-390 no-closing-form comment) render THIS, so a move
        // in the grammar rewrites them by construction. Since card#10850 / DL-436 it names one
        // route — and says outright that the head branch is not one, since DL-308 taught
        // authors that it was.
        $sentence = PrOutcome::describeClosure();

        $this->assertStringContainsString(ClosureGrammar::describe(), $sentence);
        $this->assertStringContainsString('closing form in the PR TITLE', $sentence);
        $this->assertStringContainsString('the head branch ref is not closure evidence', $sentence);
        $this->assertStringNotContainsString(CardTokenGrammar::describe(), $sentence, 'the branch-ref spellings were the retired route\'s accept-set');
        $this->assertStringNotContainsString('integration branch', $sentence);
        $this->assertStringContainsString('[no-close]', $sentence);

        // The SETUP flavour is the same sentence with the rejected side withheld — DL-305's
        // editorial split (noise at setup, diagnosis at runtime).
        $accepted = PrOutcome::describeClosureAccepted();
        $this->assertStringContainsString(implode(', ', ClosureGrammar::accepted()), $accepted);
        $this->assertStringNotContainsString('does NOT close', $accepted);
        $this->assertStringContainsString('the head branch ref is not closure evidence', $accepted);
    }
}
