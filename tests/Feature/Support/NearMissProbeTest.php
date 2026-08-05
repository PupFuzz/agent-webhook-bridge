<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\NearMissProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The probe ASSEMBLY, tested once for every stem that binds it (card#5310).
 * DL-250 proved this mechanism on the card stem and the proof was never about
 * cards: the separator list and the corpus that covers it are two consumers of
 * one definition, so this reds when the built pattern stops carrying that list
 * as written — a group made mandatory (every glued cell goes dark), a lost `s?`
 * (every plural goes dark, which is the defect this all came from), a member
 * that quotes wrong.
 *
 * The per-stem ACCEPT-SETs are not this file's business: `CardTokenGrammarTest`
 * and `DlTokenGrammarTest` own warn-iff-not-parsed for their own grammar. What
 * is asserted here is the property that makes those two files' claims possible
 * at all — the probe SEES every cell of its own domain, and does not see
 * everything.
 */
class NearMissProbeTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function stems(): array
    {
        return [['card', '123'], ['DL', '239']];
    }

    #[DataProvider('stems')]
    public function test_the_probe_recognises_every_cell_of_its_separator_cross_product(string $stem, string $sampleId): void
    {
        $probe = new NearMissProbe($stem);
        $vectors = $probe->vectors($sampleId);

        $this->assertNotEmpty($vectors, 'an empty corpus would assert nothing');
        $this->assertCount(2 * (count(NearMissProbe::SEPARATORS) + 1), $vectors,
            'the corpus must be {singular, plural} × ({no separator} ∪ SEPARATORS) — a cell was lost');

        foreach ($vectors as $vector) {
            $this->assertTrue($probe->matches($vector),
                "'{$vector}' is invisible to the near-miss probe — it could never warn");
        }
    }

    /**
     * The negative half — without it a probe that matched everything would
     * satisfy the leg above. A bare space before the id is the DL-201 prose
     * ruling and its plural; the rest are the embedded-word cases the leading
     * boundary exists for.
     */
    #[DataProvider('stems')]
    public function test_the_probe_does_not_match_prose_embedded_words_or_a_bare_space(string $stem, string $sampleId): void
    {
        $probe = new NearMissProbe($stem);

        foreach (["{$stem} {$sampleId}", "{$stem}s {$sampleId}", "a{$stem}-{$sampleId}",
            "un{$stem}s#{$sampleId}", "the {$stem}s are listed", "{$stem}-x"] as $text) {
            $this->assertFalse($probe->matches($text), "'{$text}' must not look like a token");
        }
    }

    /**
     * The separators are QUOTED into the pattern. `.` is the member that proves
     * it: unquoted it is the any-character metacharacter, so the probe would
     * match a stem followed by any single character and a digit — silently
     * widening every consumer's warning set to shapes no author ever wrote.
     */
    #[DataProvider('stems')]
    public function test_a_separator_is_matched_literally_not_as_a_metacharacter(string $stem, string $sampleId): void
    {
        $probe = new NearMissProbe($stem);

        $this->assertTrue($probe->matches("{$stem}.{$sampleId}"), 'positive control: the literal `.` IS a separator');
        $this->assertFalse($probe->matches("{$stem}X{$sampleId}"),
            'the `.` separator is being compiled as the any-character metacharacter');
    }

    /**
     * The digit class is ASCII (DL-231), so a Unicode-digit token is silent at
     * runtime on EVERY stem. Pinned, not remembered: this is a ratified bound
     * (DL-250 states it for the card stem), and a `/u` flag added to the pattern
     * would flip it without any other leg noticing.
     */
    #[DataProvider('stems')]
    public function test_a_unicode_digit_is_a_known_silent_shape_on_every_stem(string $stem, string $sampleId): void
    {
        $probe = new NearMissProbe($stem);

        $this->assertFalse($probe->matches($stem.'-'."\u{0663}"), 'the ASCII bound (DL-231) has moved');
        $this->assertTrue($probe->matches("{$stem}-{$sampleId}"), 'ASCII positive control');
    }

    /** DL-250(6): the bare space stays out of the data, permanently. */
    public function test_the_separator_set_carries_no_bare_space(): void
    {
        $this->assertNotContains(' ', NearMissProbe::SEPARATORS,
            'a bare-space separator would demand a warning the DL-201 prose ruling forbids');
        $this->assertContains(' #', NearMissProbe::SEPARATORS,
            'the space-then-hash member is load-bearing — DL-234(d)');
    }
}
