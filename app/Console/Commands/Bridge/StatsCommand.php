<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\BridgePaths;
use App\Bridge\Writeback\BoardDivergenceLedger;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use App\Models\WritebackBoardDivergence;

/**
 * Summarise the event/dispatch ledger: totals, processed vs errored, the writeback
 * board-divergence ledger (card#7212/DL-300 — always printed, zero included), and a
 * per-provider event breakdown. --agent scopes the dispatch metrics to one
 * agent and adds its staged-inbox line count (single-install multi-agent
 * visibility, symmetry with bridge:inbox --agent).
 */
class StatsCommand extends BridgeCommand
{
    protected $signature = 'bridge:stats {--agent= : scope dispatch metrics to one agent}';

    protected $description = 'Show webhook-event and agent-dispatch counts';

    public function handle(): int
    {
        return $this->guardDatabase($this->handleGuarded(...));
    }

    private function handleGuarded(): int
    {
        $agent = $this->strOption('agent');

        $dispatches = AgentDispatch::query();
        if ($agent !== null) {
            $dispatches->where('agent_name', $agent);
        }

        $rows = [
            ['webhook_events', WebhookEvent::query()->count()],
            [$agent !== null ? "agent_dispatches [{$agent}]" : 'agent_dispatches', (clone $dispatches)->count()],
            ['  processed', (clone $dispatches)->whereNotNull('processed_at')->count()],
            ['  errored (replayable)', (clone $dispatches)->whereNull('processed_at')->whereNotNull('error_message')->count()],
        ];
        if ($agent !== null) {
            $rows[] = ["inbox lines [{$agent}]", $this->agentInboxCount($agent)];
        }
        // card#7212 / DL-300 — ALWAYS printed, including the zero. This is the one metric
        // whose expected value is 0, and a line that appeared only when non-empty would make
        // "no cross-board write was ever recorded" indistinguishable from "nothing measured
        // it" — the exact defect the table exists to close. Not scoped by --agent: a board
        // divergence belongs to the writeback, which is not per-agent.
        $divergences = WritebackBoardDivergence::query();
        $rows[] = ['writeback board divergences', (clone $divergences)->count()];
        $rows[] = ['  refused (guard stopped the write)', (clone $divergences)->where('disposition', BoardDivergenceLedger::DISPOSITION_REFUSED)->count()];
        $rows[] = ['  recorded (a divergent card reached a write site)', (clone $divergences)->where('disposition', BoardDivergenceLedger::DISPOSITION_RECORDED)->count()];
        $this->table(['metric', 'count'], $rows);

        $perProvider = WebhookEvent::query()
            ->selectRaw('provider, count(*) as c')
            ->groupBy('provider')
            ->pluck('c', 'provider');
        if ($perProvider->isNotEmpty()) {
            $this->table(['provider', 'events'], $perProvider->map(fn ($c, $p) => [$p, $c])->values()->all());
        }

        return self::SUCCESS;
    }

    /**
     * Staged inbox lines for an agent (per-agent file or shared-filtered) — the
     * layout-fallback contract lives in BridgePaths::agentInboxLines.
     */
    private function agentInboxCount(string $agent): int
    {
        return count(BridgePaths::agentInboxLines($agent));
    }
}
