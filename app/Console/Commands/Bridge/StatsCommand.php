<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\BridgePaths;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Summarise the event/dispatch ledger: totals, processed vs errored, and a
 * per-provider event breakdown. --agent scopes the dispatch metrics to one
 * agent and adds its staged-inbox line count (single-install multi-agent
 * visibility, symmetry with bridge:inbox --agent).
 */
class StatsCommand extends Command
{
    protected $signature = 'bridge:stats {--agent= : scope dispatch metrics to one agent}';

    protected $description = 'Show webhook-event and agent-dispatch counts';

    public function handle(): int
    {
        $agent = $this->option('agent');
        $agent = is_string($agent) && $agent !== '' ? $agent : null;

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
