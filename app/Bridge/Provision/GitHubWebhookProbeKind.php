<?php

namespace App\Bridge\Provision;

/**
 * The outcome of asking GitHub whether a repo still carries a webhook pointing at THIS
 * install's receiver — the discriminated result of {@see GitHubWebhookProbe::probe}
 * (card#9150).
 *
 * ⭐ THE CASES SPLIT ON ONE QUESTION AND IT IS NOT "IS THE HOOK THERE": it is *did this run
 * LOOK*. {@see self::Present} and {@see self::Absent} are the two answers a completed read
 * gives; the other three are a read that did not happen, and they exist as distinct cases
 * rather than as one `null` because each names a DIFFERENT thing for the operator to fix.
 * Collapsing any of the three into {@see self::Absent} would report a hook as GONE on
 * evidence that measured nothing — and the consumer's absent arm is a `fail` that moves
 * `bridge:check`'s exit code, so that collapse would red every install whose token cannot
 * enumerate hooks, a configuration this product permits (DL-037/DL-039 posture).
 */
enum GitHubWebhookProbeKind
{
    /** The hook list was read and a hook delivers to this install's receiver URL. */
    case Present;

    /**
     * The hook list was read TO THE END and no hook delivers to this install's receiver
     * URL. The only case that asserts a fault, and the only one earned by an exhausted
     * enumeration rather than by a first page.
     */
    case Absent;

    /** No GitHub token could be resolved for this repo: `$problem` is set. */
    case Unresolvable;

    /** The hook-list read got a non-2xx: `$status` + `$hint` + `$source` are set. */
    case Http;

    /**
     * Nothing was read: the request never completed (timeout/connection), or it answered
     * 200 with something that is not a hook list, or the pagination bound was reached.
     * `$reason` + `$source` are set.
     */
    case Unreadable;
}
