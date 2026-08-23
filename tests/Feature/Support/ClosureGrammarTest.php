<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClosureGrammar;
use App\Bridge\Support\DlTokenGrammar;
use Tests\TestCase;

/**
 * The closure grammar's own contract (card#7348 / DL-305), in the two jobs its two
 * sibling grammars split the same way:
 *
 *  - the RULES are pinned by hand, because they come from ABOVE the code — the verb set
 *    is GitHub's documented linking-keyword set, and the adjacency requirement is the
 *    ruling the whole predicate rests on;
 *  - the operator-facing SENTENCE is never pinned by hand. It is asserted to be a
 *    function of the predicate, so it cannot become a snapshot of a past value of one.
 *
 * ⛔ THE DEFECT ROW IS THE FIRST ASSERTION IN THIS FILE. A bare `card#123` must NOT
 * close. That single row is the whole of card#7348: it is what used to move a card into
 * a terminal stage on any PR that merely cited it.
 */
class ClosureGrammarTest extends TestCase
{
    public function test_a_bare_mention_closes_nothing(): void
    {
        // THE DEFECT. Both tokens still PARSE — correlation is untouched, which is what
        // makes this a closure question and not a grammar one.
        $this->assertSame(123, CardTokenGrammar::parse('feat: rework the widget (card#123)'));
        $this->assertSame('DL-239', DlTokenGrammar::parse('feat: rework the widget (DL-239)'));

        $this->assertFalse(ClosureGrammar::closesCard('feat: rework the widget (card#123)', 123));
        $this->assertFalse(ClosureGrammar::closesDl('feat: rework the widget (DL-239)', 'DL-239'));
        $this->assertSame([], ClosureGrammar::closedCardIds('feat: rework the widget (card#123)'));
    }

    public function test_every_github_linking_keyword_closes_on_both_stems(): void
    {
        // Adopted, not invented: GitHub's own keyword set, so an author who already writes
        // PR titles for issue-linking learns nothing new.
        foreach (['close', 'closes', 'closed', 'fix', 'fixes', 'fixed', 'resolve', 'resolves', 'resolved'] as $verb) {
            $this->assertTrue(ClosureGrammar::closesCard("feat: thing ({$verb} card#123)", 123), "'{$verb}' must close a card token");
            $this->assertTrue(ClosureGrammar::closesDl("feat: thing ({$verb} DL-239)", 'DL-239'), "'{$verb}' must close a DL token");
            // Case-insensitive, like both token grammars.
            $this->assertTrue(ClosureGrammar::closesCard('feat: thing ('.strtoupper($verb).' card#123)', 123));
        }
    }

    public function test_the_token_must_sit_flush_against_the_verb(): void
    {
        // THE ADJACENCY RULING. Without it the predicate degrades into "a closing word
        // appears somewhere and a token appears somewhere", which reads almost every real
        // PR title as a closure and would close nothing at all.
        $this->assertFalse(ClosureGrammar::closesCard('Closes the regression card#123 documents', 123));
        $this->assertFalse(ClosureGrammar::closesDl('Fixes the behaviour DL-239 describes', 'DL-239'));

        // A separator is REQUIRED, so a glued verb+token is not a closing form...
        $this->assertFalse(ClosureGrammar::closesCard('Closescard#123', 123));
        // ...and a colon is one, because `Closes: card#123` is a spelling authors write.
        $this->assertTrue(ClosureGrammar::closesCard('Closes: card#123', 123));
    }

    public function test_the_verb_needs_a_leading_word_boundary(): void
    {
        // `unfixes` / `prefixes` carry `fix` inside a word; reading them as closing verbs
        // would make an ordinary English title a completion claim.
        $this->assertFalse(ClosureGrammar::closesCard('Unfixes card#123', 123));
        $this->assertFalse(ClosureGrammar::closesCard('Prefixes card#123', 123));
    }

