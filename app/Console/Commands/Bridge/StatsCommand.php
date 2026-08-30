<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\BridgePaths;
use App\Bridge\Writeback\BoardDivergenceLedger;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use App\Models\WritebackBoardDivergence;
use Illuminate\Support\Facades\Schema;

/**
 * Summarise the event/dispatch ledger: totals, processed vs errored — split on whether
 * `bridge:replay` can actually re-run them, which since DL-315 it cannot for any event
 * past the payload window — the writeback board-divergence ledger (card#7212/DL-300 —
 * always printed, zero included), and a per-provider event breakdown. --agent scopes
 * the dispatch metrics to one agent and adds its staged-inbox line count
 * (single-install multi-agent visibility, symmetry with bridge:inbox --agent).
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

        // ⛔ `errored (replayable)` WAS A FALSE CLAIM FOR PART OF ITS OWN COUNT, and DL-315
        // made that part the default: `bridge:replay` REFUSES an event whose payload
        // retention nulled, so an errored row pointing at one is not recoverable by any
        // command this bridge has. One label over both is the shape that sends an operator
        // to a command that will turn them away — split it, and print BOTH rows always,
        // zero included, for the same reason the divergence zero below is printed.
        $errored = (clone $dispatches)->whereNull('processed_at')->whereNotNull('error_message');
        $erroredTotal = (clone $errored)->count();
        $erroredPayloadGone = (clone $errored)
            ->whereIn('webhook_event_id', WebhookEvent::query()->whereNull('payload')->select('id'))
            ->count();

        $rows = [
            ['webhook_events', WebhookEvent::query()->count()],
            [$agent !== null ? "agent_dispatches [{$agent}]" : 'agent_dispatches', (clone $dispatches)->count()],
            ['  processed', (clone $dispatches)->whereNotNull('processed_at')->count()],
            // DERIVED BY SUBTRACTION, not by a second `whereNotNull('payload')` query: the
            // two rows have to sum to the errored total on every install, and two
            // independent predicates over a table retention mutates between them cannot
            // promise that — a pass landing mid-report would print a table that does not add up.
            //
            // FLOORED AT ZERO because the two COUNTs are still two reads: a retention pass
            // nulling the payload of an already-errored event between them grows the second
            // without growing the first, and the difference goes NEGATIVE — a count of rows
            // that cannot be negative. The floor does not paper over the race, it picks which
            // of the two casualties an operator sees: inside that window the sum guarantee is
            // already unattainable, and the total is not a printed row, so nobody can observe
            // it break — whereas `errored (replayable): -1` is observable nonsense.
            ['  errored (replayable)', max(0, $erroredTotal - $erroredPayloadGone)],
            ['  errored (NOT replayable — event payload nulled by retention)', $erroredPayloadGone],
        ];
        if ($agent !== null) {
            $rows[] = ["inbox lines [{$agent}]", $this->agentInboxCount($agent)];
        }
        // card#7212 / DL-300 — ALWAYS printed, including the zero. This is the one metric
        // whose expected value is 0, and a line that appeared only when non-empty would make
        // "no cross-board write was ever recorded" indistinguishable from "nothing measured
        // it" — the exact defect the table exists to close. Not scoped by --agent: a board
        // divergence belongs to the writeback, which is not per-agent.
        //
        // ⛔ THREE STATES, NOT TWO, for the same reason the zero is printed: a count, a zero,
        // and NOT MEASURED. The table arrived after this command did, so an install that
        // upgraded and has not run `php artisan migrate` has every other table here and not
        // this one — and until this arm existed that install got no stats at all, because one
        // missing table took the whole report down through guardDatabase(). The counts a
        // maintainer already relied on keep printing, and the one that cannot be taken says so.
        $divergences = WritebackBoardDivergence::query();
        if (! Schema::hasTable($divergences->getModel()->getTable())) {
            $rows[] = ['writeback board divergences', 'NOT MEASURED — table missing; run `php artisan migrate`'];
        } else {
            $rows[] = ['writeback board divergences', (clone $divergences)->count()];
            $rows[] = ['  refused (guard stopped the write)', (clone $divergences)->where('disposition', BoardDivergenceLedger::DISPOSITION_REFUSED)->count()];
            $rows[] = ['  recorded (a divergent card reached a write site)', (clone $divergences)->where('disposition', BoardDivergenceLedger::DISPOSITION_RECORDED)->count()];
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
