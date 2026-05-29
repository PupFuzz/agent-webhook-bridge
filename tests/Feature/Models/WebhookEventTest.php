<?php

namespace Tests\Feature\Models;

use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WebhookEventTest extends TestCase
{
    use RefreshDatabase;

    private function makeAttributes(array $overrides = []): array
    {
        return array_merge([
            'delivery_id' => 'evt_'.bin2hex(random_bytes(8)),
            'provider' => 'kanban',
            'scope_id' => '5',
            'event_type' => 'task.created',
            'actor_id' => '42',
            'payload' => ['task' => ['id' => 7, 'title' => 'Hello']],
        ], $overrides);
    }

    public function test_payload_is_cast_to_array_and_round_trips(): void
    {
        $event = WebhookEvent::create($this->makeAttributes());

        $this->assertSame(['task' => ['id' => 7, 'title' => 'Hello']], $event->fresh()->payload);
    }

    public function test_received_at_is_populated_by_the_database_default(): void
    {
        $event = WebhookEvent::create($this->makeAttributes());
        $event->refresh();

        $this->assertInstanceOf(Carbon::class, $event->received_at);
    }

    public function test_actor_id_may_be_null_for_system_events(): void
    {
        $event = WebhookEvent::create($this->makeAttributes(['actor_id' => null]));

        $this->assertNull($event->fresh()->actor_id);
    }

    public function test_delivery_id_is_unique(): void
    {
        WebhookEvent::create($this->makeAttributes(['delivery_id' => 'dup-1']));

        $this->expectException(UniqueConstraintViolationException::class);
        WebhookEvent::create($this->makeAttributes(['delivery_id' => 'dup-1']));
    }

    public function test_it_has_many_dispatches(): void
    {
        $event = WebhookEvent::create($this->makeAttributes());
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'prod-agent']);
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'dev-agent']);

        $dispatches = $event->dispatches;

        $this->assertInstanceOf(Collection::class, $dispatches);
        $this->assertCount(2, $dispatches);
        $this->assertEqualsCanonicalizing(
            ['prod-agent', 'dev-agent'],
            $dispatches->pluck('agent_name')->all()
        );
    }
}
