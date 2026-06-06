<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Exceptions\ConfigException;
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
            $cleared = $query->update(['processed_at' => null]);
            $this->warn("--force: cleared processed_at on {$cleared} dispatch row(s)");
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
