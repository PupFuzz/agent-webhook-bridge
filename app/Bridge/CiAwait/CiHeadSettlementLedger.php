<?php

namespace App\Bridge\CiAwait;

use Illuminate\Support\Facades\DB;

/**
 * Which settled states each agent has already been sent (`ci_head_settlements`, card#11667): one
 * row per (agent, head, {@see HeadRuns::fingerprint()}). Both senders of `ci_settled` write it — the
 * per-head aggregate and `ci_await` — inside the transaction that stages the event, so the same
 * settled state reaches one agent once, whichever of them gets there first.
 */
final class CiHeadSettlementLedger
{
    public const TABLE = 'ci_head_settlements';

    /** Record the state as sent to `$agent`; false when it already was. */
    public static function claim(string $agent, string $repo, string $headSha, string $fingerprint): bool
    {
        return DB::table(self::TABLE)->insertOrIgnore([
            'agent' => $agent,
            'repo' => CiAwaitService::key($repo),
            'head_sha' => $headSha,
            'fingerprint' => $fingerprint,
            'created_at' => now(),
        ]) === 1;
    }
}
