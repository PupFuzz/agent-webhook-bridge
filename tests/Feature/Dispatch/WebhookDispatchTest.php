<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Support\ClassifierResolver;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
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

    private string $githubSecret = 'gh-scope-secret'; // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/e2e-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/kanban/webhook-secret-scope-5', $this->secret);
        File::put($this->dir.'/github/webhook-secret-scope-acme-corp%2Fwidget', $this->githubSecret);
        File::put($this->dir.'/agents.json', (string) json_encode(['schema_version' => 2, 'agents' => [['name' => 'prod-agent', 'kanban_user_id' => 137]]]));
        File::put($this->dir.'/prod-agent.yml', "identity:\n  self: prod-agent\n"
            ."api:\n  kanban:\n    base_url: https://k.example.com\n    token_path: /t\n"
            ."receiver:\n  base_url: https://b.example.com/webhooks\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n");

        config(['bridge.secret_dir' => $this->dir, 'bridge.config_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
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

    /**
     * Install a github-subscribed agent (`gh-agent`) that uses a test
     * classifier emitting an intent for ANY event — so "an intent staged" is a
     * causal signal that the event reached classify (the shipped
     * InboxOnlyClassifier no-ops on github events, which would make an
     * inbox-empty assertion pass for the wrong reason). gh-agent echo-suppresses
     * its own identity. `$agents` is the agents.json `agents` array.
     *
     * @param  list<array<string, mixed>>  $agents
     */
    private function writeGithubAgent(array $agents): void
    {
        File::put($this->dir.'/agents.json', (string) json_encode(['schema_version' => 2, 'agents' => $agents]));
        File::put($this->dir.'/gh-agent.yml', "identity:\n  self: gh-agent\n"
            ."api:\n  kanban:\n    base_url: https://k.example.com\n    token_path: /t\n"
            ."receiver:\n  base_url: https://b.example.com/webhooks\n"
            ."classifier:\n  class: Tests\\Feature\\Dispatch\\AlwaysIntentClassifier\n"
            ."subscriptions:\n  - provider: github\n    scopes: [acme-corp/widget]\n"
            ."echo_suppression:\n  treat_as_echo: [gh-agent]\n");
    }

    private function postGithub(string $body, string $delivery): TestResponse
    {
        return $this->call('POST', '/webhooks/github?b=acme-corp/widget', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $this->githubSecret),
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
        ], $body);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inboxLines(): array
    {
        $path = $this->dir.'/state/inbox.jsonl';
        if (! is_file($path)) {
            return [];
        }

        return array_map(fn ($l) => json_decode($l, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_github_event_is_attributed_to_agent_by_immutable_id(): void
    {
        // gh-agent is the subscriber; the event is authored by a DIFFERENT known
        // account (peer, github_user_id 583231) → not gh-agent's echo → classify
        // runs and the resolved name is observable in the staged intent.
        $this->writeGithubAgent([
            ['name' => 'gh-agent', 'github_user_id' => 700001],
            ['name' => 'peer', 'github_user_id' => 583231, 'github_login' => 'peer-bot'],
        ]);

        $body = (string) json_encode([
            'action' => 'opened',
            'repository' => ['full_name' => 'acme-corp/widget'],
            'sender' => ['id' => 583231, 'login' => 'peer-bot'],
        ]);

        $this->postGithub($body, 'gh-e2e-1')->assertStatus(200);

        $event = WebhookEvent::where('delivery_id', 'gh-e2e-1')->firstOrFail();
        $this->assertSame('583231', $event->actor_id);   // immutable numeric sender.id, not the login

        // Provider-aware match resolved github_user_id 583231 → "peer", and the
        // classifier embedded it — proves sender.id → actorFromEvent('github') →
        // byGithubUserId → name, end-to-end through the controller.
        $lines = $this->inboxLines();
        $this->assertCount(1, $lines);
        $this->assertSame('pull_request.opened by peer', $lines[0]['summary']);
    }

    public function test_github_self_event_echo_suppressed_by_immutable_id_survives_rename(): void
    {
        // gh-agent's own account, recorded in agents.json with a now-STALE login.
        // The event carries a renamed login but the same immutable id.
        $this->writeGithubAgent([
            ['name' => 'gh-agent', 'github_user_id' => 583231, 'github_login' => 'old-login'],
        ]);

        $body = (string) json_encode([
            'action' => 'opened',
            'repository' => ['full_name' => 'acme-corp/widget'],
            'sender' => ['id' => 583231, 'login' => 'renamed-login'],
        ]);

        $this->postGithub($body, 'gh-e2e-2')->assertStatus(200);

        $event = WebhookEvent::where('delivery_id', 'gh-e2e-2')->firstOrFail();
        $dispatch = AgentDispatch::where('webhook_event_id', $event->id)->where('agent_name', 'gh-agent')->firstOrFail();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);

        // Recognized as gh-agent's OWN write despite the renamed login (matching
        // keys on the id) → echo-suppressed. The always-emit classifier would
        // have staged an intent had classify run, so an empty inbox is causal.
        $this->assertSame([], $this->inboxLines());
    }
}
