<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\BridgePaths;
use App\Bridge\Writeback\BoardDivergenceLedger;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use App\Models\WritebackBoardDivergence;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;

/**
 * Summarise the event/dispatch ledger: totals, processed vs errored — split on whether
 * `bridge:replay` can actually re-run them, which since DL-315 it cannot for any event
 * past the payload window — the writeback board-divergence ledger (card#7212/DL-300 —
 * always printed, zero included, and detailed per divergence when there is one:
 * {@see divergenceHistory}), and a per-provider event breakdown. --agent scopes
 * the dispatch metrics to one agent and adds its staged-inbox line count
 * (single-install multi-agent visibility, symmetry with bridge:inbox --agent).
 */
class StatsCommand extends BridgeCommand
{
    protected $signature = 'bridge:stats {--agent= : scope dispatch metrics to one agent}';

    protected $description = 'Show webhook-event and agent-dispatch counts';

    /** How many divergences {@see divergenceHistory} details — the cap's reasoning is there. */
    private const HISTORY_LIMIT = 10;

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
        $divergenceTotal = null;
        if (! Schema::hasTable($divergences->getModel()->getTable())) {
            $rows[] = ['writeback board divergences', 'NOT MEASURED — table missing; run `php artisan migrate`'];
        } else {
            $divergenceTotal = (clone $divergences)->count();
            $rows[] = ['writeback board divergences', $divergenceTotal];
            $rows[] = ['  refused (guard stopped the write)', (clone $divergences)->where('disposition', BoardDivergenceLedger::DISPOSITION_REFUSED)->count()];
            $rows[] = ['  recorded (a divergent card reached a write site)', (clone $divergences)->where('disposition', BoardDivergenceLedger::DISPOSITION_RECORDED)->count()];
        }
        $this->table(['metric', 'count'], $rows);
        if ($divergenceTotal !== null && $divergenceTotal > 0) {
            $this->divergenceHistory($divergenceTotal);
        }

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
     * The per-divergence history: WHEN IT STARTED, WHETHER IT IS STILL HAPPENING, AND HOW
     * OFTEN — the three questions DL-300 made the row answer instead of making a reader
     * count N identical rows (card#8784).
     *
     * ⛔ THE COLUMNS EXISTED FIRST AND NOTHING READ THEM. `observations` and `last_seen_at`
     * were written on every observation from the day the table shipped, while the only
     * surface over it counted ROWS by disposition — so the ledger stated the triple as its
     * design and no operator could obtain it. This method is that reader; the alternative
     * on the table was dropping the two columns, and it was ruled against.
     *
     * ⚑ WHAT THE COUNT MEANS, MEASURED. `observations` is EVERY sighting, not the repeats:
     * the insert takes the column default of 1 and each later sighting of the same
     * observation increments it, so a divergence seen once reads `1` and never `0`. The
     * heading says `observations` for that reason and not `repeats`.
     *
     * ⚑ `first seen` IS `created_at` AND `last seen` IS `last_seen_at`, which the model
     * declares as its `UPDATED_AT`: Eloquent maintains it — including through the ledger's
     * `increment()`, which carries the updated-at column — so nothing stamps it by hand and
     * a row's first sighting has both timestamps equal. `created_at` is never rewritten.
     *
     * ⚑ ONLY WHEN THERE IS SOMETHING TO SHOW, and that is not the printed-zero defect
     * repeating itself: the count rows above already print `0` and `NOT MEASURED` on every
     * run, so an absent detail table can never be the only signal — it is the elaboration of
     * a line that is always there. The healthy state of the table is empty (DL-300).
     *
     * ⚑ MOST RECENTLY SEEN FIRST AND CAPPED. One misconfiguration mints a row per distinct
     * (card, site) pair, so this table is bounded by distinct divergences rather than by
     * traffic but is not bounded SMALL — an uncapped dump would bury the counts it details in
     * the one command an operator runs for a summary. The cap is stated in the caption
     * whenever it bites, with the true total beside it, so the surface never quietly shows a
     * part of the population as if it were the whole.
     *
     * ⚑ THE BOARD PAIR'S ARROW IS ASCII, and that is measured rather than stylistic: this
     * checkout's dev-only console renderer (`laravel/pao`, require-dev) REWRITES artisan
     * output — it collapses whitespace runs and drops `→` outright, so `12 → 8` reaches a
     * maintainer's terminal as `12 8`, which reads as two numbers. Production installs
     * (`composer install --no-dev`) do not have it and nothing in the test suite goes through
     * it, so the mangled render is invisible to both the suite and the deployed surface. `->`
     * is the same string everywhere.
     *
     * ⚑ THE SITE GETS ITS OWN LINE rather than a column. It is `Class::method (File.php:NN)`
     * with the class fully qualified — 68 to 92 characters on the real call sites — and it is
     * the most actionable field here (WHICH write site a divergent card reached). A column
     * takes the table past 200 wide; truncating one to fit cuts the `File.php:NN` off the end.
     */
    private function divergenceHistory(int $total): void
    {
        $shown = WritebackBoardDivergence::query()
            ->orderByDesc('last_seen_at')
            // A TIEBREAK, so the order is total: two divergences observed in the same
            // millisecond are two rows, and a report that reordered them between runs would
            // make the cap show a different sample of the same unchanged table.
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $rows = [];
        foreach ($shown as $i => $divergence) {
            if ($i > 0) {
                $rows[] = new TableSeparator;
            }
            $rows[] = [
                $divergence->disposition,
                $divergence->card_id ?? '(null)',
                ($divergence->card_board ?? '(null)').' -> '.$divergence->mapped_board,
                (string) $divergence->created_at,
                (string) $divergence->last_seen_at,
                $divergence->observations,
            ];
            $rows[] = [new TableCell('  at '.($divergence->site ?? '(null)'), ['colspan' => 6])];
        }

        $this->line($total > self::HISTORY_LIMIT
            ? sprintf('writeback board divergences — the %d most recently seen of %d:', self::HISTORY_LIMIT, $total)
            : 'writeback board divergences — each distinct observation, most recently seen first:');
        $this->table(['disposition', 'card', 'card board -> mapped', 'first seen', 'last seen', 'observations'], $rows);
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
