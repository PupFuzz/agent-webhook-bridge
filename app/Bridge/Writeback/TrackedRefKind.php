<?php

namespace App\Bridge\Writeback;

/**
 * How a card's payload references a PR — the discriminated result of
 * {@see TrackedCardRef::fromPayload}. The caller maps each case onto its own behavior
 * (reconcile: move/skip + a log line; promote-on-release: include/skip a candidate).
 */
enum TrackedRefKind
{
    /** Repo-qualified `pr_url`: canonRepo + prNumber + prUrl are set. */
    case PrUrl;
    /** Bare `pr_number` on a 1:1 board: prNumber is set (repo is the sole board mapping). */
    case PrNumber;
    /** Bare `pr_number` on a board shared by >1 repo: not attributable to a repo. */
    case Ambiguous;
    /** A `dl_number` with no PR reference: out of the writeback's PR-driven scope. */
    case DlOnly;
    /** No PR/DL reference: not a tracked card. */
    case None;
}
