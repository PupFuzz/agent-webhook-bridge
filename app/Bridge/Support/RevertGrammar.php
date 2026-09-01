<?php

namespace App\Bridge\Support;

/**
 * THE REVERT grammar: the two artifacts GitHub mints when a merged PR is reverted, and
 * the one ruling both of them carry — a revert UNDOES work, so nothing it inherited
 * from the original may close a card (card#8306).
 *
 * WHY THIS CLASS EXISTS, and the measurement it comes from. GitHub composes a revert
 * PR's title by QUOTING the original's verbatim and its head branch by WRAPPING the
 * original's ref. Both were read at source rather than assumed — no revert PR exists on
 * any of this shop's four repos (1,607 PRs scanned, zero), so the shapes are observed on
 * public repos where GitHub minted them:
 *
 *   laravel/framework#61330  title `Revert "[13.x] Prevent loose comparison bypass …"`
 *                            head  `revert-61320-fix/contains-strict-comparison`
 *                            (original #61320: title `[13.x] Prevent loose comparison
 *                            bypass …`, head `fix/contains-strict-comparison`)
 *   laravel/framework#61262  head  `revert-61245` — the suffix is DROPPED when the
 *                            original's branch is gone, so the ref shape is
 *                            `revert-<original PR number>` with an OPTIONAL tail.
 *
 * THE HAZARD A CHANGE OF OURS MINTED. card#8294 made PR titles carry `(closes card#N)`.
 * Every revert title therefore inherits a closing form, and {@see ClosureGrammar} read
 * the quoted text exactly as it reads an author's own — so merging a revert moved the
 * reverted card FORWARD, asserting that work landed when it had just been undone. The
 * board then says the opposite of the truth. `pr-title-lint` already exempts `revert-*`
 * branches, but that governs what CI DEMANDS; the writeback reads the title GitHub wrote
 * by itself, and only the first layer was covered.
 *
 * ⛔ BOTH ROUTES WERE LIVE, which is why this class answers about two surfaces rather
 * than one. Measured end-to-end through the classifier before the fix: the LEXICAL route
 * fires on the quoted closing form, and the STRUCTURAL route
 * ({@see PrOutcome::mergeClosesCard()}) fires whenever the original's branch carried a
 * {@see CardTokenGrammar} token — `revert-611-card-8294-slug` parses to card 8294 —
 * which is the spelling `board-card-start` mints and was this shop's house convention
 * until 2026-08-29. Repairing only the quoted title would have left the hazard half-open
 * on every install still on that convention.
 *
 * WHAT IS *NOT* REFUSED: CORRELATION. `revert-611-card-8294-slug` still NAMES card 8294
 * and must — the revert IS about that card, and the `opened` outcome, the PR-ref stamps
 * and the draft overlay all depend on it. Only the two predicates that AUTHORIZE a
 * completion claim consult this class. That split is the whole design: the revert is
 * correlated exactly as before and merely stops asserting that the work is done.
 *
 * ⛔ NESTING RE-APPLIES THE WORK AND STILL DOES NOT CLOSE — ruled, not left to fall out
 * of a regex. A revert of a revert (`Revert "Revert "…""`, branch
 * `revert-612-revert-611-…`) nets out to re-applying the original, so an argument exists
 * that it SHOULD close. It does not, for three reasons. (1) GitHub does not escape the
 * inner quotes, so depth is not reliably parseable from the title at all — a parity
 * count over an ambiguous parse would be authorizing a TERMINAL, irreversible stage move
 * on a guess, which is the trade DL-308 refused for `refCorroborates()`. (2) The cost is
 * near zero: the FIRST revert never moved the card back (a backward move was considered
 * and declined — DL-305's no-demotion ruling), so the card is still where the original
 * merge left it and the re-apply has nothing to promote. (3) Under-promotion is
 * recoverable by hand and is LOUD (the withheld-merge warning fires, rendering
 * {@see self::describeRefusal()}); the wrong terminal move is neither.
 *
 * THE ESCAPE HATCH IS POSITIONAL, and no new vocabulary is minted for it. A revert that
 * genuinely completes a card closes it by putting the closing form OUTSIDE the quoted
 * original: `Revert "feat: x (closes card#123)" (closes card#456)` closes 456 and not 123
 * at this layer. That is the existing grammar used as-is, so an author learns nothing new,
 * and it cannot fire by accident because GitHub never writes outside the quotes. ⛔ A
 * marker meaning *close anyway* was deliberately NOT invented: card#8294's `[no-close]` is
 * a CI-only suppression that nothing at runtime reads, and its inverse would be the first
 * title DIRECTIVE the writeback obeys — a new accept-surface, and its own decision.
 *
 * ⚠ A KNOWN DIVERGENCE FROM CI, filed rather than repaired here — the same shape
 * {@see PrOutcome::mergeClosesCard()}'s docblock already records for the branch predicate.
 * `.github/workflows/pr-title-lint.yml` answers *"is this a revert"* by exempting the
 * `revert-*` BRANCH; this class also reads the TITLE. So a hand-made revert (a `git revert`
 * pushed to an ordinary branch) whose title carries the quoted closing form **passes** the
 * CI closure gate and is **refused** at runtime. The direction is under-promotion — the
 * PR merges green, the withheld-merge warning names the reason, and the card is moved by
 * hand — which is the recoverable side; teaching the lint the title shape would change
 * what a CI gate accepts and is its own decision, exactly as the branch-predicate
 * divergence was.
 *
 * ⚠ THE HATCH'S REACH IS BOUNDED BY CORRELATION, disclosed here rather than discovered by
 * the first author who tries it. Closure only ever FILTERS the cards correlation already
 * selected, and on a GitHub-minted revert the head ref is authoritative and names the
 * REVERTED card — so on `revert-<n>-card-123-slug` the hatch can re-close card 123
 * deliberately, and it cannot redirect the move to card 456: nothing in a title outranks
 * the branch (card#5287 / DL-270). Closing a DIFFERENT card from a revert therefore needs
 * a branch that names it, which is the same rule every other PR in this shop already
 * follows and is not special-cased here.
 */
