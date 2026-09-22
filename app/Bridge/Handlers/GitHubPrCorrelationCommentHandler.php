<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Writeback\PrCorrelationComment;
use App\Bridge\Writeback\PrCorrelationCommenter;

/**
 * Posts the correlation-failure comment for a merge or close the CLASSIFIER could not correlate
 * (DL-390): a DL no card carries, or a token that does not parse. Those events emit no move target,
 * so without this reaction nothing downstream of classify would ever see them. The refusals the
 * move handler makes itself are reported from inside {@see KanbanMoveCardHandler}, through the same
 * {@see PrCorrelationCommenter}.
 *
 * DURABLE, although it never throws, for the DL-203 reason and not the redelivery one: on an echo-gated
 * event the dispatcher strips every non-durable target as agent-facing, and a pull request an agent
 * merged itself is exactly the one whose correlation failure must still be reported.
 *
 * Payload: `repo`, `outcome`, `cause` ({@see PrCorrelationComment}), `dl` (the DL the classifier
 * looked up, or null) and `title_closes_dl`, and the classifier's `pr_correlation` evidence.
 */
final class GitHubPrCorrelationCommentHandler implements DurableReaction, Handler
{
    public function __construct(private readonly PrCorrelationCommenter $commenter = new PrCorrelationCommenter) {}

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $cause = $target->payload['cause'] ?? null;
        if (is_string($cause)) {
            $this->commenter->reportForRepo($target->payload, $cause);
        }
    }
}
