<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\PathHelper;

/**
 * Append the target to a per-target activity ledger
 * (state/registry-<sanitized_target_id>.jsonl). The target_id is sanitized to
 * a single safe path segment so it can't escape the state dir.
 */
final class RegistryAppendHandler implements Handler
{
    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $path = BridgePaths::stateDir().'/registry-'.PathHelper::sanitizeSegment($target->targetId).'.jsonl';
        BridgePaths::appendJsonl($path, [
            'ts' => microtime(true),
            'agent' => $agent->agentName,
            'target_id' => $target->targetId,
            'payload' => $target->payload,
        ]);
    }
}
