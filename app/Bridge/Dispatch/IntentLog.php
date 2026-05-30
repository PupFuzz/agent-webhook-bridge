<?php

namespace App\Bridge\Dispatch;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BridgePaths;
use App\Models\WebhookEvent;

/**
 * Stages intents to the inbox — the durable pull-backstop an idle agent reads
 * via hooks. This is dispatch step (B): an IO failure here must propagate
 * (→ 5xx → upstream redelivers), never be swallowed.
 *
 * Every staged line carries the serving `agent` (distinct from `actor`, the
 * event's author) so a shared inbox can be filtered per agent and a single
 * install can fan out cleanly. BRIDGE_INBOX_LAYOUT selects where lines land:
 *  - shared    → state/inbox.jsonl (default; one file for all agents)
 *  - per-agent → state/inbox-<agent>.jsonl (one file per serving agent)
 *  - both      → both
 *
 * Idempotent across redelivery (implementation requirement 3):
 *  - write side: each line carries a stable id "{delivery_id}:{agent}:{index}"
 *    (the intent's array index — always unique within an event, unlike
 *    (subject_id, kind) which a multi-intent classifier may repeat). Re-staging
 *    an existing id is a true no-op per file (no duplicate line).
 *  - read side: the line's ts is derived from the event's received_at + index
 *    (NOT a fresh clock), so a re-stage produces the identical ts and can't
 *    re-surface an already-consumed card.
 */
class IntentLog
{
    public function stage(AgentConfig $agent, WebhookEvent $event, Intent $intent, int $index): void
    {
        $id = $event->delivery_id.':'.$agent->agentName.':'.$index;

        // received_at is the DB-default fill (non-nullable column); the
        // dispatcher refresh()es the event before staging so it's loaded. The
        // stable ts derives from it + the index — never a fresh clock — so a
        // re-stage produces the identical ts (read-side idempotency, req 3).
        $ts = (float) $event->received_at->format('U.u') + $index * 1e-6;

        $line = array_merge(['id' => $id, 'ts' => $ts, 'agent' => $agent->agentName], $intent->toArray());

        foreach ($this->targetPaths($agent->agentName) as $path) {
            if (BridgePaths::jsonlContainsId($path, $id)) {
                continue;   // idempotent: already staged on a prior delivery
            }
            BridgePaths::appendJsonl($path, $line);
            if ($path !== $this->sharedPath()) {
                BridgePaths::applyInboxPerms($path);   // per-agent files get the cross-user mode/group
            }
        }
    }

    private function sharedPath(): string
    {
        return BridgePaths::stateDir().'/inbox.jsonl';
    }

    /**
     * The inbox files this agent's lines are written to, per BRIDGE_INBOX_LAYOUT.
     *
     * @return list<string>
     */
    private function targetPaths(string $agentName): array
    {
        return match (BridgePaths::inboxLayout()) {
            'per-agent' => [BridgePaths::agentInboxPath($agentName)],
            'both' => [$this->sharedPath(), BridgePaths::agentInboxPath($agentName)],
            default => [$this->sharedPath()],
        };
    }
}
