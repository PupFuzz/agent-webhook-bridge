<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ClosureGrammar;
use App\Bridge\Support\NoCloseGrammar;

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
 * And since card#10850 / DL-436, a THIRD question with the same two consumers: which outcomes
 * move a card AT ALL ({@see self::movesCard()}). `closed_unmerged` stopped moving one, and the
 * reconciler re-derives the same outcome from REST state, so the answer lives here or the
 * backstop would re-plan the move the event path stopped emitting.
 *
 * ⛔ WHAT CAN SATISFY THE CLOSURE GATE IS NOT DECIDED HERE ANY MORE. From card#7348 / DL-308 to
 * card#10850 / DL-436 this class also owned a STRUCTURAL route — a merge into the integration
 * branch from a head branch naming the card closed it with no closing form — and that route is
 * RETIRED: a multi-PR card shipped at its FIRST merged PR, because the branch that names a card
 * is also the branch every partial leg of it is built on. {@see ClosureGrammar} is the one
 * authority for closure evidence; {@see self::describeClosure()} renders it for the surfaces
 * that print the accept-set.
 */
final class PrOutcome
{
    /**
     * The release base ref: a PR merged INTO it means the card is "released"
     * (merged_to_main); a merge into any other base (the integration branch) means
     * "shipped" (merged). Mirrors the constant the writeback classifier keys on.
     */
    public const RELEASE_BASE = 'main';

    /** A merge into the INTEGRATION branch — any base that is not {@see self::RELEASE_BASE}. */
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

    /** A pull request closed without merging. {@see self::movesCard()} is false for it. */
    public const CLOSED_UNMERGED = 'closed_unmerged';

    /** The move outcome for a MERGED pull request, from its base ref. */
    public static function forMergedBase(string $baseRef): string
    {
        return $baseRef === self::RELEASE_BASE ? self::RELEASE_MERGE : self::INTEGRATION_MERGE;
    }

    /**
     * Does this outcome need CLOSURE EVIDENCE before a card moves on it (card#7348 /
     * DL-305)? WHICH evidence satisfies it is {@see ClosureGrammar}'s to say — a closing
     * form in the PR title naming the card, the only route since card#10850 / DL-436 retired
     * DL-308's structural one — and {@see self::describeClosure()} renders it. This answers
     * only WHERE the requirement applies.
     *
     * TRUE FOR EXACTLY THE MERGE OUTCOMES, and the boundary is the claim each outcome
     * makes, not its severity. `merged` / `merged_to_main` are the two that say *this
     * card's work is shipped* — a proposition a PR that merely CITES a card never made,
     * and the one the release sweep then propagates into an irreversible stage. Every
     * other outcome describes the PR's own lifecycle and stays keyed on correlation
     * alone:
     *   - `started` is derived from a branch this install's tooling minted for that card,
     *     and it promotes FROM a narrow allowlist — a slug cannot carry a closing verb, so
     *     gating it would make every branch-create inert;
     *   - `opened` says a PR exists, is reversible, and is what STAMPS the card's PR refs
     *     so the reconciler can find it later;
     *   - `closed_unmerged` moves nothing at all since card#10850 / DL-436
     *     ({@see self::movesCard()}), so there is no move for a gate to withhold.
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
     * Does this outcome move a card at all (card#10850 / DL-436)? False for
     * {@see self::CLOSED_UNMERGED} alone.
     *
     * A pull request closed without merging says nothing about whether the card's work is
     * still wanted: it was abandoned, superseded by another pull request, or closed to be
     * re-cut. Moving the card to the mapped `closed_unmerged` stage — a Won't Do column
     * wherever the defect was reported — declined requirements nobody had declined, so the
     * card is left where it is instead. Both consumers ask it, for the reason {@see self::requiresClosure()}
     * lives here: the classifier emits no move for it, and `bridge:reconcile` plans none, so
     * the backstop cannot re-apply the move the event path stopped emitting.
     *
     * `stages.closed_unmerged` in `writeback.json` still parses: it names the abandon stage
     * DL-195's opt-in revival scopes from, which is all it does now.
     */
    public static function movesCard(string $outcome): bool
    {
        return $outcome !== self::CLOSED_UNMERGED;
    }

    /**
     * The operator-facing sentence for what makes a merge move a card — DERIVED
     * (card#7348 / DL-305; one route again since card#10850 / DL-436).
     *
     * Composed HERE, not at the two surfaces that print it (the withheld-merge warning and
     * `bridge:check`'s per-mapping line), so neither restates an accept-set: the grammar
     * renders its own spellings, and the `[no-close]` condition is appended in one place
     * (the DL-239 discipline). Until DL-436 it also rendered DL-308's structural route; a
     * sentence still naming it would tell an author their branch name closes a card when it
     * does not.
     *
     * TWO FLAVOURS, because the two surfaces ask different questions: `bridge:check` speaks
     * at SETUP time, where the operator wants to know what DOES close a card and the
     * rejected shapes are noise ({@see self::describeClosureAccepted()}); the withheld-merge
     * warning speaks about a specific PR that just failed the gate, where the rejected side
     * is the diagnosis.
     */
    public static function describeClosure(): string
    {
        return self::lexicalClause().' ('.ClosureGrammar::describe().')'.self::noCloseClause();
    }

    /** {@see self::describeClosure()}, accepted spellings only — the setup-time flavour. */
    public static function describeClosureAccepted(): string
    {
        return self::lexicalClause().' ('.implode(', ', ClosureGrammar::accepted()).')'.self::noCloseClause();
    }

    /** What the merge must carry — and, since DL-436, what it need not: the head branch is not read. */
    private static function lexicalClause(): string
    {
        return 'a closing form in the PR TITLE naming the card — the head branch ref is not closure evidence, even when it names the card';
    }

    /**
     * The SUBTRACTIVE half of both sentences (card#8344 / DL-327) — and it belongs in both
     * flavours, unlike the rejected-spelling lists the two are split over.
     *
     * A rejected SPELLING is diagnosis: an operator at setup time does not need to be told
     * that `Related to card#123` closes nothing. `[no-close]` is not a spelling that fails
     * the accept-set, it is a CONDITION that empties it — a title carrying a closing form
     * still moves no card while it is present. A sentence that omitted it would be FALSE
     * about a PR the author deliberately marked, on the exact surface (`bridge:check`, the
     * withheld-merge warning) an operator consults to find out why nothing moved. It renders
     * {@see NoCloseGrammar::MARKER} rather than spelling the literal, for the reason every
     * other clause here is derived.
     */
    private static function noCloseClause(): string
    {
        return ' — unless the PR TITLE carries the literal `'.NoCloseGrammar::MARKER
            .'`, the author\'s declaration that this PR CITES the card rather than finishing it,'
            .' which withholds the move';
    }
}
