<?php

namespace App\Bridge\Support;

use App\Bridge\Writeback\PinGuard;

/**
 * THE AUTHOR'S OWN DECLARATION: `[no-close]` in a PR title means *this PR CITES a card it
 * does not finish*, and since card#8344 the writeback READS it (DL-327).
 *
 * WHY IT HAD TO EXIST, measured twice rather than argued. Since DL-308 a merge closes a
 * card STRUCTURALLY whenever the head ref names it — which is what this fleet's own
 * tooling mints — so a PR built *for* a card while deliberately not finishing it (a design
 * note, a reference doc, a partial spike on the card's branch) promoted that card on
 * merge: a TERMINAL stage move asserting work landed that did not. Measured on real
 * merges (`mezzanine#24` → card 7341, roundtable 388; the same shape again in card#7348's
 * population). Under-promotion is recoverable by hand; this direction is not.
 *
 * ⛔ NO PREDICATE OVER TITLE, BRANCH AND DIFF CAN READ INTENT, which is why this is a
 * declaration and not a heuristic. The three routes already closed into the terminal move
 * — {@see PrOutcome::mergeClosesCard()}'s strict token (card#8294), {@see RevertGrammar}
 * (card#8306), {@see PinGuard} (card#8289) — each answer a question
 * about the ARTIFACT. *"I am citing this card, not closing it"* is a fact about the
 * author, and the only place it can come from is the author.
 *
 * ⛔ THE PIN IS THE CARD-SIDE HALF AND DOES NOT COVER THIS, which is the gap card#8344 was
 * filed for rather than a duplicate of card#8289. `PinGuard` is PRE-EMPTIVE and lives on
 * the CARD: it helps only where someone knew IN ADVANCE that some future PR would cite
 * that card without closing it. In the measured incident nobody did, and it was caught
 * weeks later. This marker is written by the person who already knows, at the moment they
 * know, on the artifact they are already editing. See {@see self::describeRefusal()} for
 * the precedence the two hold together.
 *
 * NO SECOND VOCABULARY IS MINTED — the marker is card#8294's, unchanged. `[no-close]`
 * already exists as the `pr-title-lint` opt-out, is already documented in
 * `CLAUDE_CONVENTIONS.md` § *PR titles*, and already means exactly this proposition to
 * the humans who write it; it was simply read by CI alone. Inventing a runtime-only
 * spelling beside it would leave two markers for one intent, which is the divergence
 * DL-239 keeps being re-derived to prevent. ⚠ Consequently CI and the writeback now read
 * ONE literal, and this class owns it: `.github/workflows/pr-title-lint.yml`'s `optout=`
 * is tied to {@see self::MARKER} by `PrTitleLintTest` — bash cannot read a PHP constant,
 * so the copy is GUARDED rather than deleted.
 *
 * ⛔ IT SUBTRACTS; IT NEVER ADDS — and that is the whole reason it is not the accept-surface
 * DL-318 refused. That entry declined a `[close-anyway]` marker because its effect would be
 * to MOVE a card the writeback had otherwise refused to move: the first title DIRECTIVE the
 * writeback OBEYS, a title's author reaching into the terminal stage. This marker's only
 * possible effect is to WITHHOLD a move. It can never select a card, never authorize a
 * stage, never overturn a guard, and never make the writeback act on anything it would
 * have ignored — so an author who writes it, and an attacker who plants it, can only ever
 * cause the recoverable direction (a card that stands still, loudly warned). The
 * asymmetry is the ruling, not the marker's spelling.
 *
 * A QUOTATION IS NOT AN ASSERTION (DL-318, re-derived on the second marker rather than
 * assumed by analogy). {@see self::marks()} reads the title with GitHub's quoted revert
 * wrapper REMOVED, so `[no-close]` inside `Revert "…"` is the ORIGINAL author's declaration
 * about the ORIGINAL PR and does not veto this author's own closing form written outside
 * the quotes. That difference is REACHABLE and was checked rather than waved at: a revert
 * takes no structural route at all, so the two readings can only differ on the LEXICAL
 * route — which is exactly where DL-318's positional escape hatch lives.
 *
 * CORRELATION IS UNTOUCHED, exactly as it is for a revert. The card is still selected, the
 * PR refs are still stamped, `opened` / `started` / `closed_unmerged` still fire, and
 * `bridge:reconcile` still sees the card. Only the completion claim is refused, which is
 * what keeps the card inside the backstop's population instead of stranding it.
 */
