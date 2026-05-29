<?php

namespace App\Bridge\Dispatch;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BridgePaths;
use App\Models\WebhookEvent;

/**
 * Stages intents to the per-agent inbox (state/inbox.jsonl) — the durable
 * pull-backstop an idle agent reads via hooks. This is dispatch step (B): an
 * IO failure here must propagate (→ 5xx → upstream redelivers), never be
 * swallowed.
 *
 * Idempotent across redelivery (implementation requirement 3):
 *  - write side: each line carries a stable id "{delivery_id}:{agent}:{index}"
 *    (the intent's array index — always unique within an event, unlike
 *    (subject_id, kind) which a multi-intent classifier may repeat). Re-staging
 *    an existing id is a true no-op (no duplicate line).
 *  - read side: the line's ts is derived from the event's received_at + index
 *    (NOT a fresh clock), so a re-stage produces the identical ts and can't
 *    re-surface an already-consumed card.
 */
class IntentLog
{
    public function stage(AgentConfig $agent, WebhookEvent $event, Intent $intent, int $index): void
    {
        $path = BridgePaths::stateDir().'/inbox.jsonl';
        $id = $event->delivery_id.':'.$agent->agentName.':'.$index;

        if ($this->idExists($path, $id)) {
            return;   // idempotent: already staged on a prior delivery
        }

        // received_at is the DB-default fill (non-nullable column); the
        // dispatcher refresh()es the event before staging so it's loaded. The
        // stable ts derives from it + the index — never a fresh clock — so a
        // re-stage produces the identical ts (read-side idempotency, req 3).
        $ts = (float) $event->received_at->format('U.u') + $index * 1e-6;

        BridgePaths::appendJsonl($path, array_merge(['id' => $id, 'ts' => $ts], $intent->toArray()));
    }

    private function idExists(string $path, string $id): bool
    {
        if (! is_file($path)) {
            return false;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }
}
