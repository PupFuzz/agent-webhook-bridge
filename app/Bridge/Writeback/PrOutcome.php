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
 */
final class PrOutcome
{
    /**
     * The release base ref: a PR merged INTO it means the card is "released"
     * (merged_to_main); a merge into any other base (the integration branch) means
     * "shipped" (merged). Mirrors the constant the writeback classifier keys on.
     */
    public const RELEASE_BASE = 'main';

    /** The move outcome for a MERGED pull request, from its base ref. */
    public static function forMergedBase(string $baseRef): string
    {
        return $baseRef === self::RELEASE_BASE ? 'merged_to_main' : 'merged';
    }
}
