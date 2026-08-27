<?php

namespace Tests\Feature\Webhook;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    private function agedEvent(string $deliveryId): WebhookEvent
    {
        $e = WebhookEvent::create([
            'delivery_id' => $deliveryId, 'provider' => 'kanban', 'scope_id' => '5',
            'event_type' => 'task.moved', 'payload' => ['a' => 1],
        ]);
        $e->received_at = now()->subDays(40);   // NOT fillable — must be set post-create
        $e->save();

        return $e;
    }

    private function enableRetention(): void
    {
        config([
            'bridge.retention.enabled' => true,
            'bridge.retention.older_than' => '30d',
            'bridge.retention.interval' => 86400,
            'bridge.retention.batch' => 500,
            'bridge.state_dir' => $this->secretDir.'/state',
        ]);
        Cache::flush();
    }

    public function test_a_delivery_defers_retention_to_the_terminating_phase(): void
    {
        // The controller must not prune inline: every webhook would then wait behind
        // a DELETE, which is the DL-001 latency regression DL-199 forbids.
        //
        // Terminating callbacks run in registration order, so a spy registered BEFORE
        // the request observes the world as the terminating phase begins. The aged row
        // still being there proves receive() only QUEUED the work.
        //
        // What this does NOT prove: that the response reached the client first. That
        // is fastcgi_finish_request(), an FPM property no PHPUnit test can observe —
        // it is verified against the running dev bridge instead (DL-199 step 5).
        $this->enableRetention();
        $aged = $this->agedEvent('aged-1');
        $existedWhenTerminatingBegan = null;
        $this->app->terminating(function () use ($aged, &$existedWhenTerminatingBegan) {
            $existedWhenTerminatingBegan = WebhookEvent::whereKey($aged->id)->exists();
        });

        $body = $this->kanbanBody();
        $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ])->assertStatus(200);

        $this->assertTrue($existedWhenTerminatingBegan, 'retention ran inline instead of after the response');
        $this->assertDatabaseMissing('webhook_events', ['id' => $aged->id]);   // and it DID run
    }

    public function test_a_ping_does_not_schedule_retention(): void
    {
        // A ping stores no event, so it grows nothing and must not trigger a pass —
        // the gate keys off the arrival that CREATES, not any arrival. (GitHub is the
        // only provider with a real ping; KanbanAdapter::isPing() is always false.)
        $this->enableRetention();
        $aged = $this->agedEvent('aged-ping');

        $body = (string) json_encode(['zen' => 'Design for failure.']);
        $this->postWebhook('/webhooks/github?b=acme-corp/widget', $body, [
            'X-Hub-Signature-256' => $this->sign($body, 'gh-secret'),
            'X-GitHub-Delivery' => 'gh-ping-1',
            'X-GitHub-Event' => 'ping',
        ])->assertStatus(200)->assertSee('pong');

        $this->assertDatabaseHas('webhook_events', ['id' => $aged->id]);
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

    public function test_github_replay_with_fresh_delivery_header_is_deduped(): void
    {
        // #3573 / DL-176: the dedup key binds to the SIGNED body. Resending a
        // captured, validly-signed delivery with a fresh X-GitHub-Delivery must
        // NOT create a second event row / re-dispatch.
        $body = (string) json_encode([
            'action' => 'opened',
            'repository' => ['full_name' => 'acme-corp/widget'],
            'sender' => ['id' => 583231],
        ]);
        $headers = fn (string $uuid) => [
            'X-Hub-Signature-256' => $this->sign($body, 'gh-secret'),
            'X-GitHub-Delivery' => $uuid,
            'X-GitHub-Event' => 'pull_request',
        ];

        $this->postWebhook('/webhooks/github?b=acme-corp/widget', $body, $headers('uuid-original'))->assertStatus(200);
        $this->postWebhook('/webhooks/github?b=acme-corp/widget', $body, $headers('uuid-replayed'))->assertStatus(200);

        $this->assertSame(1, WebhookEvent::query()->count());
    }

    /**
     * A CONTRACT test over a documented EXTENSION POINT, not over the two shipped
     * adapters — which is why it is derived from `WebhookAdapterFactory::SUPPORTED`
     * rather than written twice by hand (DL-315 Decision 6).
     *
     * `5` and `"x"` are VALID JSON that decode to a non-array. The receiver stores
     * `json_decode($body, true)` as the event payload with no fallback of its own, and
     * `DispatchService::dispatch()`'s `array $payload` parameter is the only thing left
     * underneath — so an adapter that refuses only UNDECODABLE bodies turns this input
     * into a TypeError → 500 → an upstream redelivering a deterministically-bad body
     * forever. `WebhookAdapter::parse()`'s docblock states the refusal; this is the
     * check that the declaration is true of this repo's own code (canon #7 leg 2).
     *
     * ⛔ SEEN TO FAIL TWO WAYS, both run, because they prove different things and only
     * the second is what this test is FOR:
     *   1. Deleting `decodeJson()`'s `is_array` guard reds at 500 — but the throw is its
     *      `: array` RETURN TYPE, a TypeError. So that mutation measures the return type,
     *      not the contract. (It is also the correction to DL-315's original mechanism,
     *      which named `requireScalar()` throwing `missing_field`: `requireScalar()` is
     *      `array`-typed too and is never reached, and a TypeError is a 500, not a 400.)
     *   2. Registering a third adapter in `SUPPORTED` that implements `WebhookAdapter`
     *      directly and never calls `decodeJson()` — exactly what
     *      `docs/provider-adapters.md` instructs for a non-`sha256=` provider — reds with
     *      `Expected response status code [400] but received 500`, the TypeError landing
     *      on `DispatchService::dispatch()`'s `array $payload` parameter. THAT is the
     *      state the declaration exists to prevent, and the reason this test is not the
     *      decoration DL-315 first argued it would be.
     *
     * A provider added to `SUPPORTED` with no fixture below reds on the missing key.
     * That is deliberate: the scalar-body case is owed by every provider, and a
     * silently-skipped provider is the hole this test exists to close.
     */
    public function test_every_supported_provider_refuses_a_scalar_json_body(): void
    {
        $fixtures = [
            'kanban' => [
                '/webhooks/kanban?b=5',
                fn (string $body) => ['X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret)],
            ],
            'github' => [
                '/webhooks/github?b=acme-corp/widget',
                fn (string $body) => [
                    'X-Hub-Signature-256' => $this->sign($body, 'gh-secret'),
                    // Present on purpose: with these absent the refusal would be
                    // `missing_header`, and the test would pass without ever reaching
                    // the body — a green over the wrong branch.
                    'X-GitHub-Delivery' => 'gh-scalar-probe',
                    'X-GitHub-Event' => 'pull_request',
                ],
            ],
        ];

        foreach (WebhookAdapterFactory::SUPPORTED as $provider) {
            $this->assertArrayHasKey(
                $provider,
                $fixtures,
                "provider `{$provider}` is registered but has no scalar-body fixture here; ".
                'see WebhookAdapter::parse()\'s docblock for what it owes'
            );
            [$uri, $headers] = $fixtures[$provider];

            foreach (['5', '"x"'] as $body) {
                $this->postWebhook($uri, $body, $headers($body))
                    ->assertStatus(400)
                    ->assertSee('invalid_envelope');

                $this->assertSame(
                    0,
                    WebhookEvent::query()->count(),
                    "{$provider} stored an event for the scalar body {$body}"
                );
            }
        }
    }
}
