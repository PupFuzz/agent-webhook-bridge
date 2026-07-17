<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\GitHubRepoProbe;
use App\Bridge\Writeback\GitHubRepoProbeKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHubRepoProbe — the single authority for "resolve a GitHub read token for a
 * repo, probe it, classify the outcome" shared by bridge:reconcile and bridge:check
 * (canon #5). Resolution lives in GitHubTokenResolver (GitHubTokenResolverTest); the
 * request shape in GitHubReadClientTest. These tests pin the CLASSIFICATION — the
 * 401/403/404 → hint table and the unresolvable/http/network discrimination — that
 * had drifted between the two commands (bridge:check carried no status branching).
 */
class GitHubRepoProbeTest extends TestCase
{
    private string $dir;

    private string|false $origGhToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/repo-probe-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config([
            // No conventional token file and no store helper on this host, so the
            // GH_TOKEN leg resolves deterministically (source label = 'GH_TOKEN').
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.github.token_path' => null,
            'bridge.providers.github.credential_helper' => $this->dir.'/no-store-helper',
        ]);
        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN=ghp_probe');
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

    public function test_ok_when_probe_succeeds(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['full_name' => 'owner/repo'])]);

        $result = (new GitHubRepoProbe)->probe('owner/repo');

        $this->assertTrue($result->ok());
        $this->assertSame(GitHubRepoProbeKind::Ok, $result->kind);
        $this->assertNotNull($result->client);
        $this->assertSame('GH_TOKEN', $result->source);
    }

    public function test_unresolvable_when_no_token_resolves(): void
    {
        putenv('GH_TOKEN');   // no file, no store, no env → resolution fails
        Http::fake();

        $result = (new GitHubRepoProbe)->probe('owner/repo');

        $this->assertFalse($result->ok());
        $this->assertSame(GitHubRepoProbeKind::Unresolvable, $result->kind);
        $this->assertStringContainsString('no github token', (string) $result->problem);
        $this->assertNull($result->client);
        // A never-resolved token is never probed.
        Http::assertNothingSent();
    }

    public function test_http_401_classifies_the_expired_hint(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $result = (new GitHubRepoProbe)->probe('owner/repo');

        $this->assertFalse($result->ok());
        $this->assertSame(GitHubRepoProbeKind::Http, $result->kind);
        $this->assertSame(401, $result->status);
        $this->assertSame(' (token expired/revoked)', $result->hint);
        $this->assertSame('GH_TOKEN', $result->source);
    }

    public function test_http_403_classifies_the_scope_hint(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Forbidden'], 403)]);

        $result = (new GitHubRepoProbe)->probe('owner/repo');

        $this->assertSame(GitHubRepoProbeKind::Http, $result->kind);
        $this->assertSame(403, $result->status);
        $this->assertSame(' (token lacks access to this private repo — needs `repo` scope)', $result->hint);
    }

    public function test_http_404_classifies_the_scope_hint(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $result = (new GitHubRepoProbe)->probe('owner/repo');

        $this->assertSame(GitHubRepoProbeKind::Http, $result->kind);
        $this->assertSame(404, $result->status);
        $this->assertSame(' (token lacks access to this private repo — needs `repo` scope)', $result->hint);
    }

    public function test_http_other_status_has_no_hint(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Server Error'], 500)]);

        $result = (new GitHubRepoProbe)->probe('owner/repo');

        $this->assertSame(GitHubRepoProbeKind::Http, $result->kind);
        $this->assertSame(500, $result->status);
        $this->assertSame('', $result->hint);
    }

    public function test_network_error_is_a_distinct_kind_carrying_the_message(): void
    {
        Http::fake(['https://api.github.com/*' => fn () => throw new ConnectionException('Connection timed out')]);

        $result = (new GitHubRepoProbe)->probe('owner/repo');

        $this->assertFalse($result->ok());
        $this->assertSame(GitHubRepoProbeKind::Network, $result->kind);
        $this->assertStringContainsString('Connection timed out', (string) $result->networkMessage);
        $this->assertSame('GH_TOKEN', $result->source);
    }
}
