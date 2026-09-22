<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Writeback\ProtocolInvalidLabeler;

/**
 * Adds `protocol:invalid` to the thread of a coordination comment the classifier could not attribute
 * (card#10218 / DL-408), through {@see ProtocolInvalidLabeler}, which owns what is written, when, and
 * every failure.
 *
 * DURABLE, although it never throws, for the DL-203 reason {@see GitHubPrCorrelationCommentHandler}
 * is: on an echo- or signal-gated event the dispatcher strips every non-durable target as
 * agent-facing, and whether a comment carries an attribution is a fact about the event, not about
 * which agents want to see it.
 *
 * Payload: `repo`, `number` (the enclosing issue or pull request), `comment_id`.
 */
final class GitHubProtocolInvalidLabelHandler implements DurableReaction, Handler
{
    public function __construct(private readonly ProtocolInvalidLabeler $labeler = new ProtocolInvalidLabeler) {}

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $this->labeler->apply($target->payload);
    }
}
