<?php

namespace App\Bridge\Writeback;

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
 * same drift hazard: which outcomes require an explicit CLOSING FORM in the PR title
 * before a card moves at all ({@see self::requiresClosure()}). Both paths must gate the
 * same set, or the reconciler would re-apply on a later pass exactly the move the event
 * path declined — so the set lives here beside the mapping it qualifies, not twice.
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
     * The outcomes this class produces — the MERGE outcomes, and the only ones whose
     * move asserts that a card's work is DONE (card#7348 / DL-305).
     *
     * @var list<string>
     */
    public const MERGE_OUTCOMES = ['merged', 'merged_to_main'];

    /** The move outcome for a MERGED pull request, from its base ref. */
    public static function forMergedBase(string $baseRef): string
    {
        return $baseRef === self::RELEASE_BASE ? 'merged_to_main' : 'merged';
    }

    /**
     * Does this outcome need an explicit CLOSING FORM in the PR title before a card
     * moves on it (card#7348 / DL-305)?
     *
     * TRUE FOR EXACTLY THE MERGE OUTCOMES, and the boundary is the claim each outcome
     * makes, not its severity. `merged` / `merged_to_main` are the two that say *this
     * card's work is shipped* — a proposition a PR that merely CITES a card never made,
     * and the one the release sweep then propagates into an irreversible stage. Every
     * other outcome describes the PR's own lifecycle and stays keyed on correlation
     * alone:
     *   - `started` is derived from a branch this install's tooling minted for that card,
     *     and it promotes FROM a narrow allowlist — a slug cannot carry a closing verb
     *     and gating it would make every branch-create inert;
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
}
