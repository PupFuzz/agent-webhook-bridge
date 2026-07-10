<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\GitHubReadClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHubReadClient — the read-only GitHub PR-state client (bridge:reconcile).
 * Token resolution lives in GitHubTokenResolver (see GitHubTokenResolverTest); the
 * client is constructed with an already-resolved token, so these tests cover only
 * the request shape (auth header, UA, endpoint) and the PR-state parsing.
 */
class GitHubReadClientTest extends TestCase
{
    public function test_get_pull_parses_state_and_sends_auth_and_ua(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response([
            'state' => 'closed', 'merged' => true, 'base' => ['ref' => 'main'], 'html_url' => 'https://github.com/o/r/pull/7',
        ])]);

        $pr = (new GitHubReadClient('ghp_x'))->getPull('o/r', 7);

        $this->assertSame('closed', $pr['state']);
        $this->assertTrue($pr['merged']);
        $this->assertSame('main', $pr['base_ref']);
        $this->assertSame('https://github.com/o/r/pull/7', $pr['html_url']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/o/r/pulls/7'
            && $r->hasHeader('Authorization', 'Bearer ghp_x')
            && $r->hasHeader('User-Agent', 'agent-webhook-bridge'));
    }

    public function test_probe_repo_sends_auth_and_ua(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['full_name' => 'o/r'])]);

        (new GitHubReadClient('ghp_probe'))->probeRepo('o/r');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/o/r'
            && $r->hasHeader('Authorization', 'Bearer ghp_probe'));
    }

    public function test_get_pull_throws_on_404(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->expectException(RequestException::class);
        (new GitHubReadClient('ghp_x'))->getPull('o/r', 999);
    }
}
