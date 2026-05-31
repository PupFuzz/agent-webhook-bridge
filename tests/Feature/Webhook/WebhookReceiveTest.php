<?php

namespace Tests\Feature\Webhook;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pins the receiver's HTTP status contract end-to-end through the real
 * middleware + adapter stack. The exact codes are load-bearing: kanban-board
 * retries 5xx/429 and does NOT retry other 4xx, so a wrong code changes
 * whether a delivery is re-sent.
 */
class WebhookReceiveTest extends TestCase
{
    // The valid-delivery path now runs the synchronous dispatch (storing the
    // event), so this status-contract test needs a migrated DB. The 4xx/5xx
    // cases all short-circuit before dispatch.
    use RefreshDatabase;

    private string $secretDir;

    private string $kanbanSecret = 'kanban-scope-5-secret'; // gitleaks:allow — fake HMAC secret used only by these tests

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretDir = sys_get_temp_dir().'/bridge-hmac-'.uniqid();
        File::ensureDirectoryExists($this->secretDir.'/kanban');
        File::ensureDirectoryExists($this->secretDir.'/github');

        File::put($this->secretDir.'/kanban/webhook-secret-scope-5', $this->kanbanSecret);
        File::put($this->secretDir.'/kanban/webhook-secret-scope-7', '   ');   // whitespace-only = empty
        File::put($this->secretDir.'/github/webhook-secret-scope-acme-corp%2Fwidget', 'gh-secret');
        // 0600 like the provisioner writes — the receiver fail-closes on a
        // group/world-readable secret (DL-010).
        foreach (File::allFiles($this->secretDir) as $f) {
            chmod($f->getPathname(), 0o600);
        }

        // No *.yml in the secret dir → zero subscribers, so the valid-delivery
        // path stores the event and 200s without dispatching to any agent.
        config(['bridge.secret_dir' => $this->secretDir, 'bridge.config_dir' => $this->secretDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->secretDir);
        parent::tearDown();
    }

    private function kanbanBody(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'event' => 'task.moved',
            'board_id' => 5,
            'delivery_id' => '550e8400-e29b-41d4-a716-446655440000',
            'user_id' => 137,
            'payload' => ['from' => 1, 'to' => 2],
        ], $overrides));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function postWebhook(string $uri, string $body, array $headers = [])
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    private function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public function test_valid_kanban_delivery_is_accepted(): void
    {
        $body = $this->kanbanBody();

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(200)->assertSee('ok');
    }

    public function test_bad_signature_is_401(): void
    {
        $body = $this->kanbanBody();

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => 'sha256=deadbeef',
        ]);

        $response->assertStatus(401)->assertSee('sig_mismatch');
    }

    public function test_unknown_scope_is_401(): void
    {
        $body = $this->kanbanBody(['board_id' => 999]);

        $response = $this->postWebhook('/webhooks/kanban?b=999', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(401)->assertSee('unknown_scope');
    }

    public function test_empty_secret_file_is_500(): void
    {
        $body = $this->kanbanBody(['board_id' => 7]);

        $response = $this->postWebhook('/webhooks/kanban?b=7', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(500)->assertSee('empty_secret_file');
    }

    public function test_insecure_secret_perms_is_500(): void
    {
        // DL-010: a group/world-readable secret is no boundary — fail-closed 500
        // so kanban-board holds + redelivers once the operator chmods it.
        chmod($this->secretDir.'/kanban/webhook-secret-scope-5', 0o644);
        $body = $this->kanbanBody();

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(500)->assertSee('secret_perms_insecure');
    }

    public function test_invalid_scope_is_400(): void
    {
        $response = $this->postWebhook('/webhooks/kanban?b=../etc/passwd', '{}', [
            'X-Kanban-Signature' => 'sha256=whatever',
        ]);

        $response->assertStatus(400)->assertSee('invalid_scope');
    }

    public function test_unknown_provider_is_400(): void
    {
        $response = $this->postWebhook('/webhooks/gitlab?b=5', '{}', []);

        $response->assertStatus(400)->assertSee('unknown_provider');
    }

    public function test_invalid_provider_is_400(): void
    {
        $response = $this->postWebhook('/webhooks/Kanban?b=5', '{}', []);

        $response->assertStatus(400)->assertSee('invalid_provider');
    }

    public function test_malformed_json_is_400(): void
    {
        $body = 'this is not json';

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(400)->assertSee('invalid_envelope');
    }

    public function test_missing_required_field_is_400(): void
    {
        $body = (string) json_encode(['event' => 'task.moved', 'delivery_id' => 'd1']);  // no board_id

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(400)->assertSee('invalid_envelope');
    }

    public function test_oversize_body_is_413(): void
    {
        config(['bridge.max_body_bytes' => 16]);
        $body = str_repeat('x', 64);

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(413)->assertSee('body_too_large');
    }

    public function test_scope_mismatch_is_401(): void
    {
        // Valid signature, but the payload claims board 6 while the URL says 5.
        $body = $this->kanbanBody(['board_id' => 6]);

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(401)->assertSee('scope_mismatch');
    }

    public function test_github_ping_is_accepted_without_scope(): void
    {
        $body = (string) json_encode(['zen' => 'Design for failure.']);

        $response = $this->postWebhook('/webhooks/github?b=acme-corp/widget', $body, [
            'X-Hub-Signature-256' => $this->sign($body, 'gh-secret'),
            'X-GitHub-Delivery' => 'gh-d1',
            'X-GitHub-Event' => 'ping',
        ]);

        $response->assertStatus(200)->assertSee('pong');
    }

    public function test_missing_secret_dir_config_is_500(): void
    {
        config(['bridge.secret_dir' => null]);
        $body = $this->kanbanBody();

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(500)->assertSee('config_secret_dir_missing');
    }

    public function test_relative_secret_dir_config_is_500(): void
    {
        config(['bridge.secret_dir' => 'relative/secrets']);
        $body = $this->kanbanBody();

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(500)->assertSee('config_secret_dir_not_absolute');
    }

    public function test_github_scope_mismatch_is_401(): void
    {
        // Valid signature for scope acme-corp/widget, but the payload's
        // repository claims a different repo than the URL scope.
        $body = (string) json_encode([
            'action' => 'opened',
            'repository' => ['full_name' => 'acme-corp/other'],
            'sender' => ['login' => 'octocat'],
        ]);

        $response = $this->postWebhook('/webhooks/github?b=acme-corp/widget', $body, [
            'X-Hub-Signature-256' => $this->sign($body, 'gh-secret'),
            'X-GitHub-Delivery' => 'gh-d2',
            'X-GitHub-Event' => 'pull_request',
        ]);

        $response->assertStatus(401)->assertSee('scope_mismatch');
    }

    public function test_get_method_is_not_allowed(): void
    {
        $this->get('/webhooks/kanban?b=5')->assertStatus(405);
    }
}
