<?php

namespace App\Bridge\Writeback;

/** What a card's stored `pr_url` names, relative to this event's repo — {@see StoredPrRef}'s url axis. */
enum StoredPrUrlKind
{
    /** No `pr_url` (absent or empty). */
    case None;
    /** A URL naming THIS event's pull request — its repo and its number ({@see TrackedCardRef::namesPr}). */
    case NamesThisPr;
    /**
     * A URL naming a real pull request that is not this event's: another number, or the same
     * number in another repo. The only case that is "a different pull request".
     */
    case NamesOtherPr;
    /** This repo's `.../pull/0` source-only placeholder: a stamp for this repo may replace it. */
    case PlaceholderThisRepo;
    /** Another repo's `.../pull/0` placeholder: it names that repo and no pull request, and is never replaced. */
    case PlaceholderOtherRepo;
    /** A value that is not a GitHub pull-request URL at all (an operator's free text). */
    case NotAPrUrl;
}
