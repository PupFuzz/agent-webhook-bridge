<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BridgePaths;

/**
 * Append the target to a forensic JSON-line log (state/handler-log.jsonl).
 * Cheap, always-on; NOT read by bridge:inbox (the durable agent-facing
 * backstop is Intent staging, not this log).
 */
final class LogIntentHandler implements Handler
{
    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        BridgePaths::appendJsonl(BridgePaths::stateDir().'/handler-log.jsonl', [
            'ts' => microtime(true),
            'agent' => $agent->agentName,
            'handler' => $target->handler,
            'target_id' => $target->targetId,
            'debounce_key' => $target->debounceKey,
            'payload' => $target->payload,
        ]);
    }
}