final class NoCloseGrammar
{
    /**
     * The literal, and the ONE place it is spelled in PHP.
     *
     * A LITERAL, NOT A GRAMMAR, deliberately (card#8294's ruling, inherited rather than
     * re-decided): prose that resembles the marker — `no close intended`, `does not close
     * this card` — must NOT withhold a move, or the writeback starts guessing at intent
     * again through a fuzzier surface than the one it just refused to guess from. An
     * author declaring a non-closure types eleven exact characters, and the brackets are
     * what make it unmistakably a marker rather than a sentence.
     */
    public const MARKER = '[no-close]';

    /**
     * Does the PR author declare that this PR does not finish the card(s) it cites?
     *
     * CASE-INSENSITIVE SUBSTRING, and it agrees with the CI step by construction rather
     * than by discipline: `pr-title-lint` folds the title with `tr '[:upper:]'
     * '[:lower:]'` and then tests `grep -qF "$optout"`, which is a fixed ASCII-folded
     * substring test — `stripos()` on a byte string is the same predicate over the same
     * domain (neither folds non-ASCII). `PrTitleLintTest` asserts the two answer sets
     * agree, so the marker cannot come to mean one thing at PR time and another at merge
     * time.
     *
     * ⚠ A TITLE THAT MERELY *MENTIONS* THE MARKER IS MARKED BY IT, and there is no escape:
     * a literal has no quoting rule, so a PR whose subject is the marker itself (a docs PR
     * about this feature; the PR that shipped it) declares a non-closure by talking about
     * one. Disclosed rather than repaired — the cure would be a position or context rule,
     * which is the grammar this deliberately is not, and the failure is UNDER-promotion:
     * loud, logged, and fixed by moving the card. A title that must name the marker without
     * meaning it should reword around it.
     *
     * ANYWHERE IN THE TITLE, with no position rule: the house convention already parks it
     * mid-title (`docs: cite the prior ruling [no-close] (card#8286)`), and a position
     * rule would be a second thing to get wrong in the direction that FAILS OPEN — a
     * marker the author wrote and the writeback ignored is the defect this closes.
     */
    public static function marks(string $title): bool
    {
        return stripos(RevertGrammar::withoutQuotedRevert($title), self::MARKER) !== false;
    }

    /**
     * The operator-facing sentence for a move this marker withheld — OWNED here so the
     * classifier's withheld-merge warning and `bridge:reconcile`'s skip line render the
     * identical one (the DL-239 discipline, as {@see RevertGrammar::describeRefusal()}
     * does for its own case).
     *
     * IT EXISTS BECAUSE THE DEFAULT SENTENCE IS FALSE HERE, which is the same reason
     * card#8306 minted its own. Both surfaces otherwise assert that *the head branch ref
     * does not name the card and the title carries no closing form naming it* — usually
     * untrue of a `[no-close]` PR, whose branch is normally the card's own. An operator
     * reading that line would go and rewrite prose that is already correct, or worse,
     * rename a branch to "fix" a refusal they deliberately asked for.
     *
     * IT NAMES THE PIN'S PRECEDENCE because this is the surface an operator meets the
     * question on. Both refusals hold and neither can overturn the other: they are
     * withholdings, and there is no order in which two withholdings disagree.
     */
    public static function describeRefusal(): string
    {
        return 'This PR TITLE carries the literal `'.self::MARKER.'`, which is the author declaring that it '
            .'CITES the card rather than finishing it (card#8344) — so neither closure route fires and no '
            .'terminal move is emitted, on the merge into the integration branch or on a release merge. '
            .'It only ever WITHHOLDS a move: it can never select a card, authorize a stage, or move one the '
            .'writeback would otherwise have left alone. Correlation is untouched — the card is still '
            .'correlated and its PR refs are still stamped, so `bridge:reconcile` still sees it. Remove the '
            .'marker (and re-run `bridge:reconcile --fix`, or move the card by hand) if the PR does finish '
            .'the card after all. This is INDEPENDENT of the card-side pin (`block_reason` / `no-automove`, '
            .'DL-178 / card#8289): BOTH hold, either alone withholds the move, and neither can override the '
            .'other — the marker is read here at classify time, the pin at write time.';
    }
}
