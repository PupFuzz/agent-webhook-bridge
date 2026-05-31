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
 * Idempotent across redelivery (implementation requirement 3) — WITHOUT an
 * O(file) read-before-write on the synchronous hot path (DL-012):
 *  - full redelivery is gated upstream: DispatchService skips an agent whose
 *    AgentDispatch.processed_at is already set, so stage() is never re-called
 *    for a completed dispatch (the authoritative dedup).
 *  - a PARTIAL-staging redelivery (a treatment-B IO failure mid-loop leaves
 *    processed_at null, so re-staging re-writes already-written ids) is deduped
 *    on the READ side: each line carries a stable id "{delivery_id}:{agent}:
 *    {index}" and bridge:inbox collapses duplicate ids, so a re-staged line
 *    surfaces at most once.
 *  - the line's ts derives from the event's received_at + index (NOT a fresh
 *    clock), so a re-stage produces the identical ts (stable ordering + lets
 *    bridge:prune age it deterministically).
 * Appends are O(1); growth is bounded by bridge:prune, not a per-intent scan.
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
            // No read-before-write dedup: the per-intent file scan was O(file)
            // on the synchronous hot path and grew with calendar time. Dedup is
            // upstream (processed_at) + read-side (bridge:inbox by id); see the
            // class docblock (DL-012).
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
