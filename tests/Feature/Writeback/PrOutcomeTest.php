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

    // The two title surfaces the structural term now reads. `PLAIN_TITLE` carries NO
    // closing form on purpose: every structural assertion below must turn on the ref and
    // the revert ruling alone, never on a lexical route quietly answering for it.
    private const PLAIN_TITLE = 'feat: widget';

    private const REVERT_TITLE = 'Revert "feat: widget (closes card#4811)"';

    // --- card#7348 / DL-308: the structural closure term ---

    public function test_an_integration_merge_from_a_branch_naming_the_card_closes_it(): void
    {
        // The positive, in every branch-ref spelling the ONE card-token authority accepts —
        // derived from that authority rather than retyped, so a grammar move lands here as
        // a red test instead of as a silently narrower gate. This is the shape
        // `board-card-start` mints. ⚠ It is no longer the shape most of this shop's recent
        // PRs carry — the 92% this comment used to claim was measured under the
        // `card-<id>-slug` convention, which flipped to `<type>/<id>-slug` on 2026-08-29
        // and took the structural route to 0 for 8 consecutive PRs (card#8294). The
        // predicate below did not move; its REACH did, which is why the figure is stated
        // as history here and enforced nowhere.
        foreach (['card-4811-widget', 'card#4811-widget', 'card4811-widget', 'fix/card-4811-widget'] as $ref) {
            $this->assertTrue(PrOutcome::mergeClosesCard('merged', $ref, 4811, self::PLAIN_TITLE), "'{$ref}' names card 4811");
        }
    }

    public function test_the_structural_term_is_keyed_on_this_card_not_on_any_card(): void
    {
        // ⛔ THE NEGATIVE THE WIDENING RESTS ON, at the predicate. A branch naming SOME
        // card must not close a DIFFERENT one — otherwise a title citing someone else's
        // card id would ride any card-named branch into a terminal stage, which is the
        // peer incident card#7348 was filed for, re-minted through the new route.
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', 'card-9999-other', 4811, self::PLAIN_TITLE));
        // …and a branch that names no card at all closes nothing. `fix/4811-widget`
        // carries the id but not a token: the strict reading is deliberate (DL-308) —
        // the loose bare-id test the classifier uses for CORROBORATION accepts an
        // accidental match on the stated grounds that it can never authorize anything,
        // and that justification does not survive being moved to a gate that can.
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', 'fix/streaming-timeout', 4811, self::PLAIN_TITLE));
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', 'fix/4811-widget', 4811, self::PLAIN_TITLE));
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', '', 4811, self::PLAIN_TITLE));
    }

    public function test_a_revert_takes_no_structural_route_on_either_surface(): void
    {
        // ⛔ card#8306 — THE HALF THAT WOULD HAVE BEEN LEFT OPEN by repairing only the
        // quoted title. GitHub wraps the original's branch as `revert-<n>-<original ref>`,
        // so the reverted branch's own card token rides along inside it and the structural
        // term fired on every revert of a card branch spelled the way `board-card-start`
        // spells them. Measured, not reasoned: the token STILL PARSES out of that ref —
        // which is deliberate, because correlation must survive — and only the closure
        // authorization is refused.
        $this->assertSame(4811, CardTokenGrammar::parse('revert-611-card-4811-widget'));
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', 'revert-611-card-4811-widget', 4811, self::PLAIN_TITLE));
        // Nesting does not re-authorize it — the ruling, stated rather than derived.
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', 'revert-612-revert-611-card-4811-widget', 4811, self::PLAIN_TITLE));

        // ⛔ AND THE OTHER HALF, which an earlier revision of this file PINNED AS INTENDED
        // and was wrong to: a HAND-MADE revert (`git revert`, pushed to an ordinary branch)
        // wraps no ref at all and announces itself only in the TITLE. Every row below was
        // MEASURED closing card 4811 while this predicate asked the ref alone — including
        // `card-<id>-slug`, the spelling `board-card-start` mints, so the gap was the
        // COMMON branch shape rather than an exotic one. It was also SILENT: the card
        // reached the closing set, so the withheld-merge warning never fired.
        foreach (['card-4811-widget', 'revert/card-4811-widget', 'revert-card-4811-widget', 'fix/card-4811-widget'] as $ref) {
            $this->assertFalse(PrOutcome::mergeClosesCard('merged', $ref, 4811, self::REVERT_TITLE),
                "'{$ref}' under a revert title must take no structural route");
        }

        // THE CONTROL THAT MAKES THE BLOCK ABOVE EVIDENCE, in both directions — without it
        // every row would also pass a predicate that had simply stopped closing card 4811.
        // Same refs, ordinary title ⇒ still closed; same title, ref naming no card ⇒ still
        // refused, so the rows above turn on the REVERT and not on either field alone.
        foreach (['card-4811-widget', 'revert/card-4811-widget', 'revert-card-4811-widget', 'fix/card-4811-widget'] as $ref) {
            $this->assertTrue(PrOutcome::mergeClosesCard('merged', $ref, 4811, self::PLAIN_TITLE),
                "'{$ref}' with no revert in play must keep its structural route");
        }
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', 'fix/streaming-timeout', 4811, self::REVERT_TITLE));

        // THE PRICE, pinned rather than left to be discovered: a title that merely QUOTES
        // the word loses the structural route too. Under-promotion, loud, and the identical
        // over-refusal the lexical route already accepts — measured at 0 of 1,566 real
        // merged PR titles. If this row ever becomes a real cost, THIS is the line to argue
        // with; it is not an accident.
        $this->assertFalse(PrOutcome::mergeClosesCard('merged', 'card-4811-widget', 4811,
            'fix: revert "the streaming change" and re-land it', self::PLAIN_TITLE));
    }

    public function test_only_the_integration_merge_takes_the_structural_route(): void
    {
        // A release merge still needs a closing form, and no ungated outcome can reach the
        // structural route at all (it is only ever asked about a merge). Asserted over the
        // full outcome vocabulary so a future outcome cannot land on this side unexamined.
        $this->assertFalse(PrOutcome::mergeClosesCard(PrOutcome::RELEASE_MERGE, 'card-4811-widget', 4811, self::PLAIN_TITLE));
        foreach (array_diff(WritebackConfig::OUTCOMES, [PrOutcome::INTEGRATION_MERGE]) as $other) {
            $this->assertFalse(PrOutcome::mergeClosesCard($other, 'card-4811-widget', 4811, self::PLAIN_TITLE), "{$other} must not close structurally");
        }
        $this->assertFalse(PrOutcome::mergeClosesCard('reopened', 'card-4811-widget', 4811, self::PLAIN_TITLE));
        // The constants and the mapping agree — `forMergedBase` is what produces the
        // outcome this predicate compares against, so a drift between them would make the
        // structural route silently unreachable rather than red.
        $this->assertSame(PrOutcome::INTEGRATION_MERGE, PrOutcome::forMergedBase('dev'));
        $this->assertSame(PrOutcome::RELEASE_MERGE, PrOutcome::forMergedBase(PrOutcome::RELEASE_BASE));
    }

    public function test_the_operator_sentence_renders_both_routes_from_their_authorities(): void
    {
        // DL-239 applied across two authorities. The surfaces that tell an operator what
        // moves a card (`bridge:check`, the withheld-merge warning) render THIS, so a move
        // in either grammar rewrites them by construction — and, more to the point here, so
        // that neither surface can go on asserting the title is the only closure route.
        $sentence = PrOutcome::describeClosure();

        $this->assertStringContainsString(ClosureGrammar::describe(), $sentence);
        $this->assertStringContainsString(CardTokenGrammar::describe(), $sentence);
        $this->assertStringContainsString(PrOutcome::RELEASE_BASE, $sentence);
        // The structural half must be SAID, not merely implied by a token list.
        $this->assertStringContainsString('HEAD BRANCH REF', $sentence);

        // The SETUP flavour is the same sentence with both rejected sides withheld —
        // DL-305's editorial split (noise at setup, diagnosis at runtime), preserved rather
        // than overturned by composing two routes into one renderer.
        $accepted = PrOutcome::describeClosureAccepted();
        $this->assertStringContainsString(implode(', ', ClosureGrammar::accepted()), $accepted);
        $this->assertStringContainsString(implode(', ', CardTokenGrammar::accepted()), $accepted);
        $this->assertStringNotContainsString('does NOT close', $accepted);
        $this->assertStringNotContainsString('not accepted:', $accepted);
        // Both flavours describe the SAME structural condition — one clause, one owner, so
        // a change to what the merge must be cannot land on one surface and not the other.
        foreach ([$sentence, $accepted] as $flavour) {
            $this->assertStringContainsString('a merge into the integration branch', $flavour);
        }
    }
}
