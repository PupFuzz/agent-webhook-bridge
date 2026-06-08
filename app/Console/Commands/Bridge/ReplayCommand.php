<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Exceptions\ConfigException;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;

/**
 * Re-run dispatch for a stored event. Errored dispatch rows (processed_at null)
 * re-run; succeeded rows are skipped — so replay never re-fires an
 * already-delivered channel_push / spawn_detached. --agent scopes to one agent;
 * --force clears processed_at first so succeeded rows re-run too.
 *
 * DispatchService is resolved LAZILY in handle(), NOT constructor-injected — the
 * bind reads every agent YAML, and console bootstrap instantiates every command,
 * so injecting it here would make one malformed YAML crash EVERY artisan command
 * (incl. bridge:check, the pre-flight). #2054.
 */
class ReplayCommand extends BridgeCommand
{
    protected $signature = 'bridge:replay {id : the webhook_events.id} {--agent= : scope to one agent} {--force : re-run even already-succeeded agents}';

    protected $description = 'Re-dispatch a stored webhook event (recovery for errored/missed dispatches)';

    public function handle(): int
    {
        return $this->guardDatabase($this->handleGuarded(...));
    }

    private function handleGuarded(): int
    {
        $event = WebhookEvent::query()->find((int) $this->argument('id'));
        if ($event === null) {
            $this->error("no webhook_event with id {$this->argument('id')}");

            return self::FAILURE;
        }

        $onlyAgent = $this->strOption('agent');

        if ($this->option('force')) {
            $query = $event->dispatches();
            if ($onlyAgent !== null) {
                $query->where('agent_name', $onlyAgent);
            }
            // Reset the WHOLE terminal tuple, not just processed_at: the re-run can
            // exit via a non-terminal path (durable-handler throw / config throw →
            // 5xx) that reaches no mark*() stamper, which would otherwise leave the
            // prior pass's outcome/reason/error_message next to the now-null
            // processed_at — the exact inconsistency DL-036 exists to prevent.
            $cleared = $query->update([
                'processed_at' => null,
                'outcome' => null,
                'reason' => null,
                'error_message' => null,
            ]);
            $this->warn("--force: reset {$cleared} dispatch row(s) for re-run");
        } else {
            // Without --force, dispatch() skips rows already marked processed_at —
            // INCLUDING gate-dropped ones (a drop is marked processed, DL-036). A
            // replay-after-gate-fix would then silently no-op for exactly the rows
            // you want to re-run. Surface the skip + how many were gate-dropped.
            $processed = AgentDispatch::query()
                ->where('webhook_event_id', $event->id)
                ->whereNotNull('processed_at')
                ->when($onlyAgent !== null, fn ($q) => $q->where('agent_name', $onlyAgent))
                ->count();
            if ($processed > 0) {
                $dropped = AgentDispatch::query()
                    ->where('webhook_event_id', $event->id)
                    ->whereNotNull('processed_at')
                    ->where('outcome', AgentDispatch::OUTCOME_DROPPED)
                    ->when($onlyAgent !== null, fn ($q) => $q->where('agent_name', $onlyAgent))
                    ->count();
                $this->warn("skipping {$processed} already-processed dispatch row(s)"
                    .($dropped > 0 ? " — {$dropped} were gate-DROPPED (recoverable after a gate fix)" : '')
                    .'; pass --force to re-run them.');
            }
        }

        $dto = new EventDto(
            deliveryId: $event->delivery_id,
            scopeId: $event->scope_id,
            eventType: $event->event_type,
            actorId: $event->actor_id,
        );
        // Resolve here (not at construction) so a malformed config surfaces as a
        // clean message on THIS command only, never a boot-time crash of all.
        try {
            $dispatcher = app(DispatchService::class);
        } catch (ConfigException $e) {
            $this->error('config error — run `php artisan bridge:check` to diagnose ('.$e->getMessage().')');

            return self::FAILURE;
        }

        $payload = $event->payload;
        $dispatcher->dispatch($event->provider, $event->scope_id, $dto, is_array($payload) ? $payload : [], $onlyAgent);

        $this->info("replayed event {$event->id}".($onlyAgent !== null ? " for agent {$onlyAgent}" : ''));

        return self::SUCCESS;
    }
}
