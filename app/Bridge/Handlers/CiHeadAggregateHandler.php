<?php

namespace App\Bridge\Handlers;

use App\Bridge\CiAwait\CiHeadAggregate;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\HandlerException;
use App\Bridge\Support\AgentConfig;

/**
 * `ci_head_aggregate` (card#11667): the `impl-ci-wake` family's target on a completed
 * `workflow_run`, run once per agent that family stages CI for. Sends that agent ONE `ci_settled`
 * when the head's tracked runs are settled — {@see CiHeadAggregate} owns what that means and the
 * once-per-state guarantee. Best-effort, like `channel_push`: the event is not owed to anyone until
 * the head settles, and a throw is the dispatch row's note.
 */
final class CiHeadAggregateHandler implements Handler
{
    public const NAME = 'ci_head_aggregate';

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $repo = $target->payload['repo'] ?? null;
        $headSha = $target->payload['head_sha'] ?? null;
        if (! is_string($repo) || ! is_string($headSha)) {
            throw new HandlerException('ci_head_aggregate target carries no repo / head_sha');
        }

        app(CiHeadAggregate::class)->settleFor($agent, $repo, $headSha);
    }
}
