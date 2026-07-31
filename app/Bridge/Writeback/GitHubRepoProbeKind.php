<?php

namespace App\Bridge\Writeback;

/**
 * The outcome of resolving a GitHub read token for one repo and probing it — the
 * discriminated result of {@see GitHubRepoProbe::probe}. Each consumer maps the case
 * onto its own posture (bridge:reconcile: error + skip the repo's cards + set
 * hadError; bridge:check: `warn` on a token problem, `unvalidated` on {@see self::Network}
 * — the probe did not complete, so nothing was learned about the token — and silent only
 * on {@see self::Ok}).
 */
enum GitHubRepoProbeKind
{
    /** Token resolved and the repo is readable: {@see GitHubRepoProbeResult::$client} is set. */
    case Ok;
    /** No token could be resolved (GitHubTokenResolver problem): {@see GitHubRepoProbeResult::$problem} is set. */
    case Unresolvable;
    /** The probe got a non-2xx: {@see GitHubRepoProbeResult::$status} + `$hint` + `$source` are set. */
    case Http;
    /** The probe could not reach GitHub (timeout/connection): `$networkMessage` + `$source` are set. */
    case Network;
}
