<?php

namespace App\Console\Commands\Bridge;

use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Summarise the event/dispatch ledger: totals, processed vs errored, and a
 * per-provider event breakdown.
 */
class StatsCommand extends Command
{
    protected $signature = 'bridge:stats';

    protected $description = 'Show webhook-event and agent-dispatch counts';

    public function handle(): int
    {
        $errored = AgentDispatch::query()->whereNull('processed_at')->whereNotNull('error_message')->count();

        $this->table(['metric', 'count'], [
            ['webhook_events', WebhookEvent::query()->count()],
            ['agent_dispatches', AgentDispatch::query()->count()],
            ['  processed', AgentDispatch::query()->whereNotNull('processed_at')->count()],
            ['  errored (replayable)', $errored],
        ]);

        $perProvider = WebhookEvent::query()
            ->selectRaw('provider, count(*) as c')
            ->groupBy('provider')
            ->pluck('c', 'provider');
        if ($perProvider->isNotEmpty()) {
            $this->table(['provider', 'events'], $perProvider->map(fn ($c, $p) => [$p, $c])->values()->all());
        }

        return self::SUCCESS;
    }
}
