<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClosureGrammar;
use App\Bridge\Support\RevertGrammar;

/**
 * The single authority for the drift-prone "which merge stage" decision shared by
 * the event-driven correlation classifier (GitHubPrCardMoveClassifier) and the
 * reconciler (bridge:reconcile). Both must derive the SAME outcome from a merged
 * PR's base ref, or a card would settle to different stages depending on which
 * path last touched it. The classifier drives off the webhook action; the
 * reconciler off the REST PR state — but the merged→stage mapping (the subtle
 * part) lives here so it can't drift between them.
 *
 * Since card#7348 / DL-305 it owns a SECOND question with the same two consumers and the
 * same drift hazard: which outcomes require CLOSURE EVIDENCE
 * before a card moves at all ({@see self::requiresClosure()}). Both paths must gate the
 * same set, or the reconciler would re-apply on a later pass exactly the move the event
 * path declined — so the set lives here beside the mapping it qualifies, not twice.
 *
 * And since card#7348 / DL-308, the THIRD question the same two consumers ask about the
 * same event: what, other than the title's prose, can satisfy that gate
 * ({@see self::mergeClosesCard()}). It lives here for the reason the other two do — a
 * second copy in the reconciler would let the backstop and the event path disagree about
 * which merges close a card — and it lives here rather than in {@see ClosureGrammar}
 * because it is not grammar: it reads no prose. It is a fact about the MERGE.
 */
final class PrOutcome
{
    /**
     * The release base ref: a PR merged INTO it means the card is "released"
     * (merged_to_main); a merge into any other base (the integration branch) means
     * "shipped" (merged). Mirrors the constant the writeback classifier keys on.
     */
    public const RELEASE_BASE = 'main';

    /**
     * A merge into the INTEGRATION branch — any base that is not {@see self::RELEASE_BASE}.
     * Named rather than spelled inline because DL-308 gave the distinction a second job:
     * it is no longer only which stage a merge lands in, it is also which merges can close
     * a card structurally ({@see self::mergeClosesCard()}).
     */
    public const INTEGRATION_MERGE = 'merged';

    /** A merge into {@see self::RELEASE_BASE}. */
    public const RELEASE_MERGE = 'merged_to_main';

    /**
     * The outcomes this class produces — the MERGE outcomes, and the only ones whose
     * move asserts that a card's work is DONE (card#7348 / DL-305).
     *
     * @var list<string>
     */
    public const MERGE_OUTCOMES = [self::INTEGRATION_MERGE, self::RELEASE_MERGE];

    /** The move outcome for a MERGED pull request, from its base ref. */
    public static function forMergedBase(string $baseRef): string
    {
        return $baseRef === self::RELEASE_BASE ? self::RELEASE_MERGE : self::INTEGRATION_MERGE;
    }

    /**
     * Does this outcome need CLOSURE EVIDENCE before a card moves on it (card#7348 /
     * DL-305)? WHICH evidence satisfies it is a separate question with a separate owner —
     * {@see self::mergeClosesCard()} for the structural route, {@see ClosureGrammar} for
     * the lexical one, {@see self::describeClosure()} for the sentence that names both.
     * This answers only WHERE the requirement applies, and DL-308 did not move that line.
     *
     * TRUE FOR EXACTLY THE MERGE OUTCOMES, and the boundary is the claim each outcome
     * makes, not its severity. `merged` / `merged_to_main` are the two that say *this
     * card's work is shipped* — a proposition a PR that merely CITES a card never made,
     * and the one the release sweep then propagates into an irreversible stage. Every
     * other outcome describes the PR's own lifecycle and stays keyed on correlation
     * alone:
     *   - `started` is derived from a branch this install's tooling minted for that card,
     *     and it promotes FROM a narrow allowlist — there is no merge for the structural
     *     route to read and a slug cannot carry a closing verb, so gating it would make
     *     every branch-create inert;
     *   - `opened` says a PR exists, is reversible, and is what STAMPS the card's PR refs
     *     so the reconciler can find it later;
     *   - `closed_unmerged` is an abandon disposition, revivable by DL-195, and
     *     withholding it would strand a card in In-Review after its PR was abandoned.
     *
     * ⛔ NOT EXTENDED TO THE RELEASE-PROMOTE SWEEP (DL-207), deliberately. That leg asks
     * whether a card ALREADY in the shipped stage has its commit on `main` — a question
     * about a transition some earlier decision already made, not a fresh completion
     * claim. The gate belongs where the claim is first made, which is here.
     */
    public static function requiresClosure(string $outcome): bool
    {
        return in_array($outcome, self::MERGE_OUTCOMES, true);
    }

