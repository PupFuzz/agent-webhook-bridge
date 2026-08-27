<?php

namespace App\Console\Commands\Bridge;

use App\Models\WebhookEvent;

/**
 * Pretty-print a single webhook event and its per-agent dispatch ledger.
 *
 * It REPORTS a payload retention has nulled rather than refusing the event the way
 * `bridge:replay` does — inspect neither dispatches nor reconstructs, so there is
 * nothing here to fabricate, and the row's metadata is what an operator came for.
 */
class InspectCommand extends BridgeCommand
{
    protected $signature = 'bridge:inspect {id : the webhook_events.id} {--agent= : show only this agent\'s dispatch row}';

    protected $description = 'Show a webhook event and its agent dispatches';

    public function handle(): int
    {
        return $this->guardDatabase($this->handleGuarded(...));
    }

    private function handleGuarded(): int
    {
        $event = WebhookEvent::query()->with('dispatches')->find((int) $this->argument('id'));
        if ($event === null) {
            $this->error("no webhook_event with id {$this->argument('id')}");

            return self::FAILURE;
        }

        $this->table(['field', 'value'], [
            ['id', $event->id],
            ['delivery_id', $event->delivery_id],
            ['provider', $event->provider],
            ['scope_id', $event->scope_id],
            ['event_type', $event->event_type],
            ['actor_id', $event->actor_id ?? '(null)'],
            ['received_at', (string) $event->received_at],
        ]);

        $this->line('payload:');
        // ⛔ NOT `json_encode(null)` — that prints the bare literal `null`, which reads as
        // "the upstream sent nothing" rather than "retention removed it", and this is the
        // surface an operator reaches for right after `bridge:replay` refuses. Inspect
        // deliberately does NOT refuse (unlike replay): it dispatches nothing and fabricates
        // nothing, and the row's surviving metadata above is exactly what is still worth
        // reading. Same `=== null` predicate as the replay guard and as `bridge:stats`'
        // `whereNull('payload')`, so all three surfaces name one population.
        if ($event->payload === null) {
            $this->warn('  (NULL — nulled by retention (BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN, '
                .'default 7d, DL-315). The event row is intact; `bridge:replay` REFUSES this event '
                .'because it cannot reconstruct the payload.)');
        } else {
            $this->line((string) json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $agent = $this->strOption('agent');
        $dispatches = $agent !== null
            ? $event->dispatches->where('agent_name', $agent)
            : $event->dispatches;

        $rows = $dispatches->map(fn ($d) => [
            $d->agent_name,
            // DL-036: a delivery and a gate-drop both have processed_at set — show
            // the recorded outcome so they're distinguishable. Pre-DL-036 rows have
            // no outcome → fall back to a legacy label.
            $d->outcome ?? ($d->processed_at !== null ? 'done (pre-DL036)' : ($d->error_message !== null ? 'errored' : 'pending')),
            (string) $d->processed_at,
            mb_strimwidth((string) ($d->reason ?? $d->error_message ?? ''), 0, 60, '…'),
        ])->values()->all();
        $this->table(['agent', 'outcome', 'processed_at', 'reason / error'], $rows);

        return self::SUCCESS;
    }
}
