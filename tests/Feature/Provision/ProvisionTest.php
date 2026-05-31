<?php

namespace Tests\Feature\Provision;

use App\Bridge\Provision\KanbanProvisionClient;
use App\Bridge\Provision\WebhookProvisioner;
use App\Bridge\Support\SecretPath;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

class ProvisionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/provision-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/token', 'secret-token'); // gitleaks:allow — <secret_dir>/<provider>/token convention
        chmod($this->dir.'/kanban/token', 0o600);   // DL-010: bridge:provision FAILs on a group/world-readable token
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.receiver_base_url' => 'https://bridge.example.com/webhooks',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private string $receiverUrl = 'https://bridge.example.com/webhooks/kanban?b=5';

    public function test_creates_a_missing_subscription_and_writes_the_secret(): void
    {
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['data' => []])
            : Http::response(['data' => ['id' => 7]]));

        $this->artisan('bridge:provision')->assertExitCode(0);

        // POST carried our receiver URL + a freshly written secret.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/boards/5/webhooks.json')
            && $r['url'] === $this->receiverUrl
            && is_string($r['secret']) && strlen($r['secret']) === 64
            && $r['active'] === true);

        $secretPath = SecretPath::for($this->dir, 'kanban', '5');
        $this->assertFileExists($secretPath);
        $this->assertSame(64, strlen(trim(File::get($secretPath))));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($secretPath)), -4));   // owner-only
    }

    public function test_existing_subscription_is_left_alone(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 3, 'url' => $this->receiverUrl, 'active' => true]]])]);

        $this->artisan('bridge:provision')->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        $this->assertFileDoesNotExist(SecretPath::for($this->dir, 'kanban', '5'));
    }

    public function test_dry_run_creates_nothing(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->artisan('bridge:provision', ['--dry-run' => true])->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        $this->assertFileDoesNotExist(SecretPath::for($this->dir, 'kanban', '5'));
    }

    public function test_unknown_provider_is_skipped_with_failure_exit(): void
    {
        File::put($this->dir.'/gh-agent.yml', "subscriptions:\n  - provider: github\n    scopes: [acme-corp/widget]\n");
        Http::fake(['*' => Http::response(['data' => []])]);

        // gh-agent's github sub is skipped (non-zero), prod-agent's kanban sub still provisions.
        $this->artisan('bridge:provision')->assertExitCode(1);
    }

    public function test_missing_token_is_skipped_with_failure_exit(): void
    {
        File::delete($this->dir.'/kanban/token');
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->artisan('bridge:provision')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_insecure_token_perms_fails_the_command(): void
    {
        // DL-010: a group/world-readable API token is a hard failure (any
        // co-tenant could read it and write upstream), not a silent skip.
        chmod($this->dir.'/kanban/token', 0o644);
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->artisan('bridge:provision')
            ->expectsOutputToContain('group/world-readable')
            ->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_inactive_subscription_drift_is_reported_without_reconcile(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 3, 'url' => $this->receiverUrl, 'active' => false]]])]);

        // No --reconcile → drift reported, non-zero exit, nothing changed.
        $this->artisan('bridge:provision')->assertExitCode(1);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' || $r->method() === 'POST');
    }

    public function test_reconcile_reactivates_inactive_subscription_reusing_secret(): void
    {
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put(SecretPath::for($this->dir, 'kanban', '5'), 'existing-secret-value'); // gitleaks:allow — test fixture
        chmod(SecretPath::for($this->dir, 'kanban', '5'), 0o600);   // DL-010: reconcile refuses a group/world-readable secret

        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['data' => [['id' => 3, 'url' => $this->receiverUrl, 'active' => false]]])
            : Http::response(['data' => ['id' => 9]]));

        $this->artisan('bridge:provision', ['--reconcile' => true])->assertExitCode(0);

        // delete the drifted webhook, then recreate REUSING the on-disk secret (no rotation).
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/webhooks/3.json'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['secret'] === 'existing-secret-value');
    }

    public function test_reconcile_refuses_an_insecure_secret(): void
    {
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put(SecretPath::for($this->dir, 'kanban', '5'), 'existing-secret-value'); // gitleaks:allow — test fixture
        chmod(SecretPath::for($this->dir, 'kanban', '5'), 0o644);   // group/world-readable

        Http::fake(['*' => Http::response(['data' => [['id' => 3, 'url' => $this->receiverUrl, 'active' => false]]])]);

        // DL-010: don't reuse + re-push a co-tenant-readable secret upstream.
        $this->artisan('bridge:provision', ['--reconcile' => true])->assertExitCode(1);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' || $r->method() === 'POST');
    }

    public function test_reconcile_fixes_filter_drift(): void
    {
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put(SecretPath::for($this->dir, 'kanban', '5'), 'existing-secret-value'); // gitleaks:allow — test fixture
        chmod(SecretPath::for($this->dir, 'kanban', '5'), 0o600);   // DL-010: reconcile refuses a group/world-readable secret

        // config has no event_filter (= all); the live sub filters to task.* → drift.
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['data' => [['id' => 3, 'url' => $this->receiverUrl, 'active' => true, 'event_filter' => ['task.*']]]])
            : Http::response(['data' => ['id' => 9]]));

        $this->artisan('bridge:provision', ['--reconcile' => true])->assertExitCode(0);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_dry_run_reconcile_previews_without_changing_anything(): void
    {
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put(SecretPath::for($this->dir, 'kanban', '5'), 'existing-secret-value'); // gitleaks:allow — test fixture
        chmod(SecretPath::for($this->dir, 'kanban', '5'), 0o600);   // DL-010: reconcile refuses a group/world-readable secret
        Http::fake(['*' => Http::response(['data' => [['id' => 3, 'url' => $this->receiverUrl, 'active' => false]]])]);

        $this->artisan('bridge:provision', ['--dry-run' => true, '--reconcile' => true])->assertExitCode(0);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' || $r->method() === 'POST');
    }

    public function test_reconcile_refuses_when_secret_missing(): void
    {
        // Inactive drift but no on-disk secret → cannot reconcile without rotating the key.
        Http::fake(['*' => Http::response(['data' => [['id' => 3, 'url' => $this->receiverUrl, 'active' => false]]])]);

        $this->artisan('bridge:provision', ['--reconcile' => true])->assertExitCode(1);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_service_cleans_up_orphaned_secret_on_create_failure(): void
    {
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['data' => []])
            : Http::response(['error' => 'boom'], 422));

        $client = new KanbanProvisionClient('https://kanban.example.com/api/v3', 'token');
        $provisioner = new WebhookProvisioner($this->dir);

        try {
            $provisioner->ensure($client, 'kanban', '5', $this->receiverUrl, null, false);
            $this->fail('expected the create to throw');
        } catch (Throwable) {
            // expected (Http throw on 422)
        }

        // The secret written before the create must be removed so a re-run starts clean.
        $this->assertFileDoesNotExist(SecretPath::for($this->dir, 'kanban', '5'));
    }
}