    /**
     * Does the MERGE ITSELF close this card — with no closing form in the title at all
     * (card#7348 / DL-308)? The SECOND route through the DL-305 gate, and the one that
     * makes the gate reachable for the convention this fleet actually writes.
     *
     * WHY A SECOND ROUTE EXISTS, measured rather than argued. DL-305 shipped a title-only
     * accept-set, and every real merged PR title in this shop returns no-move against it:
     * the house convention is `type(scope): summary (card#N)` and has never carried a
     * closing verb, so 0 of 351 correlated merged PRs across four repos closed anything.
     * Only an artificial `Closes card#N` control passed. A gate nothing satisfies does not
     * protect a board, it FREEZES one — quietly, since CI is green and the merge succeeds
     * and only the card stands still. Widening the accept-set with a structural term is the
     * ruling of roundtable #343, taken back to the peer whose property it re-opens.
     *
     * THE PROPERTY IT PRESERVES, which is the whole reason it is acceptable: *no token that
     * merely APPEARS may move a stage.* A closing verb is one way to corroborate the token;
     * this is the other, and it is not prose at all — it is two facts about the merge that
     * a citation cannot manufacture:
     *
     *  1. THE HEAD BRANCH'S OWN REF NAMES THE CARD. The ref is minted by this install's own
     *     tooling (`board-card-start` → `card-<id>-slug`), which is why
     *     {@see GitHubPrCardMoveClassifier::cardTokenResolution()} already treats it as
     *     AUTHORITATIVE over the title everywhere else. Quoting someone else's card id in
     *     your title does not rename your branch — that is exactly the peer incident this
     *     card was filed for, and it still cannot pass here.
     *  2. THE PR MERGED INTO THE INTEGRATION BRANCH. `merged-to-integration` is the
     *     proposition the Shipped stage asserts; it is not a claim read off text, it is
     *     what GitHub did.
     *
     * ⛔ RELEASE MERGES ARE NOT WIDENED. Only {@see self::INTEGRATION_MERGE} takes this
     * route: a release PR's head is a disposable `release/vX` branch that names no card,
     * so the term would never fire there anyway — but stating it as a condition is what
     * stops a future release convention that DID name a card from silently acquiring a
     * terminal-stage move nobody approved. `merged_to_main` still needs a closing form,
     * and the promote sweep (DL-207) still owns the Shipped→Released transition.
     *
     * ⛔ THE STRICT CARD-TOKEN READING, and the cost of it, disclosed rather than
     * discovered. The ref must carry a {@see CardTokenGrammar} token — the same authority
     * that decides what names a card everywhere else in this codebase, so no second
     * accept-set is minted here. It deliberately does NOT reuse the classifier's
     * {@see GitHubPrCardMoveClassifier::refCorroborates()} bare-id test, whose own docblock
     * ACCEPTS an accidental-collision bound (`chore/bump-1-2-3` corroborating `card#2`) on
     * the stated grounds that it "only ever RELAXES a guard ... and never widens what can
     * be selected". That justification does not survive being moved here, where the
     * predicate AUTHORIZES A TERMINAL-STAGE MOVE. The measured price of the strict reading
     * is the legacy `<type>/<id>-slug` branch spelling, which carries the id but no token.
     * That price was measured at 1 of 59 recent merged PRs (55 → 56 with the loose reading,
     * against a ceiling of 56) while the house convention was `card-<id>-slug`; the
     * convention has since flipped TO `<type>/<id>-slug`, which took the same reading to 8
     * of 8 (card#8294). ⚠ Read that figure as a measurement of a convention, not a property
     * of this predicate — nothing here changed. The misses are LOUD (the withheld-merge
     * warning fires) and the lexical `Closes card#N` route is still open; under-promotion is
     * recoverable by hand, the terminal move is not. What the incident actually exposed is
     * that the dependency was declared nowhere and checked nowhere: `CLAUDE_CONVENTIONS.md`
     * § *PR titles* now states it and `.github/workflows/pr-title-lint.yml` now reds a PR
     * that correlates a card and closes it on neither route.
     *
     * ⚠ A KNOWN DIVERGENCE, filed rather than repaired here: `.github/workflows/pr-title-lint.yml`
     * answers "does this branch name a card id" with its own wider predicate
     * (`^([a-z-]+/)?(card-?)?([0-9]+)-`, card#6822), so a `fix/6100-slug` branch is a
     * card-id branch to CI and is not one to this gate. Two answers to one question is the
     * drift shape this repo keeps paying for; consolidating them is a change to what a CI
     * gate accepts and belongs to its own decision, not to this one.
     *
     * ⛔ A REVERT TAKES NO STRUCTURAL ROUTE (card#8306), and it is this predicate's own
     * premise that refuses it rather than a special case bolted on. Everything above rests
     * on the ref being the CARD's branch, carrying the card's work. A revert's branch
     * carries the card's work UNDONE, so the same ref means the opposite thing, and reading
     * it as a completion claim moves a card FORWARD for work that was just taken out.
     *
     * IT ASKS {@see RevertGrammar::isRevert()} — BOTH SURFACES, not the ref alone, and the
     * ref alone was measured INSUFFICIENT before this line was written this way. GitHub's
     * own revert wraps the ref (`revert-<n>-<original ref>`), so a ref-only test catches
     * GitHub's mint — but a HAND-MADE revert (`git revert`, pushed to an ordinary branch)
     * announces itself only in the TITLE, and its branch is whatever the author typed.
     * Measured on the shipped predicates, card 4811, title `Revert "feat: widget (closes
     * card#4811)"`: `revert-611-card-4811-widget` was refused, while `card-4811-widget`,
     * `revert/card-4811-widget` and `revert-card-4811-widget` all still CLOSED the card —
     * `isRevert()` returning true on every one of them, consulted by nothing. And
     * `card-<id>-slug` is the spelling `board-card-start` mints, so that was the COMMON
     * branch shape, not an exotic one. Worse, it was SILENT: the card reached the closing
     * set, so the withheld-merge warning never fired. Asking both surfaces here is what
     * makes "a revert closes nothing on either route" true rather than nearly true.
     *
     * THE FALSE-NEGATIVE IT BUYS, priced rather than asserted away: a PR whose title merely
     * QUOTES the word revert (`fix: revert "the streaming change" and re-land it`) now
     * loses its structural route. That is the identical over-refusal the lexical route
     * already accepts by construction, it fails toward under-promotion, and it is LOUD —
     * the card lands in the withheld set, so the warning fires and names the revert as the
     * reason. Priced at zero on real data: `revert` + whitespace + `"` appears in
     * 0 of the 1,566 merged PR titles across all three repos this shop owns — measured here, with a control planted into that same corpus to prove the predicate discriminates.
     *
     * @param  string  $title  REQUIRED, deliberately not defaulted: a caller that forgets it
     *                         must fail to compile rather than silently keep the ref-only
     *                         reading this clause exists to replace.
     */
    public static function mergeClosesCard(string $outcome, string $headRef, int $cardId, string $title): bool
    {
        return $outcome === self::INTEGRATION_MERGE
            && ! RevertGrammar::isRevert($title, $headRef)
            && CardTokenGrammar::parse($headRef) === $cardId;
    }

