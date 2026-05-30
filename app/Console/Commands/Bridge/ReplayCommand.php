<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Dispatch\DispatchService;
use App\Models\WebhookEvent;

/**
 * Re-run dispatch for a stored event. Errored dispatch rows (processed_at null)
 * re-run; succeeded rows are skipped — so replay never re-fires an
 * already-delivered channel_push / spawn_detached. --agent scopes to one agent;
 * --force clears processed_at first so succeeded rows re-run too.
 */
class ReplayCommand extends BridgeCommand
{
    protected $signature = 'bridge:replay {id : the webhook_events.id} {--agent= : scope to one agent} {--force : re-run even already-succeeded agents}';

    protected $description = 'Re-dispatch a stored webhook event (recovery for errored/missed dispatches)';

    public function __construct(private DispatchService $dispatcher)
    {
        parent::__construct();
    }

    public function handle(): int
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
        $payload = $event->payload;
        $this->dispatcher->dispatch($event->provider, $event->scope_id, $dto, is_array($payload) ? $payload : [], $onlyAgent);

        $this->info("replayed event {$event->id}".($onlyAgent !== null ? " for agent {$onlyAgent}" : ''));

        return self::SUCCESS;
    }
}
