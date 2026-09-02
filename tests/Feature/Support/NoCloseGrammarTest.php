<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClosureGrammar;
use App\Bridge\Support\NoCloseGrammar;
use App\Bridge\Writeback\PrOutcome;
use Tests\TestCase;

/**
 * The `[no-close]` marker's own contract (card#8344 / DL-327).
 *
 * ⛔ EVERY ASSERTION THAT THE MARKER WITHHOLDS A MOVE IS PAIRED WITH THE SAME ROW MINUS THE
 * MARKER, and that is not ceremony. A veto is trivially satisfied by a predicate that
 * refuses everything — the DL-305 failure mode exactly, a gate nothing satisfies freezing
 * the board it guards — so a witness without its control measures nothing. Each control is
 * one variable away: the identical title, identical ref, identical outcome, marker deleted.
 */
class NoCloseGrammarTest extends TestCase
{
    /** The house shape: a context PR built ON the card's own branch, which is what makes the marker necessary. */
    private const REF = 'card-8344-no-close-marker';

    private const MARKED = 'docs: cite the prior ruling [no-close] (card#8344)';

    private const CONTROL = 'docs: cite the prior ruling (card#8344)';

    public function test_the_defect_a_context_pr_on_the_cards_branch_closed_it_structurally(): void
    {
        // THE DEFECT AT ITS SMALLEST SURFACE. The structural route (DL-308) reads the head
        // ref's IDENTITY, and a PR written FOR a card without finishing it has exactly the
        // ref a PR that finishes it has — so the premise held and the conclusion was still
        // wrong. Nothing in the artifact distinguishes them; the CONTROL is the proof that
        // this row turns on the marker and not on some property of the branch or the title.
        $this->assertTrue(PrOutcome::mergeClosesCard('merged', self::REF, 8344, self::CONTROL),
            'the control must close, or the witness below is satisfied by a gate that refuses everything');
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', self::REF, 8344, self::MARKED));

        // CORRELATION IS UNTOUCHED — the card is still selected on both surfaces, which is
        // what keeps the PR-ref stamps and `bridge:reconcile`'s population intact. This is
        // a CLOSURE question, exactly as the revert refusal is.
        $this->assertSame(8344, CardTokenGrammar::parse(self::MARKED));
        $this->assertSame(8344, CardTokenGrammar::parse(self::REF));
    }

    public function test_it_vetoes_the_lexical_route_including_a_closing_dl(): void
    {
        // THE VETO IS OVER THE WHOLE TITLE, not over a span: an author who declares a
        // non-closure and then writes a closing form has contradicted themselves, and the
        // safe reading of a contradiction is the recoverable one (no move).
        $this->assertTrue(ClosureGrammar::closesCard('feat: widget (closes card#4811)', 4811));
        $this->assertFalse(ClosureGrammar::closesCard('feat: widget [no-close] (closes card#4811)', 4811));

        // THE DL FORM TOO, which is why the veto lives at the grammar's choke point rather
        // than in `closesCard()`: `bridge:reconcile` closes a card off `closedDls()`, so a
        // veto spelled in one predicate would be missed by the other.
        $this->assertTrue(ClosureGrammar::closesDl('fix: thing (fixes DL-239)', 'DL-239'));
        $this->assertFalse(ClosureGrammar::closesDl('fix: thing [no-close] (fixes DL-239)', 'DL-239'));
        $this->assertSame([], ClosureGrammar::closedDls('fix: thing [no-close] (fixes DL-239)'));
        $this->assertSame([], ClosureGrammar::closedCardIds('feat: widget [no-close] (closes card#4811)'));
    }

    public function test_it_is_a_literal_and_not_a_grammar(): void
    {
        // card#8294's ruling, inherited: prose that RESEMBLES the marker must not withhold
        // a move, or the writeback resumes guessing at intent through a fuzzier surface
        // than the one it just refused to guess from. Each row still closes.
        foreach ([
            'docs: cite the ruling, no close intended (closes card#4811)',
            'docs: cite the ruling — this does not close the card (closes card#4811)',
            'docs: cite the ruling [noclose] (closes card#4811)',
            'docs: cite the ruling [no_close] (closes card#4811)',
            'docs: cite the ruling (no-close) (closes card#4811)',
        ] as $title) {
            $this->assertFalse(NoCloseGrammar::marks($title), "'{$title}' must not read as the marker");
            $this->assertTrue(ClosureGrammar::closesCard($title, 4811), "'{$title}' must still close");
        }

        // ⛔ AND THE LITERAL'S OWN TEXT IS NOT A CLOSING FORM, measured on a near-spelling
        // the veto does NOT catch — `[no-close]` is one character away from a verb bridge
        // (`close` at a word boundary), and under the veto that property is unfalsifiable
        // through the marker itself. `[no-close!]` differs from the marker, so the grammar
        // reads it normally and answers about the BRACKET adjacency alone.
        $this->assertFalse(NoCloseGrammar::marks('docs: a thing [no-close!] (card#123)'));
        $this->assertFalse(ClosureGrammar::hasClosure('docs: a thing [no-close!] card#123'),
            'the bracketed `close` must not bridge to the token');
    }

    public function test_a_title_that_merely_mentions_the_marker_is_marked_by_it(): void
    {
        // ⚠ THE DISCLOSED COST OF A LITERAL, pinned so the disclosure is falsifiable rather
        // than a sentence in a docblock. There is no quoting rule — a title whose SUBJECT is
        // this marker declares a non-closure by talking about one, and the PR that shipped
        // the feature is the first instance. The cure would be a position or context rule,
        // which is exactly the grammar the row above refuses to be; the failure direction is
        // UNDER-promotion, which is the recoverable one. If this row ever needs an exception,
        // that is a decision, not a bug fix.
        $this->assertTrue(NoCloseGrammar::marks('feat: the writeback honours [no-close] on the PR title (closes card#8344)'));
        $this->assertFalse(ClosureGrammar::closesCard('feat: the writeback honours [no-close] on the PR title (closes card#8344)', 8344));
    }

