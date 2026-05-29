<?php

namespace App\Console\Commands\Bridge;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Pretty-print a single webhook event and its per-agent dispatch ledger.
 */
class InspectCommand extends Command
{
    protected $signature = 'bridge:inspect {id : the webhook_events.id}';

    protected $description = 'Show a webhook event and its agent dispatches';

    public function handle(): int
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
        $this->line((string) json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $rows = $event->dispatches->map(fn ($d) => [
            $d->agent_name,
            $d->processed_at !== null ? 'done' : 'errored/pending',
            (string) $d->processed_at,
            $d->error_message !== null ? mb_strimwidth((string) $d->error_message, 0, 60, '…') : '',
        ])->all();
        $this->table(['agent', 'status', 'processed_at', 'error'], $rows);

        return self::SUCCESS;
    }
}
