<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClosureGrammar;
use App\Bridge\Support\RevertGrammar;
use Tests\TestCase;

/**
 * The revert grammar's own contract (card#8306).
 *
 * ⛔ THE FIXTURES ARE OBSERVED, NOT INVENTED, and that is the first thing to check if a
 * row here looks arbitrary. No revert PR exists anywhere in this shop (1,607 PRs across
 * `agent-webhook-bridge`, `agent-board-toolkit` and `kanban-board`, zero matches on title
 * or head ref), so the shapes were read off public repositories where GitHub itself minted
 * them — `laravel/framework#61330` (`Revert "[13.x] Prevent loose comparison bypass in
 * contains validation rule"` on `revert-61320-fix/contains-strict-comparison`, reverting
 * #61320 whose own head was `fix/contains-strict-comparison`) and
 * `laravel/framework#61262` (`revert-61245`, the suffix dropped because the original's
 * branch was gone). Both fields were pulled through the REST API, not read from a docs
 * page: GitHub's *Reverting a pull request* article does not state either format.
 *
 * WHAT MAKES THIS A GRAMMAR AND NOT A FLAG: the same upstream act writes a marker on TWO
 * surfaces owned by two different authorities, and the pair must not be spelled twice.
 */
class RevertGrammarTest extends TestCase
{
    /** The observed GitHub-minted title, and the closing form card#8294 put inside it. */
    private const OBSERVED_TITLE = 'Revert "fix(ci): red a PR that correlates a card and closes it on neither route (closes card#8294)"';

    /** The observed GitHub-minted head ref, over the pre-2026-08-29 house branch spelling. */
    private const OBSERVED_REF = 'revert-611-card-8294-closure-gate';

    public function test_the_defect_a_quoted_closing_form_is_not_this_authors_claim(): void
    {
        // THE DEFECT, at the smallest surface that shows it. Before this grammar the
        // quoted text was read exactly as an author's own, so a merged revert moved the
        // reverted card FORWARD. Correlation is deliberately untouched: the token still
        // parses out of both surfaces, which is what makes this a CLOSURE question.
        $this->assertSame(8294, CardTokenGrammar::parse(self::OBSERVED_TITLE));
        $this->assertSame(8294, CardTokenGrammar::parse(self::OBSERVED_REF));

        $this->assertTrue(RevertGrammar::quotesRevertedTitle(self::OBSERVED_TITLE));
        $this->assertTrue(RevertGrammar::isRevertRef(self::OBSERVED_REF));
        $this->assertFalse(ClosureGrammar::closesCard(self::OBSERVED_TITLE, 8294));
    }

    public function test_only_the_quoted_span_is_subtracted(): void
    {
        // THE RULING, and the reason it is a subtraction rather than a veto over the whole
        // title: a hand-written revert may legitimately COMPLETE a card — the "back this
        // out" card the revert exists to serve. Text outside the quotes is this author's
        // own and is read exactly as it always was, so the escape hatch is POSITIONAL and
        // needs no new vocabulary. It cannot fire by accident: GitHub never writes outside
        // the quotes.
        $hand = 'Revert "feat: streaming (closes card#8294)" — back it out (closes card#456)';
        $this->assertFalse(ClosureGrammar::closesCard($hand, 8294), 'the quoted original must not close');
        $this->assertTrue(ClosureGrammar::closesCard($hand, 456), 'the author\'s own closing form must still close');
    }

    public function test_nesting_is_ruled_not_left_to_the_regex(): void
    {
        // ⛔ THE NESTED RULING. A revert of a revert re-applies the work, so an argument
        // exists that it SHOULD close; it does not. GitHub does not escape the inner
        // quotes, so the depth is not reliably parseable at all, and a parity count over an
        // ambiguous parse would authorize a TERMINAL move on a guess. The cost is near
        // zero: the first revert never moved the card back, so there is nothing to promote.
        $nested = 'Revert "Revert "fix(ci): red a PR (closes card#8294)""';
        $this->assertTrue(RevertGrammar::quotesRevertedTitle($nested));
        $this->assertFalse(ClosureGrammar::closesCard($nested, 8294));
        $this->assertTrue(RevertGrammar::isRevertRef('revert-612-revert-611-card-8294-slug'));
    }

