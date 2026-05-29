<?php

namespace Tests\Feature\Models;

use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AgentDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(): WebhookEvent
    {
        return WebhookEvent::create([
            'delivery_id' => 'evt_'.bin2hex(random_bytes(8)),
            'provider' => 'kanban',
            'scope_id' => '5',
            'event_type' => 'task.created',
            'actor_id' => '42',
            'payload' => ['task' => ['id' => 7]],
        ]);
    }

    public function test_it_belongs_to_a_webhook_event(): void
    {
        $event = $this->makeEvent();
        $dispatch = AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'prod-agent']);

        $this->assertTrue($dispatch->webhookEvent->is($event));
    }

    public function test_a_fresh_dispatch_is_neither_processed_nor_errored(): void
    {
        $dispatch = AgentDispatch::create([
            'webhook_event_id' => $this->makeEvent()->id,
            'agent_name' => 'prod-agent',
        ])->fresh();

        $this->assertNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
    }

    public function test_processed_at_is_cast_to_a_datetime(): void
    {
        $dispatch = AgentDispatch::create([
            'webhook_event_id' => $this->makeEvent()->id,
            'agent_name' => 'prod-agent',
            'processed_at' => now(),
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $dispatch->processed_at);
    }

    public function test_event_and_agent_pair_is_unique(): void
    {
        $event = $this->makeEvent();
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'prod-agent']);

        $this->expectException(UniqueConstraintViolationException::class);
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'prod-agent']);
    }

    public function test_the_same_agent_can_dispatch_for_different_events(): void
    {
        $first = AgentDispatch::create(['webhook_event_id' => $this->makeEvent()->id, 'agent_name' => 'prod-agent']);
        $second = AgentDispatch::create(['webhook_event_id' => $this->makeEvent()->id, 'agent_name' => 'prod-agent']);

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_deleting_the_event_cascades_to_its_dispatches(): void
    {
        $event = $this->makeEvent();
        $dispatch = AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'prod-agent']);

        $event->delete();

        $this->assertDatabaseMissing('agent_dispatches', ['id' => $dispatch->id]);
    }
}
