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
            'merge_commit_sha' => 'abc123def456',
        ])]);

        $pr = (new GitHubReadClient('ghp_x'))->getPull('o/r', 7);

        $this->assertSame('closed', $pr['state']);
        $this->assertTrue($pr['merged']);
        $this->assertSame('main', $pr['base_ref']);
        $this->assertSame('https://github.com/o/r/pull/7', $pr['html_url']);
        $this->assertSame('abc123def456', $pr['merge_commit_sha']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/o/r/pulls/7'
            && $r->hasHeader('Authorization', 'Bearer ghp_x')
            && $r->hasHeader('User-Agent', 'agent-webhook-bridge'));
    }

    public function test_get_pull_defaults_merge_commit_sha_to_empty_when_absent(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response([
            'state' => 'open', 'merged' => false, 'base' => ['ref' => 'dev'], 'html_url' => 'https://github.com/o/r/pull/8',
        ])]);

        $pr = (new GitHubReadClient('ghp_x'))->getPull('o/r', 8);

        $this->assertFalse($pr['merged']);
        $this->assertSame('', $pr['merge_commit_sha']);
    }

    public function test_compare_status_reads_status_and_builds_the_triple_dot_range(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response([
            'status' => 'ahead', 'ahead_by' => 3, 'behind_by' => 0, 'commits' => [],
        ])]);

        $status = (new GitHubReadClient('ghp_c'))->compareStatus('o/r', 'deadbeef', 'main');

        $this->assertSame('ahead', $status);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/o/r/compare/deadbeef...main'
            && $r->hasHeader('Authorization', 'Bearer ghp_c')
            && $r->hasHeader('User-Agent', 'agent-webhook-bridge'));
    }

    public function test_compare_status_defaults_to_empty_when_absent(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['ahead_by' => 0])]);

        $this->assertSame('', (new GitHubReadClient('ghp_c'))->compareStatus('o/r', 'sha', 'main'));
    }

    public function test_compare_status_throws_on_404_unknown_sha(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->expectException(RequestException::class);
        (new GitHubReadClient('ghp_c'))->compareStatus('o/r', 'nope', 'main');
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