final class RevertGrammar
{
    /**
     * The QUOTED span: the `Revert "…"` wrapper GitHub writes around the original title,
     * and everything inside it.
     *
     * GREEDY TO THE LAST QUOTE, deliberately — that is what makes nesting fall inside
     * one span rather than needing a balanced parse GitHub's unescaped inner quotes make
     * impossible. The `.*$` alternative catches an UNTERMINATED wrapper (a title
     * truncated after the opening quote): without it that shape would match nothing and
     * the inherited closing form would survive, which is the one direction this must
     * never fail in. `/s` for the same reason — a newline must not end the span early.
     *
     * The leading `\b` keeps `unrevert "x"` out, mirroring {@see ClosureGrammar}'s own
     * boundary rule; `/i` mirrors every grammar in this package.
     */
    private const QUOTED_TITLE = '/\brevert\s+"(?:.*"|.*$)/is';

    /**
     * The head ref GitHub mints: `revert-<original PR number>`, with the original's own
     * ref appended when it still exists.
     *
     * ANCHORED, AND THE DIGITS ARE REQUIRED. `^revert-\d+` is what separates GitHub's
     * artifact from a human branch that merely starts with the word: a card branch named
     * `revert/8306-back-out-the-thing` (the `<type>/<id>-slug` house convention, whose
     * type segment ends in a SLASH) and `revert-the-streaming-change` both fail this and
     * keep whatever closure they had. The trailing `(?:-|$)` stops `revert-1x` matching.
     */
    private const REVERT_REF = '/^revert-\d+(?:-|$)/i';

    /**
     * The text with GitHub's quoted revert wrapper REMOVED — what
     * {@see ClosureGrammar} reads a closing form out of.
     *
     * A QUOTATION IS NOT AN ASSERTION, which is the entire ruling and the reason it is
     * expressed as a subtraction rather than as a veto over the whole title. Text inside
     * the wrapper is the ORIGINAL author's claim about work this PR undoes; text outside
     * it is this author's own and is read exactly as it always was. A title carrying no
     * wrapper is returned byte-identical, so every non-revert PR — effectively all of
     * them — is untouched by construction.
     *
     * ⚠ ONE DISCLOSED COST of the greedy span: a title with a SECOND quoted phrase after
     * the wrapper (`Revert "A" (closes card#1) "B"`) swallows the closing form between
     * the quotes. Contrived, and it fails toward under-promotion, which is the direction
     * this predicate is allowed to be wrong in.
     */
    public static function withoutQuotedRevert(string $text): string
    {
        return (string) preg_replace(self::QUOTED_TITLE, ' ', $text);
    }

    /** Is this text GitHub's `Revert "…"` wrapper around another PR's title? */
    public static function quotesRevertedTitle(string $text): bool
    {
        return preg_match(self::QUOTED_TITLE, $text) === 1;
    }

    /**
     * Is this head ref GitHub's `revert-<n>[-<original ref>]` wrapper around another
     * PR's branch?
     *
     * WHY THE STRUCTURAL ROUTE MUST ASK. DL-308's argument for reading the head ref as a
     * completion claim is that the ref is minted by THIS INSTALL's own tooling
     * (`board-card-start` → `card-<id>-slug`) and so carries the branch's IDENTITY. A
     * `revert-<n>-` ref is minted by GITHUB and carries a COPY of the original's
     * identity; the branch it names is not the card's work branch, it is a wrapper
     * around the name of one. The premise fails, so the conclusion may not be drawn.
     */
    public static function isRevertRef(string $headRef): bool
    {
        return preg_match(self::REVERT_REF, $headRef) === 1;
    }

    /** Does this PR carry a revert marker on EITHER surface GitHub mints one on? */
    public static function isRevert(string $title, string $headRef): bool
    {
        return self::quotesRevertedTitle($title) || self::isRevertRef($headRef);
    }

    /**
     * The operator-facing sentence for a move this class withheld — DERIVED nowhere,
     * because it states a ruling rather than an accept-set, but OWNED here so the
     * classifier's withheld-merge warning and `bridge:reconcile`'s skip line render it
     * instead of each spelling it (the DL-239 discipline).
     *
     * IT EXISTS BECAUSE THIS CHANGE MADE THE OTHER SENTENCE FALSE. Both surfaces told the
     * operator that *the head branch ref does not name the card and the title carries no
     * closing form naming it* — which on a revert is untrue on both counts: the ref
     * usually does name it and the quoted title usually does close it. An operator
     * reading that line would go and rewrite prose that is already correct.
     */
    public static function describeRefusal(): string
    {
        return 'This PR is a REVERT. GitHub composes a revert by QUOTING the original PR\'s title '
            .'(`Revert "…"`) and WRAPPING its branch (`revert-<n>-<original ref>`), so a closing form '
            .'inside those quotes, and a card token inside that ref, are the ORIGINAL\'s — they name work '
            .'this PR UNDOES. Neither closes a card (card#8306), and a revert of a revert does not either. '
            .'The card is left where it is, never moved back. To close a card ON a revert, put the closing '
            .'form OUTSIDE the quoted title.';
    }
}
