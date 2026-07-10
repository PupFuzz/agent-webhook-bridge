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

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ghread-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        config(['bridge.secret_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeToken(int $mode = 0o600): void
    {
        File::put($this->dir.'/github/token', 'ghp_x');
        chmod($this->dir.'/github/token', $mode);
    }

    public function test_from_config_is_null_when_token_absent(): void
    {
        $this->assertNull(GitHubReadClient::fromConfig());
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
