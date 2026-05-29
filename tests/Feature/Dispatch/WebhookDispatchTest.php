<?php

namespace Tests\Feature\Dispatch;

use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * End-to-end: a signed kanban webhook flows through the HMAC middleware, the
 * controller, and the synchronous DispatchService — storing the event, marking
 * the agent dispatch done, and staging the intent to the inbox — all in one
 * request, returning 200.
 */
class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $secret = 'scope-5-secret'; // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/e2e-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/webhook-secret-scope-5', $this->secret);
        File::put($this->dir.'/agents.json', (string) json_encode(['agents' => [['name' => 'prod-agent', 'kanban_user_id' => 137]]]));
        File::put($this->dir.'/prod-agent.yml', "identity:\n  self: prod-agent\n"
            ."api:\n  kanban:\n    base_url: https://k.example.com\n    token_path: /t\n"
            ."receiver:\n  base_url: https://b.example.com/webhooks\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n");

        config(['bridge.secret_dir' => $this->dir, 'bridge.config_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_signed_webhook_is_stored_dispatched_and_staged(): void
    {
        $body = (string) json_encode([
            'event' => 'task.created',
            'board_id' => 5,
            'delivery_id' => 'e2e-delivery-1',
            'user_id' => 999,                       // not prod-agent → not an echo
            'payload' => ['name' => 'Ship the rewrite'],
        ]);

        $response = $this->call('POST', '/webhooks/kanban?b=5', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KANBAN_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
        ], $body);

        $response->assertStatus(200)->assertSee('ok');

        // Event durably stored.
        $event = WebhookEvent::where('delivery_id', 'e2e-delivery-1')->firstOrFail();
        $this->assertSame('task.created', $event->event_type);
        $this->assertSame('5', $event->scope_id);

        // Agent dispatch marked done, no error.
        $dispatch = AgentDispatch::where('webhook_event_id', $event->id)->where('agent_name', 'prod-agent')->firstOrFail();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);

        // Intent staged to the inbox with the stable id.
        $lines = array_map(fn ($l) => json_decode($l, true), file($this->dir.'/state/inbox.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertCount(1, $lines);
        $this->assertSame('e2e-delivery-1:prod-agent:0', $lines[0]['id']);
        $this->assertSame('new_card', $lines[0]['kind']);
        $this->assertStringContainsString('Ship the rewrite', $lines[0]['summary']);
    }

    public function test_redelivery_does_not_duplicate(): void
    {
        $body = (string) json_encode([
            'event' => 'task.created', 'board_id' => 5, 'delivery_id' => 'dup-1',
            'user_id' => 999, 'payload' => ['name' => 'Once'],
        ]);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KANBAN_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
        ];

        $this->call('POST', '/webhooks/kanban?b=5', [], [], [], $headers, $body)->assertStatus(200);
        $this->call('POST', '/webhooks/kanban?b=5', [], [], [], $headers, $body)->assertStatus(200);

        // Dedup: one event, one dispatch, one inbox line.
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, AgentDispatch::count());
        $this->assertCount(1, file($this->dir.'/state/inbox.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}