    public function test_case_and_position_are_free(): void
    {
        // Case-insensitive, mirroring the CI step's `tr` fold and every grammar in this
        // package. Position-free because the house convention parks the marker mid-title
        // and a position rule would fail OPEN — a marker the author wrote and the writeback
        // ignored is the defect this closes.
        foreach (['[no-close]', '[NO-CLOSE]', '[No-Close]'] as $spelling) {
            $this->assertTrue(NoCloseGrammar::marks("docs: cite it {$spelling} (card#8344)"));
        }
        foreach ([
            '[no-close] docs: cite the ruling (card#8344)',
            'docs: cite the ruling (card#8344) [no-close]',
            'docs: cite the ruling (card#8344)[no-close]',
        ] as $title) {
            $this->assertTrue(NoCloseGrammar::marks($title), "'{$title}' must read as marked");
            $this->assertFalse(PrOutcome::mergeClosesCard('merged', self::REF, 8344, $title));
        }
    }

    public function test_a_quoted_marker_is_the_original_authors_declaration(): void
    {
        // DL-318's ruling RE-DERIVED on the second marker rather than assumed by analogy,
        // and the case is reachable: a revert takes no structural route at all, so the two
        // readings can differ only on the LEXICAL one — which is where DL-318's positional
        // escape hatch lives. A marker inside the quotes is the ORIGINAL author's claim
        // about the ORIGINAL PR, so it must not veto this author's own closing form.
        $hand = 'Revert "docs: cite the ruling [no-close] (card#8344)" — deliberate, this completes it (closes card#456)';
        $this->assertFalse(NoCloseGrammar::marks($hand),
            'a marker inside the quoted original is not this author\'s declaration');
        $this->assertTrue(ClosureGrammar::closesCard($hand, 456),
            'the escape hatch must survive a marker in the quoted original');

        // …and the author's OWN marker, outside the quotes, still vetoes that hatch.
        $vetoed = 'Revert "docs: cite the ruling (card#8344)" [no-close] — back it out (closes card#456)';
        $this->assertTrue(NoCloseGrammar::marks($vetoed));
        $this->assertFalse(ClosureGrammar::closesCard($vetoed, 456));
    }

    public function test_the_marker_can_only_ever_withhold_a_move(): void
    {
        // ⛔ THE PROPERTY THAT MAKES THIS NOT THE ACCEPT-SURFACE DL-318 REFUSED, asserted
        // rather than argued in prose: over a corpus that already fails the gate, adding
        // the marker never turns a no into a yes. It is a term in a conjunction on the
        // structural route and an early return on the lexical one, so there is no title in
        // which it can select a card, authorize a stage, or overturn a guard.
        $rows = [
            ['merged', 'feat: rework, follows card#4811', 'fix/streaming-timeout', 4811],  // the bare mention
            ['merged_to_main', 'feat: rework (card#4811)', 'card-4811-widget', 4811],      // release merge
            ['merged', 'feat: rework (card#4811)', 'fix/4811-widget', 4811],               // id, no token
            ['merged', 'feat: rework (closes card#9999)', 'fix/streaming-timeout', 4811],  // closes another card
        ];
        foreach ($rows as [$outcome, $title, $ref, $id]) {
            $plain = PrOutcome::mergeClosesCard($outcome, $ref, $id, $title) || ClosureGrammar::closesCard($title, $id);
            $marked = PrOutcome::mergeClosesCard($outcome, $ref, $id, $title.' [no-close]')
                || ClosureGrammar::closesCard($title.' [no-close]', $id);
            $this->assertFalse($plain, "'{$title}' is expected to close nothing before the marker");
            $this->assertFalse($marked, "'{$title}' must not START closing because of the marker");
        }

        // THE CONTROL for the row above: the same construction over a title that DOES close
        // shows the pair discriminates rather than reporting false twice.
        $this->assertTrue(PrOutcome::mergeClosesCard('merged', self::REF, 8344, self::CONTROL));
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', self::REF, 8344, self::CONTROL.' [no-close]'));
    }

    public function test_the_refusal_sentence_names_the_marker_and_the_pins_precedence(): void
    {
        // DL-239: the operator-facing sentence is OWNED here so both consuming surfaces
        // render the identical one, and it RENDERS the literal rather than spelling it — so
        // a change to the marker rewrites the sentence by construction.
        $sentence = NoCloseGrammar::describeRefusal();
        $this->assertStringContainsString(NoCloseGrammar::MARKER, $sentence);
        // The two things an operator meeting this line needs and cannot get elsewhere: that
        // it was DELIBERATE (so they do not go rename a branch), and how it composes with
        // the card-side pin, which is the question card#8289 leaves open on this surface.
        $this->assertStringContainsString('CITES the card rather than finishing it', $sentence);
        $this->assertStringContainsString('block_reason', $sentence);
        $this->assertStringContainsString('no-automove', $sentence);
    }

    public function test_both_operator_sentences_state_the_veto(): void
    {
        // A sentence that named the two closure routes and omitted the condition that
        // EMPTIES both would be false about exactly the PR whose author is reading it. Both
        // flavours carry it — unlike the rejected-spelling lists the two are split over,
        // this is not diagnosis, it is a term of the rule.
        foreach ([PrOutcome::describeClosure(), PrOutcome::describeClosureAccepted()] as $flavour) {
            $this->assertStringContainsString(NoCloseGrammar::MARKER, $flavour);
        }
    }
}
