<?php

namespace App\Bridge\Writeback;

/**
 * One leg's verdict in a {@see GitHubRepoAccess}: GitHub answered the probe, refused it in a way
 * that is a verdict on the token (a 403 that is not a rate limit, or a 404 where that means the token
 * cannot see the repo or lacks the scope), or did not answer it in a way that measures anything (a
 * 401, a rate limit, a 5xx, any other status, no answer) — never folded into either of the others.
 * `Wrote` is the write probe answered 2xx: GitHub says it accepted what the probe sends precisely
 * because it can never be accepted, which is a defect to report, not a measurement.
 */
enum GitHubAccess
{
    case Granted;
    case Refused;
    case Unmeasured;
    case Wrote;
}
