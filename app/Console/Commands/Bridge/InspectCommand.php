<?php

namespace App\Console\Commands\Bridge;

use App\Models\AgentDispatch;
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

        // ⭐ WHAT THE WORD IN THAT COLUMN COVERS (card#9172, DL-370). `delivered` is the
        // stored outcome and it reads as "the seat got it"; the bridge only ever held
        // "every handler returned without throwing". A `channel_push` leg is unconfirmed —
        // at best accepted by its transport, and on the arms where the handler raised above
        // `ChannelPushTransport::send()` never written to one at all.
        //
        // ⛔ THE LEGEND NAMES THE LINES THAT ACTUALLY EVIDENCE A LEG, and the first cut
        // named a breakdown that does not exist: it sent the operator to the
        // `bridge dispatch:` line, which is ONE AGGREGATE per dispatch with no per-leg
        // detail, and to `bridge channel_push:`, which is absent whenever the push raised
        // before reaching the transport. A pointer to a line that cannot answer — or is not
        // written — is the same defect as the word it was added to qualify.
        //
        // ⛔ SAID, NOT RELABELLED, and the ledger is why: the row records no handler
        // identity, so this table cannot tell a push-only dispatch from one that also
        // completed a card-move writeback — and a writeback DOES get a real receipt.
        // Rewriting the column for every row would replace one false claim with its
        // mirror image. Printed only when a delivered row is on screen, so it stays a
        // reading of these rows rather than a banner.
        if ($dispatches->contains(fn ($d): bool => $d->outcome === AgentDispatch::OUTCOME_DELIVERED)) {
            $this->line('`delivered` = every handler for that dispatch returned. It is not a read receipt: a '
                .'`channel_push` leg is UNCONFIRMED — at best its endpoint accepted the write (it answers once '
                .'the notification is written to it and reports nothing about what the session did with it), and '
                .'a push that raised before reaching a transport lands on a `delivered` row too. The ledger '
                .'records no handler identity, so this table cannot say per row which legs ran — and neither can '
                .'the `bridge dispatch:` log line, which is one aggregate per dispatch. The PER-HANDLER lines are '
                .'where a leg is evidenced: `kanban_move_card: moved` is a real receipt from the kanban API, and '
                .'`bridge channel_push:` reports what the endpoint declared — written only for a push that '
                .'reached the transport.');
        }

        return self::SUCCESS;
    }
}
