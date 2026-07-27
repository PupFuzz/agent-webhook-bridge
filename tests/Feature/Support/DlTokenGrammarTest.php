<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\DlTokenGrammar;
use Tests\TestCase;

/**
 * The DL grammar owner's own contract (card#5308 / DL-240), built to the shape
 * `CardTokenGrammarTest` settled on: the rows that come from ABOVE the code —
 * DL-201's boundary ruling, DL-231's ASCII digit class — are pinned by hand,
 * because asserting the implementation against a ratification is what a test is
 * for. Everything else is derived from the vector set so it cannot become a
 * snapshot of a past value.
 *
 * The one leg with no counterpart there is the CROSS-GRAMMAR one: both classes'
 * docblocks assert the two tokens share a boundary shape, and until this file
 * existed that claim was prose in two places with nothing running it.
 */
class DlTokenGrammarTest extends TestCase
{
    /**
     * The ratified rows. A vector set that quietly lost a row would make every
     * derived assertion here and in `PrTitleLintTest` vacuous for that shape —
     * this is the floor it cannot drop below.
     */
    public function test_the_ratified_rows_are_covered_and_classified_as_ratified(): void
    {
        $rows = [
            'DL-239' => 'DL-239',       // the canonical spelling
            'dl-239' => 'DL-239',       // case-insensitive, prefix normalized to upper
            'DL-0239' => 'DL-0239',     // leading zeros preserved (canonicalized at the stamp site, not here)
            'DL-1' => 'DL-1',           // no digit floor — unlike the glued card token
            'DL-12345' => 'DL-12345',   // unbounded: the measured divergence from the CI lint's [0-9]{1,4} (card#5300)
            'DL239' => null,            // the separator is MANDATORY — no glued arm
            'DL_239' => null,           // ...and `_` is not the separator
            'IDL-239' => null,          // leading boundary: an embedded token is not a token
            'DL-' => null,              // a prefix with no digits names nothing
        ];

        foreach ($rows as $vector => $expected) {
            $this->assertContains($vector, DlTokenGrammar::VECTORS, "'{$vector}' must stay in the vector set");
            $this->assertSame($expected, DlTokenGrammar::parse($vector), "'{$vector}' must parse as ratified");
        }
    }

    /** DL-231: ASCII digits only — the pattern carries no `/u`, ratified fleet-wide. */
    public function test_unicode_digits_never_parse(): void
    {
        $three = "\u{0663}";
        $this->assertNull(DlTokenGrammar::parse("DL-{$three}"), 'a Unicode-digit DL token must not parse');
        $this->assertSame('DL-3', DlTokenGrammar::parse('DL-3'), 'ASCII positive control');
    }

    /** The boundary is leading-`\b`-only — this token is the ORIGINAL of that ruling (DL-201 / roundtable #48). */
    public function test_boundary_is_leading_only(): void
    {
        $this->assertSame('DL-200', DlTokenGrammar::parse('feat/DL-200_fix'), 'no trailing \b — the DL-201 ruling');
        $this->assertNull(DlTokenGrammar::parse('the IDL-2 spec'), 'an embedded token is not a token');
        $this->assertNull(DlTokenGrammar::parse('a MODL-3 header'), 'an embedded token is not a token');
    }

    /**
     * THE COUPLING, made executable. Both classes' docblocks say the card token
     * was widened to MATCH this one's boundary shape (DL-201) — a cross-repo
     * ratification restated in two places and, until now, run by nothing. One
     * fixture shape drives both grammars: if either boundary moves alone, the
     * ratification has been broken and this reds.
     */
    public function test_both_grammars_share_the_ratified_boundary_shape(): void
    {
        // Trailing `_` must not terminate the token in EITHER grammar — the shape
        // that made `card#3054_fix` a silent no-op while `DL-200_fix` was immune.
        $this->assertNotNull(DlTokenGrammar::parse('feat/DL-3054_fix'));
        $this->assertNotNull(CardTokenGrammar::parse('feat/card-3054_fix'));

        // A leading word character must suppress the match in EITHER grammar.
        $this->assertNull(DlTokenGrammar::parse('the xDL-3054 ref'));
        $this->assertNull(CardTokenGrammar::parse('the discard-3054 path'));
    }

    /**
     * `sole()` is the card#4852 guard: a text carrying 2+ DLs is bundled /
     * release-shaped, so its DL is foreign to any one card and must never be
     * stamped. Asserted against `parse()` on the same fixture — the pair is the
     * whole point, since `parse()` alone would happily return the first of two.
     */
    public function test_sole_is_the_exactly_one_predicate_not_the_first_one(): void
    {
        $this->assertSame('DL-239', DlTokenGrammar::sole('release: DL-239 landed'), 'exactly one → the token');
        $this->assertSame('DL-239', DlTokenGrammar::sole('feat/DL-239-slug'), 'inside a branch ref, still one');
        $this->assertNull(DlTokenGrammar::sole('release: DL-239 and DL-240'), 'two DLs → no sole token');
        $this->assertSame('DL-239', DlTokenGrammar::parse('release: DL-239 and DL-240'),
            'control: parse() DOES return the first of two — sole() is a different question');
        $this->assertNull(DlTokenGrammar::sole('no token at all'), 'none → no sole token');
    }

    /**
     * The buckets are a FUNCTION of the pattern, never a listed answer: every
     * vector lands on exactly one side, and both sides are non-empty (a
     * partition with an empty half makes every derived assertion in
     * `PrTitleLintTest` vacuous for that half).
     */
    public function test_the_buckets_partition_the_vector_set_by_the_pattern(): void
    {
        $accepted = DlTokenGrammar::accepted();
        $rejected = DlTokenGrammar::rejected();

        $this->assertNotEmpty($accepted, 'an accept-set with no accepted example teaches nothing');
        $this->assertNotEmpty($rejected, 'the near-misses are the half operators get wrong');
        $this->assertSame(count(DlTokenGrammar::VECTORS), count($accepted) + count($rejected),
            'every vector must land on exactly one side');

        foreach (DlTokenGrammar::VECTORS as $vector) {
            $this->assertSame(
                DlTokenGrammar::parse($vector) !== null,
                in_array($vector, $accepted, true),
                "the buckets must put '{$vector}' where the PATTERN puts it"
            );
        }
    }
}
