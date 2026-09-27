<?php

namespace App\Bridge\Writeback;

/**
 * How a card's payload references a PR — the discriminated result of
 * {@see TrackedCardRef::fromPayload}. The caller maps each case onto its own behavior
 * (reconcile: move/skip + a log line; promote-on-release: include/skip a candidate).
 */
enum TrackedRefKind
{
    /** Repo-qualified `pr_url`: canonRepo + prNumber + prUrl are set. The only kind that names a pull request. */
    case PrUrl;
    /**
     * A bare `pr_number` with no `pr_url` naming a real pull request: prNumber is set, the repo
     * is unknown on EVERY board (DL-429). Not attributable, so not correlatable.
     */
    case BarePrNumber;
    /** A `dl_number` with no PR reference: out of the writeback's PR-driven scope. */
    case DlOnly;
    /** No PR/DL reference: not a tracked card. */
    case None;
}