    /**
     * The operator-facing sentence for what makes a merge move a card — BOTH routes,
     * DERIVED (card#7348 / DL-308).
     *
     * It exists because DL-305 left two live surfaces (the withheld-merge warning and
     * `bridge:check`'s per-mapping line) rendering {@see ClosureGrammar::describe()} alone,
     * under a sentence asserting the title is the only thing that can move a card. That
     * sentence went FALSE the moment a second route landed — an operator reading it would
     * conclude a card that moved without a closing verb had moved in violation of the
     * documented rule. Composing the two halves HERE, where the structural half is decided,
     * is the DL-239 discipline applied to a predicate that spans two authorities: the
     * grammar renders its own accept-set, {@see CardTokenGrammar} renders the branch-ref
     * spellings, and neither is retyped.
     *
     * TWO FLAVOURS, because the two surfaces ask different questions and DL-305 had already
     * split them before this composed anything: `bridge:check` speaks at SETUP time, where
     * the operator wants to know what DOES close a card and the rejected shapes are noise
     * ({@see self::describeClosureAccepted()}); the withheld-merge warning speaks about a
     * specific PR that just failed the gate, where the rejected side is the diagnosis. Both
     * are derived, so neither can drift from the other or from the code.
     */
    public static function describeClosure(): string
    {
        return self::structuralClause().' ('.CardTokenGrammar::describe()
            .'), or a closing form in the PR TITLE naming it ('.ClosureGrammar::describe().')';
    }

    /** {@see self::describeClosure()}, accepted spellings only — the setup-time flavour. */
    public static function describeClosureAccepted(): string
    {
        return self::structuralClause().' ('.implode(', ', CardTokenGrammar::accepted())
            .'), or a closing form in the PR TITLE naming it ('.implode(', ', ClosureGrammar::accepted()).')';
    }

    /** The half neither grammar can render: what the MERGE itself must be. */
    private static function structuralClause(): string
    {
        return 'a merge into the integration branch (any base but `'.self::RELEASE_BASE
            .'`) whose HEAD BRANCH REF itself names the card';
    }
}
