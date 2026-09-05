<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\GitHubReadClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            'merge_commit_sha' => 'abc123def456', 'head' => ['ref' => 'card-4811-widget'], 'title' => 'work, follows card#4811',
        ])]);

        $pr = (new GitHubReadClient('ghp_x'))->getPull('o/r', 7);

        $this->assertSame('closed', $pr['state']);
        $this->assertTrue($pr['merged']);
        $this->assertSame('main', $pr['base_ref']);
        // The two CLOSURE surfaces (card#7348 — DL-305 the title, DL-308 the head ref).
        // The reconciler must read the same two fields the event path reads off the
        // webhook body, and this is the only place that knows the GitHub response shape.
        $this->assertSame('work, follows card#4811', $pr['title']);
        $this->assertSame('card-4811-widget', $pr['head_ref']);
        $this->assertSame('https://github.com/o/r/pull/7', $pr['html_url']);
        $this->assertSame('abc123def456', $pr['merge_commit_sha']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/o/r/pulls/7'
            && $r->hasHeader('Authorization', 'Bearer ghp_x')
            && $r->hasHeader('User-Agent', 'agent-webhook-bridge'));
    }

    /**
     * ⭐ ALSO THE QUIET HALF OF THE card#8787 PAIR (see the loud twin below). An HONEST
     * `merged: false` says something true about the PR, so it gets no line — the whole value
     * of the new one is that it fires on a body that said nothing, and a warning here would
     * train an operator to ignore it.
     */
    public function test_get_pull_defaults_merge_commit_sha_to_empty_when_absent(): void
    {
        Log::shouldReceive('warning')->never();
        Http::fake(['https://api.github.com/*' => Http::response([
            'state' => 'open', 'merged' => false, 'base' => ['ref' => 'dev'], 'html_url' => 'https://github.com/o/r/pull/8',
        ])]);

        $pr = (new GitHubReadClient('ghp_x'))->getPull('o/r', 8);

        $this->assertFalse($pr['merged']);
        $this->assertSame('', $pr['merge_commit_sha']);
        // An absent head/title reads as '' — which names no card and carries no closing
        // form, so a malformed response withholds a move rather than authorizing one on a
        // field nobody sent. The SAFE direction, asserted rather than assumed.
        $this->assertSame('', $pr['head_ref']);
        $this->assertSame('', $pr['title']);
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

    /**
     * card#8787 — THE PAIR IS THE TEST, NOT EITHER HALF. This leg and its twin above drive
     * `getPull` with the same arguments and both project a falsy `merged`; the only things that
     * separate them are the null and the log line. Lose either and "GitHub answered a body this
     * projection could not read" collapses back into "the PR is not merged yet", which is the
     * 15-day silent no-op this card exists to end.
     */
    public function test_get_pull_names_a_body_that_carries_no_merged_flag_and_projects_null_not_false(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $m, array $c) => str_contains($m, 'carries no readable `merged` flag')
                && str_contains($m, 'the pull read for o/r#8')
                && str_contains($m, "GitHub's response shape may have changed")
                && $c['repo'] === 'o/r' && $c['pr'] === 8 && $c['read'] === 'get-pull');
        Http::fake(['https://api.github.com/*' => Http::response([
            'state' => 'closed', 'base' => ['ref' => 'dev'], 'html_url' => 'https://github.com/o/r/pull/8',
        ])]);

        $pr = (new GitHubReadClient('ghp_x'))->getPull('o/r', 8);

        // NULL, not false — the third fact, previously unsayable. Every consumer's falsy test
        // reads it exactly as it read the collapsed false, so nothing any caller decides moves.
        $this->assertNull($pr['merged']);
        $this->assertFalse((bool) $pr['merged']);
    }

    /**
     * A PRESENT-but-not-boolean `merged` is the same unreadable body, not a truthy merge: the
     * predicate is the TYPE, not the key, so a shape change that starts sending `"true"` cannot
     * walk through the guard the absent key trips.
     */
    public function test_get_pull_treats_a_non_boolean_merged_as_unreadable(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'carries no readable `merged` flag'));
        Http::fake(['https://api.github.com/*' => Http::response(['state' => 'closed', 'merged' => 'true'])]);

        $this->assertNull((new GitHubReadClient('ghp_x'))->getPull('o/r', 8)['merged']);
    }

    public function test_compare_status_defaults_to_empty_when_absent(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $m, array $c) => str_contains($m, 'carries no readable `status`')
                && str_contains($m, 'the compare read for o/r sha...main')
                && str_contains($m, "GitHub's response shape may have changed")
                && $c['repo'] === 'o/r' && $c['base'] === 'sha' && $c['head'] === 'main' && $c['read'] === 'compare');
        Http::fake(['https://api.github.com/*' => Http::response(['ahead_by' => 0])]);

        $this->assertSame('', (new GitHubReadClient('ghp_c'))->compareStatus('o/r', 'sha', 'main'));
    }

    /**
     * The quiet half of that pair: `diverged` is a real answer about a real sha, and the caller
     * acts on it as a normal negative. A line here would make the degraded read indistinguishable
     * from the dominant everyday case, which is the defect, not the fix.
     */
    public function test_compare_status_is_quiet_on_a_genuine_not_reachable_answer(): void
    {
        Log::shouldReceive('warning')->never();
        Http::fake(['https://api.github.com/*' => Http::response(['status' => 'diverged'])]);

        $this->assertSame('diverged', (new GitHubReadClient('ghp_c'))->compareStatus('o/r', 'sha', 'main'));
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