    public function test_one_verb_closes_one_token(): void
    {
        // ⛔ The stated cost, pinned so it cannot drift into the permissive reading by
        // accident: the second token is a bare MENTION. GitHub's own rule is the same, and
        // the failure direction is an under-promoted card rather than a terminal move.
        $this->assertSame([1234], ClosureGrammar::closedCardIds('Closes card#1234 and card#5678'));
        $this->assertSame([1234, 5678], ClosureGrammar::closedCardIds('Closes card#1234, closes card#5678'));
    }

    public function test_a_closing_form_names_exactly_the_card_it_spells(): void
    {
        // The whole point of the id-keyed predicate: closing one card must never authorize
        // a move on another that happens to be mentioned in the same title.
        $title = 'Rework the widget (card#5139). Closes card#5287';
        $this->assertTrue(ClosureGrammar::closesCard($title, 5287));
        $this->assertFalse(ClosureGrammar::closesCard($title, 5139));
    }

    public function test_the_dl_token_is_normalized_exactly_as_its_own_grammar_normalizes_it(): void
    {
        // Leading zeros are PRESERVED on both sides, because DlTokenGrammar preserves them
        // and this grammar must not mint a second answer about DL identity. Callers that
        // need canonical equality (a stored `dl_number` vs a title) ask the reference
        // normalizer — `bridge:reconcile` does, and its own test pins that.
        $this->assertSame(['DL-0305'], ClosureGrammar::closedDls('feat: x, Closes DL-0305'));
        $this->assertSame(['DL-305'], ClosureGrammar::closedDls('feat: x, Closes DL-305'));
        $this->assertSame(DlTokenGrammar::parse('DL-0305'), ClosureGrammar::closedDls('Closes DL-0305')[0]);
    }

    public function test_the_token_half_is_the_token_grammars_and_not_a_copy(): void
    {
        // The DL-239 property, executable: every spelling the CARD grammar accepts closes
        // when a verb precedes it, and every spelling it rejects closes nothing — so this
        // class cannot drift into a second, narrower accept-set the way a pasted pattern
        // would. Driven off the owner's own vector set, not a list written here.
        foreach (CardTokenGrammar::VECTORS as $vector) {
            $id = CardTokenGrammar::parse($vector);
            $closed = ClosureGrammar::closedCardIds("Closes {$vector}");
            $this->assertSame($id === null ? [] : [$id], $closed, "closure must follow CardTokenGrammar for '{$vector}'");
        }
        foreach (DlTokenGrammar::VECTORS as $vector) {
            $dl = DlTokenGrammar::parse($vector);
            $closed = ClosureGrammar::closedDls("Closes {$vector}");
            $this->assertSame($dl === null ? [] : [$dl], $closed, "closure must follow DlTokenGrammar for '{$vector}'");
        }
    }

    public function test_the_operator_sentence_is_derived_from_the_predicate(): void
    {
        // Never a snapshot: each side of the rendered sentence is exactly what the
        // predicate answers TODAY over the vector set.
        foreach (ClosureGrammar::VECTORS as $vector) {
            $side = ClosureGrammar::hasClosure($vector) ? ClosureGrammar::accepted() : ClosureGrammar::rejected();
            $this->assertContains($vector, $side, "'{$vector}' must render on the side the predicate puts it");
        }
        $this->assertSame(
            count(ClosureGrammar::VECTORS),
            count(ClosureGrammar::accepted()) + count(ClosureGrammar::rejected()),
            'every vector renders on exactly one side',
        );
        $this->assertStringContainsString('Closes card#123', ClosureGrammar::describe());
        $this->assertStringContainsString('does NOT close', ClosureGrammar::describe());
    }

    public function test_the_ratified_rows_cannot_be_dropped_from_the_vector_set(): void
    {
        // The floor: a vector set that quietly lost a row would make every derived
        // assertion above vacuous for that shape. The rejected rows are the ones that
        // matter most — they are the rulings.
        foreach (['Closes card#123', 'Closes DL-239', 'card#123', 'DL-239', 'Closes the bug in card#123', 'Unfixes card#123'] as $row) {
            $this->assertContains($row, ClosureGrammar::VECTORS, "'{$row}' must stay in the vector set");
        }
    }
}
