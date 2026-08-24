<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\CardTokenCorroboration;
use Tests\TestCase;

/**
 * The corroboration gate's predicate, at the unit the two handlers share it as
 * (card#5287 / DL-270, card#5953). The end-to-end refusals are pinned at the real
 * surfaces (KanbanMoveCardHandlerTest, KanbanBlockReasonHandlerTest); these pin the
 * DIRECTION the predicate fails in, which is what card#7564 / DL-311 is about:
 * `refuses()` returns `! tracksPr(...)`, so a FALSE "same PR" does not refuse a write,
 * it ALLOWS one — a truncating compare here is permissive, not fail-closed.
 */
class CardTokenCorroborationTest extends TestCase
{
    public function test_a_card_value_naming_no_single_pull_request_corroborates_nothing(): void
    {
        // (Restore `(int) $cardPr === (int) $eventPr` ⇒ both go green ⇒ RED.)
        $this->assertFalse(CardTokenCorroboration::tracksPr('1.5', 1));
        $this->assertFalse(CardTokenCorroboration::tracksPr(1.5, 1));
    }

    public function test_an_uncorroborated_write_is_refused_on_a_card_whose_pr_number_truncated_to_this_pr(): void
    {
        // The gate as its callers see it: card `'1.5'` + event PR 1 used to read
        // "this card already tracks THIS PR" and let the title-only token write.
        $card = ['payload' => ['pr_number' => '1.5']];

        $this->assertTrue(CardTokenCorroboration::refuses(true, $card, 1));
    }

    public function test_the_legitimate_spellings_of_one_pull_request_still_corroborate(): void
    {
        // Controls — the numeric-string form is what a durable-inbox JSON round-trip
        // produces, and a leading-zero/float form is what an operator stamp and a JSON
        // number produce. Refusing these would be a narrowing, not a fix.
        $this->assertTrue(CardTokenCorroboration::tracksPr('148', 148));
        $this->assertTrue(CardTokenCorroboration::tracksPr(148, '148'));
        $this->assertTrue(CardTokenCorroboration::tracksPr('0148', 148));
        $this->assertTrue(CardTokenCorroboration::tracksPr(148.0, 148));

        $this->assertFalse(CardTokenCorroboration::refuses(true, ['payload' => ['pr_number' => '0148']], 148));
    }

    public function test_fail_closed_on_a_value_that_names_no_pull_request_at_all(): void
    {
        $this->assertFalse(CardTokenCorroboration::tracksPr('TBD', 148));
        $this->assertFalse(CardTokenCorroboration::tracksPr(148, null));
        $this->assertFalse(CardTokenCorroboration::tracksPr(null, null));
        $this->assertFalse(CardTokenCorroboration::tracksPr('-148', 148));
    }
}
