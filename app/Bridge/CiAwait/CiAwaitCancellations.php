<?php

namespace App\Bridge\CiAwait;

use Illuminate\Support\Facades\DB;

/**
 * The heads each agent cancelled its `ci_await` on (`ci_await_cancellations`, card#12430). A
 * `cancelled: true` promises the seat no further `ci_settled` for that head from EITHER sender: the
 * await's own cannot follow (its row is gone), and {@see CiHeadAggregate} sends nothing to an agent
 * recorded here. Written only when the cancel removed an await, so a hook cancelling
 * unconditionally (`cancelled: false`) mutes nothing; cleared by a new `ci_await` on the head, which
 * says the seat wants events for it again. Pruned with the aggregate's other per-head bookkeeping
 * on the retention window (`RetentionService`), so a head that runs again after that is sent again.
 */
final class CiAwaitCancellations
{
    public const TABLE = 'ci_await_cancellations';

    public static function record(string $agent, string $repo, string $headSha): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'agent' => $agent,
            'repo' => CiAwaitService::key($repo),
            'head_sha' => $headSha,
            'created_at' => now(),
        ]);
    }

    public static function clear(string $agent, string $repo, string $headSha): void
    {
        DB::table(self::TABLE)->where('agent', $agent)->where('repo', CiAwaitService::key($repo))->where('head_sha', $headSha)->delete();
    }

    public static function holds(string $agent, string $repo, string $headSha): bool
    {
        return DB::table(self::TABLE)->where('agent', $agent)->where('repo', CiAwaitService::key($repo))->where('head_sha', $headSha)->exists();
    }
}
