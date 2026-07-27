<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\CardTokenGrammar;
use Tests\TestCase;

/**
 * The grammar owner's own contract. Two distinct jobs here, and the distinction
 * is the point of card#5267:
 *
 *  - The RATIFIED ROWS (roundtable #159 / DL-233) are pinned by hand, because
 *    they come from ABOVE the code — a cross-repo ratification the pattern is
 *    supposed to implement. Asserting the implementation against them is what a
 *    test is for.
 *  - The operator-facing SENTENCE is never pinned by hand. It is asserted to be
 *    a function of the pattern, so it cannot become a snapshot of a past value
 *    of one — the defect that let the warn string spend two releases naming a
 *    narrower accept-set than the code enforced.
 */
class CardTokenGrammarTest extends TestCase
{
    /**
     * The four ratified rows. A vector set that quietly loses a row would make
     * every derived assertion in this file and in `PrTitleLintTest` vacuous for
     * that shape — this is the floor it cannot drop below.
     */
    public function test_the_ratified_rows_are_covered_and_classified_as_ratified(): void
    {
        $rows = [
            'card-123' => 123,   // separated, dash — the ratified spelling
            'card#123' => 123,   // separated, hash
            'card123' => 123,    // glued, >=2 digits (DL-233)
            'card-3' => 3,       // separated still takes ONE digit...
            'card4' => null,     // ...while glued at one digit is below the toolkit's 2-digit floor
        ];

        foreach ($rows as $vector => $expected) {
            $this->assertContains($vector, CardTokenGrammar::VECTORS, "'{$vector}' must stay in the vector set");
            $this->assertSame($expected, CardTokenGrammar::parse($vector), "'{$vector}' must parse as ratified");
        }
    }

    /** The separator set is closed: only `-` and `#` separate, per DL-201. */
    public function test_non_separators_do_not_parse(): void
    {
        foreach (['card_123', 'card.123', 'card:123', 'card #123', 'card 123'] as $vector) {
            $this->assertNull(CardTokenGrammar::parse($vector), "'{$vector}' must not parse");
        }
    }

    /** DL-231: ASCII digits only, in every separator spelling. */
    public function test_unicode_digits_never_parse(): void
    {
        $three = "\u{0663}";
        foreach (["card#{$three}", "card-{$three}", "card{$three}{$three}"] as $vector) {
            $this->assertNull(CardTokenGrammar::parse($vector), 'a Unicode-digit token must not parse');
        }
        $this->assertSame(3, CardTokenGrammar::parse('card#3'), 'ASCII positive control');
    }

    /** The boundary is leading-`\b`-only (DL-201 / roundtable #48). */
    public function test_boundary_is_leading_only(): void
    {
        $this->assertSame(3054, CardTokenGrammar::parse('feat/card-3054_fix'), 'no trailing \b — the DL-201 ruling');
        $this->assertNull(CardTokenGrammar::parse('the discard-1 path'), 'an embedded word is not a token');
        $this->assertNull(CardTokenGrammar::parse('a wildcard-2 match'), 'an embedded word is not a token');
    }

    /**
     * THE ANTI-SNAPSHOT ASSERTION. Every example lands on the side of the
     * sentence the PATTERN puts it on — computed here, never listed. Change the
     * pattern and the sentence changes with it; there is no third place holding
     * a remembered answer that could stay behind.
     */
    public function test_the_sentence_is_a_function_of_the_pattern_not_a_remembered_list(): void
    {
        // Read the RENDERED text, not the helpers that built it — a describe()
        // rewritten to a hardcoded string passes any assertion phrased against
        // accepted()/rejected(), which is the snapshot this class exists to
        // make impossible.
        $sentence = CardTokenGrammar::describe();
        $parts = explode(' — not accepted: ', $sentence);
        $this->assertCount(2, $parts, 'the sentence must carry both halves');
        $acceptedClause = str_replace('accepted: ', '', $parts[0]);

        foreach (CardTokenGrammar::VECTORS as $vector) {
            $inAccepted = in_array($vector, array_map('trim', explode(',', $acceptedClause)), true);
            $inRejected = in_array($vector, array_map('trim', explode(',', $parts[1])), true);

            $this->assertSame(
                CardTokenGrammar::parse($vector) !== null,
                $inAccepted,
                "the sentence must put '{$vector}' where the PATTERN puts it"
            );
            $this->assertNotSame($inAccepted, $inRejected, "'{$vector}' must appear on exactly one side");
        }

        $this->assertNotEmpty(CardTokenGrammar::accepted(), 'a sentence with no accepted example teaches nothing');
        $this->assertNotEmpty(CardTokenGrammar::rejected(), 'the near-misses are the half operators get wrong');
    }
}
