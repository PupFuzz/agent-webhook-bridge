<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Exceptions\InsecureSecretPermsException;
use App\Bridge\Writeback\GitHubReadClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubReadClientTest extends TestCase
{
    private string $dir;

    private string|false $origGhToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ghread-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        config(['bridge.secret_dir' => $this->dir]);
        // Hermetic: the test host / CI may export GH_TOKEN (~/.bashrc). Clear it
        // so each test controls the ambient-token fallback explicitly (DL-184).
        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        if ($this->origGhToken === false) {
            putenv('GH_TOKEN');
        } else {
            putenv('GH_TOKEN='.$this->origGhToken);
        }
        parent::tearDown();
    }

    private function writeToken(int $mode = 0o600, string $token = 'ghp_x'): void
    {
        File::put($this->dir.'/github/token', $token);
        chmod($this->dir.'/github/token', $mode);
    }

    /** Assert the built client authenticates with the given token. */
    private function assertClientUsesToken(GitHubReadClient $client, string $expected): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['state' => 'open', 'merged' => false, 'base' => ['ref' => 'main'], 'html_url' => 'u'])]);
        $client->getPull('o/r', 1);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.$expected));
    }

    public function test_from_config_is_null_when_token_absent(): void
    {
        $this->assertNull(GitHubReadClient::fromConfig());
    }

    // ---- DL-184: GH_TOKEN env fallback + configurable token_path ----

    public function test_falls_back_to_gh_token_env_when_default_file_absent(): void
    {
        putenv('GH_TOKEN=ghp_env');

        $client = GitHubReadClient::fromConfig();
        $this->assertNotNull($client);
        $this->assertClientUsesToken($client, 'ghp_env');
    }

    public function test_default_file_wins_over_gh_token_env(): void
    {
        $this->writeToken(token: 'ghp_file');
        putenv('GH_TOKEN=ghp_env');

        $client = GitHubReadClient::fromConfig();
        $this->assertNotNull($client);
        $this->assertClientUsesToken($client, 'ghp_file');
    }

    public function test_blank_gh_token_is_ignored(): void
    {
        putenv('GH_TOKEN=   ');
        $this->assertNull(GitHubReadClient::fromConfig());
    }

    public function test_configured_token_path_overrides_default(): void
    {
        $custom = $this->dir.'/coord-pat';
        File::put($custom, 'ghp_custom');
        chmod($custom, 0o600);
        config(['bridge.providers.github.token_path' => $custom]);

        $client = GitHubReadClient::fromConfig();
        $this->assertNotNull($client);
        $this->assertClientUsesToken($client, 'ghp_custom');
    }

    public function test_configured_path_is_authoritative_no_env_fallback(): void
    {
        // Explicit path set but the file is missing → null (reported by the
        // caller as "no token at <path>"), NOT a silent fall-through to GH_TOKEN.
        config(['bridge.providers.github.token_path' => $this->dir.'/missing-pat']);
        putenv('GH_TOKEN=ghp_env');

        $this->assertNull(GitHubReadClient::fromConfig());
    }

    public function test_configured_path_insecure_perms_throws(): void
    {
        $custom = $this->dir.'/coord-pat';
        File::put($custom, 'ghp_custom');
        chmod($custom, 0o644);
        config(['bridge.providers.github.token_path' => $custom]);

        $this->expectException(InsecureSecretPermsException::class);
        GitHubReadClient::fromConfig();
    }

    public function test_token_path_reports_configured_override(): void
    {
        config(['bridge.providers.github.token_path' => '~/coord-pat']);
        $this->assertStringEndsWith('/coord-pat', GitHubReadClient::tokenPath());
        $this->assertStringNotContainsString('~', GitHubReadClient::tokenPath());
    }

    public function test_from_config_throws_on_insecure_perms(): void
    {
        $this->writeToken(0o644);
        $this->expectException(InsecureSecretPermsException::class);
        GitHubReadClient::fromConfig();
    }

    public function test_get_pull_parses_state_and_sends_auth_and_ua(): void
    {
        $this->writeToken();
        Http::fake(['https://api.github.com/*' => Http::response([
            'state' => 'closed', 'merged' => true, 'base' => ['ref' => 'main'], 'html_url' => 'https://github.com/o/r/pull/7',
        ])]);

        $client = GitHubReadClient::fromConfig();
        $this->assertNotNull($client);
        $pr = $client->getPull('o/r', 7);

        $this->assertSame('closed', $pr['state']);
        $this->assertTrue($pr['merged']);
        $this->assertSame('main', $pr['base_ref']);
        $this->assertSame('https://github.com/o/r/pull/7', $pr['html_url']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/o/r/pulls/7'
            && $r->hasHeader('Authorization', 'Bearer ghp_x')
            && $r->hasHeader('User-Agent', 'agent-webhook-bridge'));
    }

    public function test_get_pull_throws_on_404(): void
    {
        $this->writeToken();
        Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $client = GitHubReadClient::fromConfig();
        $this->assertNotNull($client);
        $this->expectException(RequestException::class);
        $client->getPull('o/r', 999);
    }
}
