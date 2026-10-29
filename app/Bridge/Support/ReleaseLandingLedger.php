<?php

namespace App\Bridge\Support;

use App\Bridge\CiAwait\CiAwaitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which delivery carries a release landing to an agent (card#12576): one row per (agent, repo,
 * landed commit) in `release_landings`, naming the event SOURCE that claimed it.
 *
 * One merge into the release branch reaches the bridge as two deliveries — the `push` to the
 * branch and the merged `pull_request.closed` — whose order GitHub does not fix, and whose
 * landed commit is the same (the push's `after` is the PR's `merge_commit_sha`). The first one
 * classified for an agent claims the landing; the other, classified later or concurrently,
 * reads the claim and wakes nobody. The claim is an `insertOrIgnore` against a unique key, so
 * two concurrent deliveries cannot both win.
 *
 * Keyed on the SOURCE, not on the delivery, so the answer is stable under replay: replaying the
 * delivery that won wakes again, as replay is asked to; replaying the one that lost does not.
 * A second push of an already-landed commit is the same source as the claim and wakes, as every
 * release-branch push did before this ledger.
 *
 * ⚑ A CLAIM THAT CANNOT BE READ OR WRITTEN WAKES. The table is new in this release, and an
 * update serves the new code before `migrate` has run; a landing must never be lost to that
 * window, so a fault here is logged and answered as "this delivery carries it" — at worst one
 * merge wakes twice.
 */
final class ReleaseLandingLedger
{
    public const TABLE = 'release_landings';

    /** True when `$source` carries this landing to `$agent`: it claimed it now, or claimed it before. */
    public static function carries(string $agent, string $repo, string $headSha, string $source): bool
    {
        $key = ['agent' => $agent, 'repo' => CiAwaitService::key($repo), 'head_sha' => $headSha];
        try {
            DB::table(self::TABLE)->insertOrIgnore([$key + ['source' => $source, 'created_at' => now()]]);
            $winner = DB::table(self::TABLE)->where($key)->value('source');
        } catch (Throwable $e) {
            Log::warning('bridge release_landed: the landing could not be claimed — this delivery wakes, so the same merge may wake twice', [
                'agent' => $agent, 'repo' => $repo, 'head_sha' => $headSha, 'source' => $source,
            ] + RedactedErrorText::logContext($e));

            return true;
        }

        return $winner === null || $winner === $source;
    }
}
