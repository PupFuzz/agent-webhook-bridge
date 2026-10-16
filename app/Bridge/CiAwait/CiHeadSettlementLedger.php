<?php

namespace App\Bridge\CiAwait;

use Illuminate\Support\Facades\DB;

/**
 * Which settled states each agent has already been sent (`ci_head_settlements`, card#11667): one
 * row per (agent, head, {@see HeadRuns::fingerprint()}). Both senders of `ci_settled` write it,
 * inside the transaction that stages the event, but only the per-head aggregate is GATED by it: it
 * never sends an agent a state already recorded, whoever recorded it. `ci_await` records the state
 * it sends and sends it regardless (card#11938): an await promises a push, and a row the aggregate
 * wrote before the await existed may have been only an inbox line (a `route_intents: false`
 * channel), as may one an earlier, settled await on the head wrote for a seat that re-registered.
 * The cost is a `route_intents: true` seat that registers after the aggregate's event gets the same
 * state pushed twice.
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
