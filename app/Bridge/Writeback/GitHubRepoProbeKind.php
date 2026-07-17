<?php

namespace App\Bridge\Writeback;

/**
 * The outcome of resolving a GitHub read token for one repo and probing it — the
 * discriminated result of {@see GitHubRepoProbe::probe}. Each consumer maps the case
 * onto its own posture (bridge:reconcile: error + skip the repo's cards + set
 * hadError; bridge:check: warn, or stay silent on {@see self::Ok}/{@see self::Network}).
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
