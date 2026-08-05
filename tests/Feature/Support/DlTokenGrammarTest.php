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
            'DL-12345' => 'DL-12345',   // unbounded: the measured divergence from the CI lint's four-digit bound (card#5300)
            'DL239' => null,            // the separator is MANDATORY — no glued arm
            'DL_239' => null,           // ...and `_` is not the separator
            'IDL-239' => null,          // leading boundary: an embedded token is not a token
            'DL-' => null,              // a prefix with no digits names nothing
            'DLs-239' => null,          // the plural names no DL in any spelling (card#5310)
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

    /**
     * THE ANTI-SNAPSHOT ASSERTION (card#5310), the shape `CardTokenGrammarTest`
     * settled on. `describe()` now renders a human-facing log line, so it can go
     * stale the way the card token's hand-written accept-set did for two
     * releases. Read the RENDERED text, not the helpers that built it — a
     * `describe()` rewritten to a hardcoded string passes any assertion phrased
     * against `accepted()`/`rejected()`, which is the snapshot this exists to
     * make impossible.
     */
    public function test_the_sentence_is_a_function_of_the_pattern_not_a_remembered_list(): void
    {
        $sentence = DlTokenGrammar::describe();
        $parts = explode(' — not accepted: ', $sentence);
        $this->assertCount(2, $parts, 'the sentence must carry both halves');
        $acceptedClause = str_replace('accepted: ', '', $parts[0]);

        foreach (DlTokenGrammar::VECTORS as $vector) {
            $inAccepted = in_array($vector, array_map('trim', explode(',', $acceptedClause)), true);
            $inRejected = in_array($vector, array_map('trim', explode(',', $parts[1])), true);

            $this->assertSame(DlTokenGrammar::parse($vector) !== null, $inAccepted,
                "the sentence must put '{$vector}' where the PATTERN puts it");
            $this->assertNotSame($inAccepted, $inRejected, "'{$vector}' must appear on exactly one side");
        }
    }

    /**
     * Every cell of the probe's derived cross-product is ASCII, carries a digit,
     * and sits at a token boundary — so warn-iff-not-parsed holds EXACTLY over
     * it, with no exclusions to argue about. That makes it the one corpus where
     * the probe and the grammar can be compared cell by cell.
     */
    public function test_the_probe_sees_every_cell_of_its_corpus_and_the_grammar_decides_which_ones_warn(): void
    {
        $vectors = DlTokenGrammar::probeVectors();
        $this->assertNotEmpty($vectors, 'an empty corpus would assert nothing');

        $parsing = 0;
        foreach ($vectors as $vector) {
            $this->assertTrue(DlTokenGrammar::looksLikeDlToken($vector),
                "'{$vector}' is invisible to the near-miss probe — it could never warn");
            $parsing += DlTokenGrammar::parse($vector) === null ? 0 : 1;
        }

        // `DL-239` is a cell of the cross-product AND the canonical spelling, so
        // the corpus is not all-near-miss — which is what stops this leg from
        // being satisfied by a probe that says yes to everything the grammar
        // rejects and nothing else.
        $this->assertSame(1, $parsing, 'exactly the separated cell must correlate — the rest are near-misses');
    }

    /**
     * THE SILENT SET, pinned so it cannot grow quietly (card#5310). These are
     * rejected rows the probe cannot see, each for a reason that is a ratified
     * bound rather than an oversight — so a NEW rejected row that lands here is
     * a new SILENT shape, and this leg is where that has to be argued rather
     * than absorbed. It is the one place in this file that lists an answer, and
     * it lists it precisely because the list must not move by accident.
     */
    public function test_the_rows_the_probe_cannot_see_are_exactly_the_ratified_bounds(): void
    {
        $silent = array_values(array_filter(
            DlTokenGrammar::rejected(),
            fn (string $v) => ! DlTokenGrammar::looksLikeDlToken($v),
        ));

        $this->assertSame([
            'IDL-239',          // leading boundary — an embedded token is not a near-miss (DL-201)
            'DL-',              // no digit at all — a bare prefix is not a token-shaped miss
            "DL-\u{0663}",      // the ASCII digit class (DL-231) — covered by CLASS in prose, not by spelling
        ], $silent, 'a rejected row became invisible to the probe — that is a new silent shape, not a passing detail');
    }

    /**
     * `-` is in the probe's separator set although it is this grammar's ONLY
     * accepted separator, which looks like it should make the probe fire on
     * every real DL and does not: every text matching `\bDL-\d` parses, so no
     * such text reaches a probe consulted only where `parse()` returned null.
     * Pinned rather than asserted in a comment, because a comment cannot red
     * when the pattern moves.
     */
    public function test_a_separated_prefix_always_parses_so_the_probes_accepted_separator_stays_inert(): void
    {
        $corpus = array_merge(DlTokenGrammar::VECTORS, DlTokenGrammar::probeVectors(), [
            'feat/DL-239_fix', 'fix a thing DL-1 today', 'release: DL-239 and DL-240',
            'the IDL-2 spec', 'no token at all',
        ]);

        $exercised = 0;
        foreach ($corpus as $text) {
            if (preg_match('/\bDL-\d/i', $text) !== 1) {
                continue;
            }
            $exercised++;
            $this->assertNotNull(DlTokenGrammar::parse($text),
                "'{$text}' carries a separated prefix but does not parse — the probe's `-` would newly fire on it");
        }

        $this->assertGreaterThan(0, $exercised, 'no corpus text reached the implication — this would prove nothing');
    }

    /**
     * The deliberate SILENCES on the probe side: prose keeps the DL-201 ruling
     * (`DL 239` is not a near-miss), the plural WITHOUT a digit is ordinary
     * English this repo writes in its own decision log, and an embedded token is
     * what the leading boundary protects.
     */
    public function test_the_probe_stays_silent_on_prose_and_embedded_words(): void
    {
        foreach (['DL 239', 'the DLs are genuinely cardless', '2+ DLs in one title',
            'the IDL-239 spec', 'a MODL-3 header', 'the sdl2 renderer', 'no token at all'] as $text) {
            $this->assertNull(DlTokenGrammar::parse($text), "'{$text}' must not parse");
            $this->assertFalse(DlTokenGrammar::looksLikeDlToken($text), "'{$text}' must not look like a DL token");
        }
    }
}