    public function test_an_unterminated_wrapper_still_subtracts(): void
    {
        // The one direction this must never fail in. A title truncated after the opening
        // quote matches no closing quote, and without the `.*$` alternative the inherited
        // closing form would survive intact — a silent re-opening of the whole defect on a
        // shape nobody would think to test. (Drop that alternative ⇒ this reds.)
        $this->assertFalse(ClosureGrammar::closesCard('Revert "fix(ci): red a PR (closes card#8294', 8294));
        $this->assertFalse(ClosureGrammar::closesCard("Revert \"fix(ci)\nred a PR (closes card#8294)\"", 8294));
    }

    public function test_the_controls_that_make_every_row_above_evidence(): void
    {
        // ⛔ WITHOUT THESE, every assertion above is satisfied by a predicate that simply
        // refuses everything. An ordinary closing title still closes; an ordinary card
        // branch still closes structurally; and neither surface reads as a revert.
        $ordinary = 'fix(ci): red a PR that correlates a card (closes card#8294)';
        $this->assertTrue(ClosureGrammar::closesCard($ordinary, 8294));
        $this->assertFalse(RevertGrammar::quotesRevertedTitle($ordinary));
        $this->assertFalse(RevertGrammar::isRevertRef('card-8294-closure-gate'));
        $this->assertSame($ordinary, RevertGrammar::withoutQuotedRevert($ordinary), 'a non-revert title is returned byte-identical');
    }

    public function test_the_ref_predicate_does_not_swallow_a_human_branch(): void
    {
        // The anchor and the digits are what separate GitHub's artifact from a human branch
        // that merely starts with the word. `revert/8306-…` is the `<type>/<id>-slug` house
        // convention with a `revert` TYPE — its segment ends in a slash, not a hyphen — and
        // `revert-the-thing` carries no PR number. Neither is GitHub's mint, so neither
        // loses whatever closure it had. (Drop the `^` or the `\d+` ⇒ these red.)
        foreach (['revert/8306-back-out-the-thing', 'revert-the-streaming-change', 'fix/reverted-8294-slug', 'card-8294-revert-1-slug'] as $ref) {
            $this->assertFalse(RevertGrammar::isRevertRef($ref), "'{$ref}' is not GitHub's revert mint");
        }
        // …and the two shapes that ARE, including the branch-deleted one.
        foreach (['revert-611-card-8294-slug', 'revert-611', 'Revert-611-Card-8294-Slug'] as $ref) {
            $this->assertTrue(RevertGrammar::isRevertRef($ref), "'{$ref}' is GitHub's revert mint");
        }
    }

    public function test_either_surface_alone_marks_a_pr_as_a_revert(): void
    {
        // The two surfaces are independent in the wild: a `git revert` pushed to an
        // ordinary branch carries only the title wrapper, and a GitHub revert whose author
        // retitled it carries only the ref. The operator-facing refusal keys on EITHER, so
        // a line explaining the withheld move is emitted in both.
        $this->assertTrue(RevertGrammar::isRevert(self::OBSERVED_TITLE, 'feat/undo-the-streaming-change'));
        $this->assertTrue(RevertGrammar::isRevert('chore: back out the streaming change', self::OBSERVED_REF));
        $this->assertFalse(RevertGrammar::isRevert('fix(ci): red a PR (closes card#8294)', 'card-8294-closure-gate'));
    }

    public function test_the_refusal_sentence_names_both_surfaces_and_the_escape_hatch(): void
    {
        // An operator reading it must be able to tell WHY nothing moved and what to do —
        // the failure DL-308 fixed for the branch half and this change re-created for the
        // revert half. Asserted on content, never on a whole-string snapshot.
        $sentence = RevertGrammar::describeRefusal();
        $this->assertStringContainsString('REVERT', $sentence);
        $this->assertStringContainsString('card#8306', $sentence);
        $this->assertStringContainsString('OUTSIDE', $sentence, 'the escape hatch must be reachable from the line itself');
        $this->assertStringContainsString('never moved back', $sentence, 'the no-demotion promise (DL-305) still holds here');
    }
}
