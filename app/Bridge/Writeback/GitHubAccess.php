<?php

namespace App\Bridge\Writeback;

/**
 * One leg's verdict in a {@see GitHubRepoAccess}: GitHub answered the probe, refused it in a way
 * that is a verdict on the token (a 403 that is not a rate limit, or a 404 where that means the token
 * cannot see the repo or lacks the scope), or did not answer it in a way that measures anything (a
 * 401, a rate limit, a 5xx, any other status, no answer) — never folded into either of the others.
 * `RefusedNotPermission` is a read refused with a 403 that is not GitHub's permission refusal (SSO
 * authorization, an IP allow list): refused, but no permission would fix it. `BelowRole` is a write the token's ACCOUNT cannot make for want of a repo role, INFERRED from the
 * repo read's `permissions` — no request was refused. `Unprobeable` is a write the repo gives the
 * probe nothing to measure on — no unlocked thread of the kind, or that kind disabled (410) — which a
 * re-run does not change. `Wrote` is the write probe answered 2xx: GitHub says it accepted what the probe sends precisely
 * because it can never be accepted, which is a defect to report, not a measurement.
 */
enum GitHubAccess
{
    case Granted;
    case Refused;
    case RefusedNotPermission;
    case Unmeasured;
    case BelowRole;
    case Unprobeable;
    case Wrote;
}
