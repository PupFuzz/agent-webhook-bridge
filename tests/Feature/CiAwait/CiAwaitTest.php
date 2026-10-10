<?php

namespace Tests\Feature\CiAwait;

use App\Bridge\CiAwait\CiAwaitService;
use App\Bridge\ClientUpdate\CallerReport;
use App\Bridge\Scheduling\Handlers\CiAwaitSweepJob;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\DispatchOutcome;
use App\Models\CiAwait;
use App\Models\ScheduledJob;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CallingSeatSeal;
use Tests\TestCase;

/**
 * The `ci_settled` wake end to end (card#11200 / DL-452): registration through the board-tools
 * dispatcher, detection through the real webhook route, and expiry through the sweep job — with
 * GitHub and both seats' channels faked and every other request refused (`Tests\TestCase` runs
 * `Http::preventStrayRequests()`).
 */
class CiAwaitTest extends TestCase
{
    use RefreshDatabase;

    private const REPO = 'octo/widgets';

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const OTHER_SHA = 'fedcba9876543210fedcba9876543210fedcba98';

    private const RUNS_URL = 'api.github.com/repos/octo/widgets/actions/runs*';

    private const HOOK_SECRET = 'ci-await-hook-secret'; // gitleaks:allow — test fixture

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ci-await-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::ensureDirectoryExists($this->dir.'/github');
        File::ensureDirectoryExists($this->dir.'/kanban');
        foreach (['github/token' => 'gh-read-token', 'kanban/writeback-token' => 'wb', 'github/webhook-secret-scope-octo%2Fwidgets' => self::HOOK_SECRET, 'a-token' => 'tok-a', 'b-token' => 'tok-b'] as $file => $value) {
            File::put($this->dir.'/'.$file, $value);   // gitleaks:allow — test fixture
            chmod($this->dir.'/'.$file, 0o600);
        }
        foreach (['seat-a' => [8701, 'a-token'], 'seat-b' => [8702, 'b-token']] as $agent => [$port, $token]) {
            File::put($this->dir."/{$agent}.yml", "subscriptions:\n  - provider: github\n    scopes: [".self::REPO."]\n"
                ."channel:\n  url: http://127.0.0.1:{$port}/\n  auth:\n    token_path: {$this->dir}/{$token}\n");
        }
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.inbox_layout' => 'shared',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            // The registry's own after-response pass is not the subject here; the sweep is driven directly.
            'bridge.jobs.enabled' => false,
            'bridge.ci_await.ttl' => 21600,
        ]);
        Carbon::setTestNow('2026-10-03T10:00:00.000Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ---- registration -------------------------------------------------------------------

    public function test_registration_while_runs_are_in_progress_stores_the_await_and_emits_nothing(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'in_progress', null], ['Lint', 'completed', 'success']])]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA, 'pr' => 12]);

        $this->assertTrue($out->ok, json_encode($out->body()) ?: '');
        $result = $out->body()['result'];
        $this->assertSame('waiting', $result['state']);
        $this->assertSame(2, $result['runs_total']);
        $this->assertSame(1, $result['runs_completed']);
        $this->assertSame('2026-10-03T16:00:00.000Z', $result['expires_at']);
        $this->assertSame(1, CiAwait::query()->count());
        $this->assertSame([], $this->inbox());
        $this->assertSentRunsReads(1);
        $this->assertNoChannelPush();
    }

    public function test_registration_when_every_run_is_already_terminal_emits_ci_settled_at_once(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'failure'], ['Lint', 'completed', 'success']])]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok);
        $this->assertSame('settled', $out->body()['result']['state']);
        $this->assertNull($out->body()['result']['expires_at']);
        $this->assertSame(0, CiAwait::query()->count(), 'a settled await is forgotten');
        $this->assertSame(['ci_settled'], array_column($this->inbox(), 'kind'));
        $this->assertChannelPushes(['seat-a' => ['ci_settled']]);
    }

    /**
     * card#11283 / DL-461: a seat that loops register/cancel spends no GitHub quota past its
     * budget. The cooldown cannot stop it — it keys on stored rows, and cancel deletes the row —
     * so the per-seat bound does, and it STORES the await rather than refusing it.
     */
    public function test_a_register_cancel_loop_past_the_seat_budget_is_stored_and_not_read(): void
    {
        config(['bridge.ci_await.seat_reads_per_hour' => 1]);
        $this->fakeGitHub([[$this->runs([['CI', 'in_progress', null]])]]);

        $openedAt = Carbon::now()->getTimestamp();
        $first = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->assertTrue($first->ok);
        $this->assertArrayNotHasKey('read_skipped', $first->body()['result']);
        $this->callTool('seat-a', 'ci_await_cancel', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $again = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($again->ok, json_encode($again->body()) ?: '');
        $result = $again->body()['result'];
        $this->assertSame('waiting', $result['state']);
        $this->assertSame('seat_read_limited', $result['read_skipped']);
        // The window is an hour, opened by the first counted read: the budget frees about an hour
        // after it, not seconds later.
        $freesIn = Carbon::parse($result['retry_not_before'])->getTimestamp() - $openedAt;
        $this->assertGreaterThanOrEqual(3590, $freesIn, (string) $result['retry_not_before']);
        $this->assertLessThanOrEqual(3600, $freesIn, (string) $result['retry_not_before']);
        $this->assertSame(1, CiAwait::query()->count(), 'the await is stored, never refused');
        $this->assertNull(CiAwait::query()->firstOrFail()->retry_not_before, 'the seat budget is never written to the head');
        $this->assertSentRunsReads(1);
    }

    public function test_fresh_shas_past_the_seat_budget_are_stored_and_not_read_and_another_seat_is_unaffected(): void
    {
        config(['bridge.ci_await.seat_reads_per_hour' => 1]);
        $this->fakeGitHub([[$this->runs([['CI', 'in_progress', null]])], [$this->runs([['CI', 'in_progress', null]])]]);

        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $second = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::OTHER_SHA]);
        $other = $this->callTool('seat-b', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::OTHER_SHA]);

        $this->assertSame('seat_read_limited', $second->body()['result']['read_skipped']);
        $this->assertArrayNotHasKey('read_skipped', $other->body()['result'], 'the budget is per agent');
        $this->assertSame(3, CiAwait::query()->count());
        $this->assertSentRunsReads(2);
    }

    public function test_a_limiter_that_does_not_answer_skips_the_read_and_still_stores(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')->andThrow(new RuntimeException('cache store down'));
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);
        Log::spy();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok);
        $this->assertSame('seat_read_limited', $out->body()['result']['read_skipped']);
        $this->assertArrayNotHasKey('retry_not_before', $out->body()['result'], 'no instant is known when the limiter did not answer');
        $this->assertSame(1, CiAwait::query()->count());
        $this->assertSentRunsReads(0);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'per-agent read limiter could not be read'))->once();
    }

    public function test_an_out_of_range_seat_budget_is_an_install_fault(): void
    {
        config(['bridge.ci_await.seat_reads_per_hour' => 0]);
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertFalse($out->ok);
        $this->assertSame('install_fault.ci_await_config_invalid', $out->body()['reason']);
        $this->assertSame(0, CiAwait::query()->count());
    }

    public function test_a_head_with_no_runs_yet_is_not_settled(): void
    {
        $this->fakeGitHub([[$this->runs([])]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('waiting', $out->body()['result']['state']);
        $this->assertSame(1, CiAwait::query()->count());
        $this->assertSame([], $this->inbox());
    }

    public function test_an_abbreviated_sha_is_refused_and_nothing_is_stored_or_read(): void
    {
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => '0123456']);

        $this->assertFalse($out->ok);
        $this->assertSame(422, $out->status);
        $this->assertSame('bad_arguments', $out->body()['reason']);
        $this->assertStringContainsString('abbreviated', $out->body()['error']);
        $this->assertSame(0, CiAwait::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_repo_this_install_receives_no_events_for_is_refused_by_name(): void
    {
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => 'octo/elsewhere', 'head_sha' => self::SHA]);

        $this->assertFalse($out->ok);
        $this->assertSame('repo_not_received', $out->body()['reason']);
        $this->assertSame(0, CiAwait::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_seat_or_identity_argument_is_refused(): void
    {
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA, 'agent' => 'seat-b']);

        $this->assertFalse($out->ok);
        $this->assertSame('bad_arguments', $out->body()['reason']);
        $this->assertStringContainsString('belongs to YOU', $out->body()['error']);
        $this->assertSame(0, CiAwait::query()->count());
    }

    public function test_the_repo_is_matched_case_insensitively_and_stored_in_the_configured_spelling(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'queued', null]])]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => 'Octo/Widgets', 'head_sha' => strtoupper(self::SHA)]);

        $this->assertSame(self::REPO, $out->body()['result']['repo']);
        $this->assertSame(self::SHA, CiAwait::query()->sole()->head_sha);
        $this->assertSame(self::REPO, CiAwait::query()->sole()->repo);
    }

    public function test_re_registering_refreshes_the_one_await(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'queued', null]])], [$this->runs([['CI', 'queued', null]])]]);

        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA, 'pr' => 7]);
        Carbon::setTestNow('2026-10-03T11:00:00.000Z');
        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->body()['result']['refreshed']);
        $this->assertSame('2026-10-03T17:00:00.000Z', $out->body()['result']['expires_at']);
        $this->assertSame(7, $out->body()['result']['pr'], 'a re-registration without a pr keeps the recorded one');
        $this->assertSame(1, CiAwait::query()->count());
    }

    // ---- self-scope ----------------------------------------------------------------------

    public function test_a_seat_cannot_cancel_another_seats_await(): void
    {
        $this->seedAwait('seat-b');

        $out = $this->callTool('seat-a', 'ci_await_cancel', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok);
        $this->assertFalse($out->body()['result']['cancelled']);
        $this->assertSame(['seat-b'], CiAwait::query()->pluck('agent')->all());

        $mine = $this->callTool('seat-b', 'ci_await_cancel', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->assertTrue($mine->body()['result']['cancelled']);
        $this->assertSame(0, CiAwait::query()->count());
    }

    // ---- detection -----------------------------------------------------------------------

    public function test_a_completed_run_while_others_are_still_running_emits_nothing(): void
    {
        $this->seedAwait('seat-a');
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success'], ['E2E', 'in_progress', null]])]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSentRunsReads(1);
        $this->assertSame([], $this->inbox());
        $this->assertNoChannelPush();
        $await = CiAwait::query()->sole();
        $this->assertNotNull($await->last_read_at);
        $this->assertNull($await->last_error);
    }

    public function test_the_last_run_completing_emits_exactly_one_ci_settled_to_each_awaiting_seat_and_forgets_the_awaits(): void
    {
        $this->seedAwait('seat-a', pr: 12);
        $this->seedAwait('seat-b');
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success'], ['E2E', 'completed', 'failure']])]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSentRunsReads(1);
        $this->assertSame(0, CiAwait::query()->count());
        $lines = $this->inbox();
        $this->assertCount(2, $lines);
        $a = collect($lines)->firstWhere('agent', 'seat-a');
        $this->assertEquals([
            'kind' => 'ci_settled',
            'subject_id' => 'ci:octo/widgets@'.self::SHA,
            'provider' => 'bridge',
            'actor' => ['id' => null, 'name' => null, 'is_known_agent' => false],
            'summary' => "CI settled on octo/widgets@0123456789ab (PR #12): RED — E2E → failure (https://github.com/octo/widgets/actions/runs/2). Run-level only: ci-read stays the authoritative verdict (it reads jobs and the branch's required checks).",
            'payload' => [
                'repo' => self::REPO,
                'head_sha' => self::SHA,
                'pr' => 12,
                'runs' => [
                    ['workflow' => 'CI', 'conclusion' => 'success', 'html_url' => 'https://github.com/octo/widgets/actions/runs/1', 'superseded' => false],
                    ['workflow' => 'E2E', 'conclusion' => 'failure', 'html_url' => 'https://github.com/octo/widgets/actions/runs/2', 'superseded' => false],
                ],
                'all_terminal' => true,
                'runs_verdict' => 'red',
                'non_success_runs' => [['workflow' => 'E2E', 'conclusion' => 'failure', 'html_url' => 'https://github.com/octo/widgets/actions/runs/2']],
                'measured_at' => '2026-10-03T10:00:00.000Z',
            ],
        ], array_diff_key($a, ['id' => 1, 'ts' => 1, 'agent' => 1]));
        $this->assertChannelPushes(['seat-a' => ['ci_settled'], 'seat-b' => ['ci_settled']]);
    }

    public function test_two_concurrent_last_completions_emit_exactly_once(): void
    {
        $this->seedAwait('seat-a');
        $service = $this->app->make(CiAwaitService::class);
        $reentered = false;
        // The FIRST evaluation's read is where the second evaluation runs to completion: the first
        // has already loaded the await, exactly as a second FPM worker would have, and only the
        // claim stands between it and a second emit.
        Http::fake([
            self::RUNS_URL => function () use ($service, &$reentered) {
                if (! $reentered) {
                    $reentered = true;
                    $service->onWorkflowRunCompleted(self::REPO, self::SHA);
                }

                return Http::response($this->runs([['CI', 'completed', 'success']]));
            },
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $service->onWorkflowRunCompleted(self::REPO, self::SHA);

        $this->assertTrue($reentered, 'the interleaving never happened, so this measured nothing');
        $this->assertSentRunsReads(2);
        $this->assertSame(['ci_settled'], array_column($this->inbox(), 'kind'));
        $this->assertChannelPushes(['seat-a' => ['ci_settled']]);
    }

    public function test_an_unawaited_head_makes_no_read(): void
    {
        $this->seedAwait('seat-a', sha: self::OTHER_SHA);
        Http::fake();

        $this->postRunCompleted(self::SHA)->assertOk();

        Http::assertNothingSent();
        $this->assertSame(1, CiAwait::query()->count());
    }

    public function test_a_run_that_is_not_completed_is_not_evaluated(): void
    {
        $this->seedAwait('seat-a');
        Http::fake();

        $this->postWorkflowRun('in_progress', self::SHA)->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_run_list_is_read_to_its_last_page(): void
    {
        $this->seedAwait('seat-a');
        $page1 = array_fill(0, 100, ['CI', 'completed', 'success']);
        $this->fakeGitHub([[$this->runs($page1, total: 101), $this->runs([['Late', 'in_progress', null]], total: 101, firstId: 101), $this->runs($page1, total: 101)]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame([], $this->inbox(), 'the one unfinished run is on page 2, and it must hold the settle');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/actions/runs') && (string) $r['page'] === '2' && $r['head_sha'] === self::SHA);
    }

    public function test_a_settle_spanning_two_pages_carries_every_run(): void
    {
        $this->seedAwait('seat-a');
        $page1 = array_fill(0, 100, ['CI', 'completed', 'success']);
        $this->fakeGitHub([[$this->runs($page1, total: 101), $this->runs([['Late', 'completed', 'cancelled']], total: 101, firstId: 101), $this->runs($page1, total: 101)]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSentRunsReads(3);
        $lines = $this->inbox();
        $this->assertCount(1, $lines);
        $this->assertCount(101, $lines[0]['payload']['runs']);
        $this->assertSame('cancelled', $lines[0]['payload']['runs'][100]['conclusion']);
    }

    /**
     * The row's own times do not move when a read is recorded on it. ⚠ SQLite cannot fail this: the
     * hazard is MariaDB's implicit `ON UPDATE CURRENT_TIMESTAMP` on a TIMESTAMP column declared
     * without a default (the `ci_awaits` migration says why), which only the MariaDB CI legs run.
     */
    public function test_recording_a_read_moves_neither_the_registration_time_nor_the_expiry(): void
    {
        $this->seedAwait('seat-a');
        $before = CiAwait::query()->sole();
        $this->fakeGitHub([[$this->runs([['CI', 'in_progress', null]])]]);

        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->app->make(CiAwaitService::class)->onWorkflowRunCompleted(self::REPO, self::SHA);

        $after = CiAwait::query()->sole();
        $this->assertNotNull($after->last_read_at, 'the read was not recorded, so this measured nothing');
        $this->assertSame($before->expires_at->toIso8601String(), $after->expires_at->toIso8601String());
        $this->assertSame($before->created_at->toIso8601String(), $after->created_at->toIso8601String());
    }

    // ---- a consistent run list (round 1, MAJOR) -------------------------------------------

    public function test_a_run_created_between_pages_is_a_failed_read_not_a_settle(): void
    {
        $this->seedAwait('seat-a');
        // Page 1 is ids 1..100 of 101. A new run is then created ahead of them, so page 2 carries
        // id 100 AGAIN (shifted down) and the new, queued run is on neither page.
        $page1 = array_fill(0, 100, ['CI', 'completed', 'success']);
        $this->fakeGitHub([[$this->runs($page1, total: 101), $this->runs([['CI', 'completed', 'success']], total: 102, firstId: 100)]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame([], $this->inbox());
        $this->assertStringContainsString('changed while it was being read', (string) CiAwait::query()->sole()->last_error);
    }

    public function test_a_run_deleted_between_pages_is_a_failed_read_not_a_settle(): void
    {
        $this->seedAwait('seat-a');
        // Page 1 is ids 1..100 of 101. One of them is then deleted, so run 101 moves up onto page 1,
        // already read, and page 2 comes back empty: the walk never sees run 101.
        $page1 = array_fill(0, 100, ['CI', 'completed', 'success']);
        $this->fakeGitHub([[$this->runs($page1, total: 101), $this->runs([], total: 100)]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame([], $this->inbox());
        $this->assertNotNull(CiAwait::query()->sole()->last_error);
    }

    public function test_pages_whose_distinct_runs_do_not_add_up_to_the_total_are_a_failed_read(): void
    {
        $this->seedAwait('seat-a');
        // The total holds still, and a duplicate still fills the count: only de-duplicating by id sees it.
        $page1 = array_fill(0, 100, ['CI', 'completed', 'success']);
        $this->fakeGitHub([[$this->runs($page1, total: 101), $this->runs([['CI', 'completed', 'success']], total: 101, firstId: 100)]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame([], $this->inbox());
        $this->assertStringContainsString('100 distinct run(s)', (string) CiAwait::query()->sole()->last_error);
    }

    /**
     * Round 2, MAJOR. One run created AND one deleted between pages keep `total_count` (101) and
     * the distinct-id total (101) intact, so only reading page 1 again sees the new run.
     */
    public function test_a_run_created_and_another_deleted_between_pages_is_a_failed_read_not_a_settle(): void
    {
        $this->seedAwait('seat-a');
        $page1 = array_fill(0, 100, ['CI', 'completed', 'success']);
        // After the first page: queued run 500 is created at the top and run 50 is deleted, so the
        // list is 500, 1..49, 51..101 — page 2 is run 101, and run 500 is on no page the walk read.
        $after = [['New', 'queued', null, 1, 500]];
        foreach (array_merge(range(1, 49), range(51, 100)) as $id) {
            $after[] = ['CI', 'completed', 'success', 1, $id];
        }
        $this->fakeGitHub([[$this->runs($page1, total: 101), $this->runs([['CI', 'completed', 'success']], total: 101, firstId: 101), $this->runs($after, total: 101)]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame([], $this->inbox(), 'queued run 500 was never seen on the walk, so the head is not settled');
        $this->assertStringContainsString('page 1, read again after the last page', (string) CiAwait::query()->sole()->last_error);
        $this->assertSentRunsReads(3);
    }

    // ---- the delivered run (round 1, MINOR 2) --------------------------------------------

    public function test_the_delivered_run_counts_as_completed_where_the_list_still_says_in_progress(): void
    {
        $this->seedAwait('seat-a');
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success'], ['E2E', 'in_progress', null]])]]);

        $this->postRunCompleted(self::SHA, runId: 2, conclusion: 'failure')->assertOk();

        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertSame(['success', 'failure'], array_column($lines[0]['payload']['runs'], 'conclusion'));
    }

    public function test_the_delivered_run_is_counted_where_the_list_does_not_carry_it_yet(): void
    {
        $this->seedAwait('seat-a');
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);

        $this->postRunCompleted(self::SHA, runId: 7)->assertOk();

        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertCount(2, $lines[0]['payload']['runs']);
    }

    /** Round 2, item 1: a delivery for attempt 1 must not finish attempt 2 of the same run. */
    public function test_a_delivery_for_an_earlier_attempt_does_not_complete_a_re_run(): void
    {
        $this->seedAwait('seat-a');
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success'], ['E2E', 'in_progress', null, 2]])]]);

        $this->postRunCompleted(self::SHA, runId: 2, conclusion: 'failure', attempt: 1)->assertOk();

        $this->assertSame([], $this->inbox(), 'the list shows attempt 2 still running; the delivery is for attempt 1');
        $this->assertSame(1, CiAwait::query()->count());
    }

    public function test_a_delivery_for_a_later_attempt_than_the_list_shows_is_overlaid(): void
    {
        $this->seedAwait('seat-a');
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success'], ['E2E', 'in_progress', null, 1]])]]);

        $this->postRunCompleted(self::SHA, runId: 2, conclusion: 'success', attempt: 2)->assertOk();

        $this->assertSame(['ci_settled'], array_column($this->inbox(), 'kind'));
    }

    // ---- the level-triggered sweep (round 4) ---------------------------------------------

    /** A lost final delivery: the read answered `waiting`, nothing else arrives, and the sweep settles it once S has passed. */
    public function test_an_await_whose_final_delivery_is_lost_is_settled_by_the_next_eligible_sweep(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'in_progress', null]])], [$this->runs([['CI', 'completed', 'success']])]]);
        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->assertSame('waiting', $out->body()['result']['state']);

        $this->runSweep();
        $this->assertSentRunsReads(1);

        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();

        $this->assertSentRunsReads(2);
        $this->assertSame(0, CiAwait::query()->count(), 'the await is stranded until it expires');
        $this->assertChannelPushes(['seat-a' => ['ci_settled']]);
    }

    /**
     * H1 (round 4 design read): seat B registers while the final delivery's read is in flight, reads
     * for itself while the list still lags (`in_progress`), and the delivery claims only the await it
     * loaded. Nothing marks B; the sweep reads its head once the read is S old.
     */
    public function test_a_registration_that_read_in_progress_during_the_final_deliverys_read_is_settled_by_the_sweep(): void
    {
        config(['bridge.ci_await.read_cooldown' => 60]);
        $this->seedAwait('seat-a');
        $service = $this->app->make(CiAwaitService::class);
        $reads = 0;
        $bAnswer = null;
        Http::fake([
            self::RUNS_URL => function () use (&$reads, &$bAnswer) {
                $n = ++$reads;
                if ($n === 1) {
                    $bAnswer = $this->callTool('seat-b', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA])->body()['result'];
                }

                return Http::response($this->runs([['CI', $n === 2 ? 'in_progress' : 'completed', $n === 2 ? null : 'success']]));
            },
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $service->onWorkflowRunCompleted(self::REPO, self::SHA);

        $this->assertSame('waiting', $bAnswer['state'] ?? null, 'seat B did not register mid-read, so this measured nothing');
        $this->assertArrayNotHasKey('read_skipped', $bAnswer, 'seat B must have read for itself');
        $this->assertSame(['seat-b'], CiAwait::query()->pluck('agent')->all());
        $this->assertNull(CiAwait::query()->sole()->last_error);

        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();

        $this->assertSame(0, CiAwait::query()->count(), 'seat B is stranded until it expires');
        $this->assertChannelPushes(['seat-a' => ['ci_settled'], 'seat-b' => ['ci_settled']]);
    }

    public function test_the_sweep_reads_at_most_its_cap_oldest_read_first_and_never_a_head_read_within_s(): void
    {
        config(['bridge.ci_await.sweep_reads' => 2]);
        $heads = ['fresh' => [str_repeat('1', 40), 60], 'old' => [str_repeat('2', 40), 600], 'never' => [str_repeat('3', 40), null], 'older' => [str_repeat('4', 40), 3600]];
        foreach ($heads as [$sha, $age]) {
            $this->seedAwait('seat-a', sha: $sha);
            CiAwait::query()->where('head_sha', $sha)->update(['last_read_at' => $age === null ? null : Carbon::now()->subSeconds($age)]);
        }
        Http::fake([self::RUNS_URL => Http::response($this->runs([['CI', 'in_progress', null]])), '127.0.0.1:*' => Http::response('ok', 200)]);

        $this->runSweep();
        $this->assertSame([$heads['never'][0], $heads['older'][0]], $this->readHeads());

        $this->runSweep();
        $this->assertSame([$heads['never'][0], $heads['older'][0], $heads['old'][0]], $this->readHeads(), 'a head read within S is not read again');
    }

    /** Round 4 NIT: a head the HAVING leaves out is not read, and the pass does not count it as read. */
    public function test_a_head_any_of_whose_awaits_is_rate_limited_is_neither_read_nor_counted_by_the_sweep(): void
    {
        $this->seedAwait('seat-a');
        $this->seedAwait('seat-b');
        CiAwait::query()->where('agent', 'seat-a')->update(['last_error' => 'GitHub answered HTTP 502 to the workflow-run read', 'last_read_at' => Carbon::now()->subSeconds(900)]);
        CiAwait::query()->where('agent', 'seat-b')->update(['last_error' => 'GitHub answered HTTP 429 to the workflow-run read (rate limited until 2026-10-03T10:10:00.000Z)', 'retry_not_before' => Carbon::now()->addSeconds(600)]);
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);

        $outcome = $this->runSweep();

        $this->assertSentRunsReads(0);
        $this->assertStringContainsString('read 0 head(s)', $outcome->summary);
    }

    public function test_a_rate_limited_head_does_not_take_a_sweep_slot_from_a_readable_one(): void
    {
        config(['bridge.ci_await.sweep_reads' => 1]);
        $this->seedAwait('seat-a', sha: self::OTHER_SHA);
        CiAwait::query()->update(['last_read_at' => Carbon::now()->subSeconds(3600), 'last_error' => 'GitHub answered HTTP 429 to the workflow-run read', 'retry_not_before' => Carbon::now()->addSeconds(600)]);
        $this->seedAwait('seat-a');
        CiAwait::query()->where('head_sha', self::SHA)->update(['last_read_at' => Carbon::now()->subSeconds(900)]);
        Http::fake([self::RUNS_URL => Http::response($this->runs([['CI', 'in_progress', null]])), '127.0.0.1:*' => Http::response('ok', 200)]);

        $this->runSweep();

        $this->assertSame([self::SHA], $this->readHeads());
    }

    public function test_an_inbox_that_cannot_be_written_holds_back_only_its_own_seat(): void
    {
        config(['bridge.inbox_layout' => 'per-agent']);
        $blocked = $this->dir.'/state/inbox-seat-a.jsonl';
        File::ensureDirectoryExists($blocked);   // a directory where seat-a's inbox file goes
        $this->seedAwait('seat-a');
        $this->seedAwait('seat-b');
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])], [$this->runs([['CI', 'completed', 'success']])]]);

        $this->app->make(CiAwaitService::class)->onWorkflowRunCompleted(self::REPO, self::SHA);

        $this->assertSame(['seat-a'], CiAwait::query()->pluck('agent')->all(), "seat-a's unwritable inbox kept seat-b from settling");
        $this->assertSame(['ci_settled'], array_column($this->agentInbox('seat-b'), 'kind'));
        $this->assertChannelPushes(['seat-b' => ['ci_settled']]);
        $this->assertNotNull(CiAwait::query()->sole()->emit_failed_at);

        File::deleteDirectory($blocked);
        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();

        $this->assertSame(0, CiAwait::query()->count());
        $this->assertSame(['ci_settled'], array_column($this->agentInbox('seat-a'), 'kind'));
        $this->assertChannelPushes(['seat-a' => ['ci_settled'], 'seat-b' => ['ci_settled']]);
    }

    public function test_expiries_whose_inbox_cannot_be_written_block_no_other_expiry_and_are_dropped_past_the_ceiling(): void
    {
        config(['bridge.inbox_layout' => 'per-agent']);
        File::ensureDirectoryExists($this->dir.'/state/inbox-seat-a.jsonl');
        for ($i = 1; $i <= CiAwaitSweepJob::MAX_EXPIRED_PER_PASS; $i++) {
            $this->seedAwait('seat-a', sha: sprintf('%040x', $i), expiresIn: 60);
        }
        $this->seedAwait('seat-b', expiresIn: 120);
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);

        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();
        $this->assertSame(1, CiAwait::query()->where('agent', 'seat-b')->count(), 'the poison rows did not fill the pass, so this measured nothing');
        $this->assertSame(CiAwaitSweepJob::MAX_EXPIRED_PER_PASS, CiAwait::query()->whereNotNull('emit_failed_at')->count());

        // A late pass, past the retry interval: the failed rows are due again and must not fill it.
        Carbon::setTestNow('2026-10-03T10:12:00.000Z');
        $this->runSweep();
        $this->assertSame(['ci_await_expired'], array_column($this->agentInbox('seat-b'), 'kind'));

        // Within the interval of their last failure the failed rows are not tried again.
        $recent = CiAwait::query()->where('emit_failed_at', Carbon::parse('2026-10-03T10:12:00.000Z'))->pluck('id')->all();
        $this->assertNotEmpty($recent, 'the late pass retried no failed row, so this measures nothing');
        Carbon::setTestNow('2026-10-03T10:13:00.000Z');
        $this->runSweep();
        $this->assertSame($recent, CiAwait::query()->where('emit_failed_at', Carbon::parse('2026-10-03T10:12:00.000Z'))->pluck('id')->all(), 'a row that failed within the interval was tried again');

        Log::spy();
        Carbon::setTestNow(Carbon::parse('2026-10-03T10:01:00.000Z')->addSeconds(CiAwaitService::EMIT_GIVE_UP_AFTER_SECONDS + 1));
        $this->runSweep();
        $this->assertSame(0, CiAwait::query()->count(), 'a row whose inbox never takes the line is dropped past the ceiling');
        Log::shouldHaveReceived('error')->with(Mockery::pattern('/gave up/'), Mockery::on(fn (array $c): bool => ($c['agent'] ?? null) === 'seat-a'))->atLeast()->once();
    }

    public function test_an_inbox_line_id_is_ci_await_and_the_rows_uuid_so_a_recreated_table_cannot_reissue_it(): void
    {
        $this->seedAwait('seat-a', expiresIn: 60);
        $uuid = CiAwait::query()->sole()->uuid;
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $uuid);
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);
        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();

        $this->assertSame(["ci_await:{$uuid}"], array_column($this->inbox(), 'id'));

        // The table is recreated and its auto-increment restarts at 1: the new row has the SAME
        // numeric id as the one just emitted, and must not have the same line id.
        $migration = require glob(database_path('migrations/*_create_ci_awaits_table.php'))[0];
        $migration->down();
        $migration->up();
        $this->seedAwait('seat-a', expiresIn: 60);
        $second = CiAwait::query()->sole();
        $this->assertSame(1, (int) $second->id, 'the id did not restart, so this measured nothing');
        $this->assertNotSame($uuid, $second->uuid);
    }

    public function test_a_settle_that_reached_only_part_of_the_inbox_and_then_an_expiry_leave_one_terminal_event(): void
    {
        config(['bridge.inbox_layout' => 'both']);
        $blocked = $this->dir.'/state/inbox-seat-a.jsonl';
        File::ensureDirectoryExists($blocked);
        $this->seedAwait('seat-a', expiresIn: 600);
        Http::fake([self::RUNS_URL => Http::response($this->runs([['CI', 'completed', 'success']])), '127.0.0.1:*' => Http::response('ok', 200)]);
        $this->runSweep();
        $this->assertSame(1, CiAwait::query()->count(), 'the settle did not fail on the per-agent file, so this measured nothing');
        $this->assertSame(['ci_settled'], array_column($this->inbox(), 'kind'), 'the shared file did not take the first half of the append, so this measured nothing');

        File::deleteDirectory($blocked);
        Http::fake([self::RUNS_URL => Http::response($this->runs([['CI', 'in_progress', null]])), '127.0.0.1:*' => Http::response('ok', 200)]);
        Carbon::setTestNow(Carbon::now()->addSeconds(601));
        $this->runSweep();

        $this->assertSame(['ci_settled', 'ci_await_expired'], array_column($this->inbox(), 'kind'), 'the expiry was not written, so this measured nothing');
        $terminal = array_values(array_filter(BridgePaths::unseenInboxLines(null), fn (array $l): bool => str_starts_with((string) ($l['kind'] ?? ''), 'ci_')));
        $this->assertCount(1, $terminal, 'a shared-inbox reader sees both ci_settled and ci_await_expired for one await: '.implode(', ', array_column($terminal, 'kind')));
    }

    public function test_a_row_past_the_ceiling_whose_inbox_is_writable_again_is_emitted_not_dropped(): void
    {
        config(['bridge.inbox_layout' => 'per-agent']);
        $blocked = $this->dir.'/state/inbox-seat-a.jsonl';
        File::ensureDirectoryExists($blocked);
        $this->seedAwait('seat-a', expiresIn: 60);
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);
        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();
        $this->assertNotNull(CiAwait::query()->sole()->emit_failed_at, 'the first pass did not fail the emit, so this measured nothing');

        File::deleteDirectory($blocked);
        Carbon::setTestNow(Carbon::parse('2026-10-03T10:01:00.000Z')->addSeconds(CiAwaitService::EMIT_GIVE_UP_AFTER_SECONDS + 1));
        $this->runSweep();

        $this->assertSame(0, CiAwait::query()->count());
        $this->assertSame(['ci_await_expired'], array_column($this->agentInbox('seat-a'), 'kind'), 'dropped without a final attempt although the inbox is writable');
    }

    public function test_one_head_whose_read_throws_does_not_starve_the_heads_behind_it(): void
    {
        config(['bridge.ci_await.sweep_reads' => 10]);
        $this->seedAwait('seat-a', sha: self::OTHER_SHA);
        CiAwait::query()->update(['last_read_at' => Carbon::now()->subSeconds(3600)]);
        $this->seedAwait('seat-a');
        CiAwait::query()->where('head_sha', self::SHA)->update(['last_read_at' => Carbon::now()->subSeconds(900)]);
        Http::fake([self::RUNS_URL => function (Request $r) {
            if (str_contains($r->url(), self::OTHER_SHA)) {
                throw new RuntimeException('boom');
            }

            return Http::response($this->runs([['CI', 'completed', 'success']]));
        }, '127.0.0.1:*' => Http::response('ok', 200)]);

        Carbon::setTestNow('2026-10-03T10:10:00.000Z');
        $thrown = null;
        try {
            $this->runSweep();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the pass reported success although a head read threw, so the registry would record it as ok');
        $this->assertStringContainsString('head(s) threw', $thrown->getMessage());
        $this->assertStringContainsString('boom', (string) CiAwait::query()->where('head_sha', self::OTHER_SHA)->sole()->last_error, 'the throwing head carries no error, so bridge:check cannot name why it never settles');
        $this->assertSame(0, CiAwait::query()->where('head_sha', self::SHA)->count(), 'the all-terminal head behind a throwing one was not settled in the same pass');
        $this->assertTrue(CiAwait::query()->where('head_sha', self::OTHER_SHA)->sole()->last_read_at->isAfter(Carbon::now()->subSeconds(60)), 'the throwing head was not stamped, so it would head the next pass again');
    }

    public function test_an_await_whose_settle_failed_is_tried_as_an_expiry_before_it_can_be_dropped(): void
    {
        $this->seedAwait('seat-a', expiresIn: 60);
        CiAwait::query()->update(['emit_failed_at' => Carbon::now()]);
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);

        Carbon::setTestNow(Carbon::parse('2026-10-03T10:01:00.000Z')->addSeconds(CiAwaitService::EMIT_GIVE_UP_AFTER_SECONDS + 60));
        $this->runSweep();

        $this->assertSame(['ci_await_expired'], array_column($this->inbox(), 'kind'), 'dropped without its expiry ever being tried');
    }

    public function test_a_read_half_that_throws_does_not_skip_the_expiry_half(): void
    {
        config(['bridge.ci_await.sweep_reads' => 0]);
        $this->seedAwait('seat-a', expiresIn: 60);
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);
        Carbon::setTestNow('2026-10-03T10:05:00.000Z');

        $thrown = null;
        try {
            $this->runSweep();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'a pass whose read half failed must report it');
        $this->assertStringContainsString('BRIDGE_CI_AWAIT_SWEEP_READS', $thrown->getMessage());
        $this->assertSame(['ci_await_expired'], array_column($this->inbox(), 'kind'));
    }

    public function test_a_cooled_registration_whose_await_a_concurrent_settle_claimed_answers_settled(): void
    {
        config(['bridge.ci_await.read_cooldown' => 60]);
        $this->seedAwait('seat-b');
        CiAwait::query()->update(['last_read_at' => Carbon::now()->subSeconds(10)]);
        Http::fake();
        $claimed = false;
        DB::listen(function (QueryExecuted $q) use (&$claimed): void {
            if (! $claimed && str_contains($q->sql, 'exists') && str_contains($q->sql, 'last_read_at')) {
                $claimed = true;
                CiAwait::query()->where('agent', 'seat-a')->delete();
            }
        });

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($claimed, 'the cooldown query never ran, so this measured nothing');
        $this->assertSame('settled', $out->body()['result']['state']);
        $this->assertNull($out->body()['result']['expires_at']);
    }

    public function test_a_registration_whose_own_read_is_rate_limited_answers_when_it_is_read_again(): void
    {
        Http::fake([self::RUNS_URL => Http::response(['message' => 'secondary rate limit'], 403, ['Retry-After' => '120']), '127.0.0.1:*' => Http::response('ok', 200)]);

        $result = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA])->body()['result'];

        $this->assertSame('unmeasured', $result['state']);
        $this->assertArrayNotHasKey('read_skipped', $result, 'the read was made, so it was not skipped');
        $this->assertSame('2026-10-03T10:02:00.000Z', $result['retry_not_before'] ?? null);
    }

    // ---- what the tool may say after the store (round 1, MINOR 3) ------------------------

    public function test_a_failure_after_the_await_is_stored_answers_unmeasured_and_keeps_the_await(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);
        // Every run is terminal, so the registration claims and stages — and the inbox cannot be
        // written: its directory sits under a regular file.
        File::put($this->dir.'/blocker', 'x');
        config(['bridge.state_dir' => $this->dir.'/blocker/state']);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame('unmeasured', $out->body()['result']['state']);
        $this->assertStringContainsString('ci_settled could not be written to your inbox', $out->body()['result']['read_error']);
        $this->assertSame(1, CiAwait::query()->count(), 'the claim rolled back with the failed stage, so the await is still there');
        $this->assertNotNull(CiAwait::query()->sole()->emit_failed_at);
    }

    public function test_a_store_that_cannot_be_written_is_refused_as_nothing_stored(): void
    {
        Http::fake();
        $migration = require glob(database_path('migrations/*_create_ci_awaits_table.php'))[0];
        $migration->down();
        try {
            $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        } finally {
            $migration->up();
        }

        $this->assertFalse($out->ok);
        $this->assertSame('install_fault.ci_await_store_unavailable', $out->body()['reason']);
        Http::assertNothingSent();
    }

    // ---- quota (round 1, MINOR 4) --------------------------------------------------------

    public function test_a_new_await_past_the_per_seat_cap_is_refused_and_a_refresh_is_not(): void
    {
        config(['bridge.ci_await.max_per_seat' => 2]);
        $this->seedAwait('seat-a', sha: self::OTHER_SHA);
        $this->seedAwait('seat-a', sha: str_repeat('c', 40));
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertFalse($out->ok);
        $this->assertSame('too_many_awaits', $out->body()['reason']);
        $this->assertSame(2, CiAwait::query()->count());
        Http::assertNothingSent();

        $this->fakeGitHub([[$this->runs([['CI', 'queued', null]], firstId: 1)]]);
        $refresh = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::OTHER_SHA]);
        $this->assertTrue($refresh->ok);
        $this->assertTrue($refresh->body()['result']['refreshed']);
    }

    public function test_a_registration_inside_the_read_cooldown_answers_waiting_without_a_read(): void
    {
        config(['bridge.ci_await.read_cooldown' => 60]);
        $this->seedAwait('seat-b');
        CiAwait::query()->update(['last_read_at' => Carbon::now()->subSeconds(10)]);
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('waiting', $out->body()['result']['state']);
        $this->assertSame('cooldown', $out->body()['result']['read_skipped']);
        $this->assertSame(2, CiAwait::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_registration_past_the_read_cooldown_reads(): void
    {
        config(['bridge.ci_await.read_cooldown' => 60]);
        $this->seedAwait('seat-b');
        CiAwait::query()->update(['last_read_at' => Carbon::now()->subSeconds(120)]);
        $this->fakeGitHub([[$this->runs([['CI', 'queued', null]])]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertArrayNotHasKey('read_skipped', $out->body()['result']);
        $this->assertSentRunsReads(1);
    }

    public function test_the_sweep_does_not_retry_a_rate_limited_head_before_its_reset(): void
    {
        $this->seedAwait('seat-a');
        $reset = Carbon::now()->addSeconds(600);
        Http::fake([
            self::RUNS_URL => Http::sequence()
                ->push(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) $reset->getTimestamp()])
                ->push($this->runs([['CI', 'completed', 'success']])),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);
        $this->postRunCompleted(self::SHA)->assertOk();
        $this->assertStringContainsString('(rate limited until 2026-10-03T10:10:00.000Z)', (string) CiAwait::query()->sole()->last_error);

        $this->runSweep();
        $this->assertSentRunsReads(1);

        Carbon::setTestNow('2026-10-03T10:10:01.000Z');
        $this->runSweep();
        $this->assertSentRunsReads(2);
        $this->assertSame(['ci_settled'], array_column($this->inbox(), 'kind'));
    }

    /** Round 2, item 5: a secondary limit is a 403 whose Remaining is not 0 but which says Retry-After. */
    public function test_a_403_carrying_retry_after_is_a_rate_limit_with_its_reset(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([
            self::RUNS_URL => Http::response(['message' => 'You have exceeded a secondary rate limit'], 403, ['X-RateLimit-Remaining' => '4000', 'Retry-After' => '120']),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $await = CiAwait::query()->sole();
        $this->assertSame('GitHub answered HTTP 403 to the workflow-run read (rate limited until 2026-10-03T10:02:00.000Z)', $await->last_error);
        $this->assertSame('2026-10-03T10:02:00+00:00', $await->retry_not_before?->toIso8601String());
    }

    public function test_a_delivery_does_not_read_a_head_before_its_rate_limit_resets(): void
    {
        $this->seedAwait('seat-a');
        CiAwait::query()->update(['last_error' => 'GitHub answered HTTP 429 to the workflow-run read (rate limited until 2026-10-03T10:10:00.000Z)', 'retry_not_before' => Carbon::now()->addSeconds(600)]);
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSentRunsReads(0);
        $this->assertSame([], $this->inbox());
        $this->assertSame(1, CiAwait::query()->count());
    }

    public function test_a_registration_on_a_rate_limited_head_answers_waiting_with_the_reason_and_makes_no_read(): void
    {
        $this->seedAwait('seat-b');
        CiAwait::query()->update(['last_error' => 'GitHub answered HTTP 429 to the workflow-run read (rate limited until 2026-10-03T10:10:00.000Z)', 'retry_not_before' => Carbon::now()->addSeconds(600)]);
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $result = $out->body()['result'];
        $this->assertSame('waiting', $result['state']);
        $this->assertSame('rate_limited', $result['read_skipped'] ?? null);
        $this->assertSame('2026-10-03T10:10:00.000Z', $result['retry_not_before'] ?? null);
        $this->assertSentRunsReads(0);
        $mine = CiAwait::query()->where('agent', 'seat-a')->sole();
        $this->assertNotNull($mine->retry_not_before, 'the new await must carry the reset, or the sweep could not retry it as part of the head');

        $this->runSweep();
        $this->assertSentRunsReads(0);

        Carbon::setTestNow('2026-10-03T10:10:01.000Z');
        $this->runSweep();
        $this->assertSentRunsReads(1);
        $this->assertSame(0, CiAwait::query()->count(), 'a registration skipped by a rate limit is read by the sweep after the reset');
    }

    /** MINOR: a secondary-limit 403 carries Retry-After AND a primary reset an hour out; the short one is the wait. */
    public function test_retry_after_wins_over_a_far_primary_reset_on_a_secondary_limit(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([
            self::RUNS_URL => Http::response(['message' => 'secondary rate limit'], 403, ['X-RateLimit-Remaining' => '4000', 'X-RateLimit-Reset' => (string) Carbon::now()->addHour()->getTimestamp(), 'Retry-After' => '60']),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame('2026-10-03T10:01:00+00:00', CiAwait::query()->sole()->retry_not_before?->toIso8601String());
    }

    public function test_a_primary_reset_is_honoured_only_when_the_quota_is_spent(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([
            self::RUNS_URL => Http::response(['message' => 'forbidden'], 429, ['X-RateLimit-Remaining' => '12', 'X-RateLimit-Reset' => (string) Carbon::now()->addHour()->getTimestamp()]),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertNull(CiAwait::query()->sole()->retry_not_before);
    }

    public function test_an_http_date_retry_after_is_understood(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([
            self::RUNS_URL => Http::response(['message' => 'secondary rate limit'], 403, ['Retry-After' => Carbon::now()->addSeconds(90)->utc()->format(DATE_RFC7231)]),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame('2026-10-03T10:01:30+00:00', CiAwait::query()->sole()->retry_not_before?->toIso8601String());
    }

    // ---- case (round 1, MINOR 5) and pr bound (MINOR 6) ----------------------------------

    public function test_repo_spellings_that_differ_only_in_case_name_one_await(): void
    {
        File::put($this->dir.'/seat-a.yml', "subscriptions:\n  - provider: github\n    scopes: [Octo/Widgets]\n");
        Http::fake(fn (Request $r) => stripos($r->url(), 'api.github.com/repos/octo/widgets/actions/runs') !== false
            ? Http::response($this->runs([['CI', 'in_progress', null]]))
            : Http::response('ok', 200));

        $this->callTool('seat-a', 'ci_await', ['repo' => 'octo/WIDGETS', 'head_sha' => self::SHA]);
        $this->callTool('seat-a', 'ci_await', ['repo' => 'OCTO/widgets', 'head_sha' => self::SHA]);

        $await = CiAwait::query()->sole();
        $this->assertSame('octo/widgets', $await->repo);
        $this->assertSame('Octo/Widgets', $await->repo_name);

        // One read at the first registration (the second sits inside the read cooldown), and one for
        // the detector, which is handed a spelling the store never saw — all against the configured one.
        $this->app->make(CiAwaitService::class)->onWorkflowRunCompleted('octo/widgets', self::SHA);
        $this->assertSentRunsReadsMatching('/repos/Octo/Widgets/actions/runs', 2);

        $cancel = $this->callTool('seat-a', 'ci_await_cancel', ['repo' => 'Octo/WIDGETS', 'head_sha' => self::SHA]);
        $this->assertTrue($cancel->body()['result']['cancelled']);
        $this->assertSame(0, CiAwait::query()->count());
    }

    public function test_a_pr_above_the_column_maximum_is_refused_before_any_write(): void
    {
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA, 'pr' => 4294967296]);

        $this->assertFalse($out->ok);
        $this->assertSame('bad_arguments', $out->body()['reason']);
        $this->assertSame(0, CiAwait::query()->count());
        Http::assertNothingSent();
    }

    // ---- read failures -------------------------------------------------------------------

    public function test_a_failed_read_keeps_the_await_emits_nothing_and_is_retried(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([
            self::RUNS_URL => Http::sequence()
                ->push(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0'])
                ->push($this->runs([['CI', 'completed', 'success']])),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $await = CiAwait::query()->sole();
        $this->assertSame('GitHub answered HTTP 403 to the workflow-run read (rate limited)', $await->last_error);
        $this->assertSame([], $this->inbox());
        $this->assertNoChannelPush();

        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();

        $this->assertSame(0, CiAwait::query()->count());
        $this->assertSame(['ci_settled'], array_column($this->inbox(), 'kind'));
    }

    public function test_a_read_that_never_answers_ends_in_ci_await_expired_carrying_the_last_error(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([
            self::RUNS_URL => Http::response(['message' => 'boom'], 502),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);
        $this->postRunCompleted(self::SHA)->assertOk();

        Carbon::setTestNow('2026-10-03T16:00:01.000Z');
        $this->runSweep();

        $lines = $this->inbox();
        $this->assertSame(['ci_await_expired'], array_column($lines, 'kind'));
        $this->assertSame('GitHub answered HTTP 502 to the workflow-run read', $lines[0]['payload']['last_error']);
        $this->assertSame(0, CiAwait::query()->count());
    }

    public function test_a_200_whose_body_is_not_a_run_list_is_a_failed_read_not_a_settle(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([self::RUNS_URL => Http::response(['workflow_runs' => 'nope']), '127.0.0.1:*' => Http::response('ok', 200)]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertStringContainsString('is not a run list with a total_count', (string) CiAwait::query()->sole()->last_error);
        $this->assertSame([], $this->inbox());
    }

    public function test_a_run_list_that_does_not_end_within_the_page_bound_is_a_failed_read(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([self::RUNS_URL => Http::response($this->runs(array_fill(0, 100, ['CI', 'completed', 'success']), total: 5000)), '127.0.0.1:*' => Http::response('ok', 200)]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSentRunsReads(10);
        $this->assertStringContainsString('did not end within 10 pages', (string) CiAwait::query()->sole()->last_error);
        $this->assertSame([], $this->inbox());
    }

    // ---- a repo this install's token cannot read (card#11600) ----------------------------

    /** @return array<string, array{0: int, 1: array<string, string>}> */
    public static function unreadableStatuses(): array
    {
        return [
            '404 (a private repo the token cannot see)' => [404, []],
        ];
    }

    /** @param  array<string, string>  $headers */
    #[DataProvider('unreadableStatuses')]
    public function test_a_registration_whose_read_says_the_repo_cannot_be_read_is_refused_and_stores_nothing(int $status, array $headers): void
    {
        Http::fake([self::RUNS_URL => Http::response(['message' => 'Not Found'], $status, $headers), '127.0.0.1:*' => Http::response('ok', 200)]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA, 'pr' => 7]);

        $this->assertFalse($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame('repo_unreadable', $out->body()['reason']);
        $error = (string) $out->body()['error'];
        $this->assertStringContainsString("GitHub answered HTTP {$status} to the workflow-run read", $error);
        $this->assertStringContainsString('token source: the single GitHub token file, file '.$this->dir.'/github/token', $error);
        $this->assertStringContainsString('[git-credential-map]', $error);
        $this->assertStringContainsString('write_token_path', $error);
        $this->assertStringContainsString('Nothing was stored', $error);
        $this->assertStringNotContainsString('gh-read-token', $error, 'the token itself is never printed');
        $this->assertSame(0, CiAwait::query()->count(), 'nothing is stored');
        $this->assertSame([], $this->inbox(), 'the refused seat is answered by the refusal, not an event');
        $this->assertNoChannelPush();
    }

    /**
     * GitHub's secondary rate limit can answer 403 with neither Retry-After nor
     * X-RateLimit-Remaining: 0, and a token rotated outside the bridge can answer 401 briefly. Neither
     * is final on one read (card#11600 review).
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function unconfirmedStatuses(): array
    {
        return [
            'a header-less 403 whose body names no rate limit' => [403, 'Resource not accessible by integration'],
            'a header-less 403 whose body names a secondary rate limit' => [403, 'You have exceeded a secondary rate limit. Please wait a few minutes before you try again.'],
            'a 401' => [401, 'Bad credentials'],
        ];
    }

    #[DataProvider('unconfirmedStatuses')]
    public function test_a_registration_whose_first_read_answers_401_or_a_header_less_403_is_stored_and_not_refused(int $status, string $message): void
    {
        Http::fake([self::RUNS_URL => Http::response(['message' => $message], $status, ['X-RateLimit-Remaining' => '4999']), '127.0.0.1:*' => Http::response('ok', 200)]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame('unmeasured', $out->body()['result']['state']);
        $this->assertStringContainsString("HTTP {$status}", (string) $out->body()['result']['read_error']);
        $this->assertStringNotContainsString($message, (string) $out->body()['result']['read_error'], 'the body is never printed');
        $await = CiAwait::query()->sole();
        $this->assertNotNull($await->last_error);
        $this->assertNotNull($await->retry_not_before);
        $this->assertGreaterThanOrEqual(60, Carbon::now()->diffInSeconds($await->retry_not_before, false));
        $this->assertSame([], $this->inbox());

        $other = $this->callTool('seat-b', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->assertSame($status === 403 && str_contains($message, 'rate limit') ? 'rate_limited' : 'unreadable_unconfirmed', $other->body()['result']['read_skipped'] ?? null);
        $this->assertSentRunsReads(1);
    }

    #[DataProvider('unconfirmedStatuses')]
    public function test_a_sweep_read_answering_401_or_a_header_less_403_once_keeps_the_await(int $status, string $message): void
    {
        $this->seedAwait('seat-a');
        Http::fake([self::RUNS_URL => Http::response(['message' => $message], $status), '127.0.0.1:*' => Http::response('ok', 200)]);

        $this->runSweep();

        $this->assertSame(1, CiAwait::query()->count());
        $this->assertSame([], $this->inbox());
        $this->assertNoChannelPush();
    }

    /** @return array<string, array{0: int}> */
    public static function confirmableStatuses(): array
    {
        return ['403' => [403], '401' => [401]];
    }

    #[DataProvider('confirmableStatuses')]
    public function test_a_401_or_403_confirmed_by_a_read_at_least_60s_later_ends_the_await_with_one_ci_await_unreadable(int $status): void
    {
        $this->seedAwait('seat-a');
        Http::fake([self::RUNS_URL => Http::response(['message' => 'Forbidden'], $status), '127.0.0.1:*' => Http::response('ok', 200)]);

        $this->runSweep();
        Carbon::setTestNow('2026-10-03T10:00:59.000Z');
        $this->postRunCompleted(self::SHA)->assertOk();
        $this->assertSentRunsReads(1, 'no read of the head before the confirming read is due, not even a delivery\'s');
        $this->assertSame(1, CiAwait::query()->count());

        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();

        $this->assertSentRunsReads(2);
        $this->assertSame(0, CiAwait::query()->count());
        $this->assertSame(['ci_await_unreadable'], array_column($this->inbox(), 'kind'));
        $this->assertSame($status, $this->inbox()[0]['payload']['status']);
    }

    public function test_a_read_that_answers_between_two_403s_resets_the_confirmation(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([
            self::RUNS_URL => Http::sequence()
                ->push(['message' => 'Forbidden'], 403)
                ->push($this->runs([['CI', 'in_progress', null]]))
                ->push(['message' => 'Forbidden'], 403),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $this->runSweep();
        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();
        Carbon::setTestNow('2026-10-03T10:10:00.000Z');
        $this->runSweep();

        $this->assertSentRunsReads(3);
        $this->assertSame(1, CiAwait::query()->count(), 'the second 403 follows an answer, so it confirms nothing');
        $this->assertSame([], $this->inbox());
    }

    public function test_a_401_then_a_403_more_than_a_minute_apart_confirms_nothing_and_the_record_becomes_403(): void
    {
        $this->seedAwait('seat-a');
        Http::fake([
            self::RUNS_URL => Http::sequence()->push(['message' => 'Bad credentials'], 401)->push(['message' => 'Forbidden'], 403),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $this->runSweep();
        $this->assertSame(401, CiAwait::query()->sole()->unconfirmed_status);
        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();

        $this->assertSentRunsReads(2);
        $await = CiAwait::query()->sole();
        $this->assertSame(403, $await->unconfirmed_status, 'the record is the latest status, awaiting its own confirmation');
        $this->assertSame([], $this->inbox());
        $this->assertNoChannelPush();
    }

    public function test_a_refresh_refused_as_unreadable_says_the_seats_existing_await_was_removed(): void
    {
        Http::fake([
            self::RUNS_URL => Http::sequence()
                ->push($this->runs([['CI', 'in_progress', null]]))
                ->push(['message' => 'Not Found'], 404),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);
        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        Carbon::setTestNow('2026-10-03T10:05:00.000Z');

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('repo_unreadable', $out->body()['reason']);
        $this->assertStringContainsString('Your existing await on this head was removed', (string) $out->body()['error']);
        $this->assertStringNotContainsString('Nothing was stored', (string) $out->body()['error']);
        $this->assertSame(0, CiAwait::query()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function finalTokenFaults(): array
    {
        return ['an empty token file' => ['empty'], 'a group-readable token file' => ['insecure']];
    }

    #[DataProvider('finalTokenFaults')]
    public function test_a_token_file_no_reader_can_use_is_refused(string $fault): void
    {
        if ($fault === 'empty') {
            File::put($this->dir.'/github/token', '');
        } else {
            chmod($this->dir.'/github/token', 0o644);
        }
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('repo_unreadable', $out->body()['reason'] ?? null, json_encode($out->body()) ?: '');
        $this->assertSame(0, CiAwait::query()->count());
        $this->assertSentRunsReads(0);
    }

    public function test_a_token_file_absent_behind_a_directory_this_process_cannot_traverse_is_retried(): void
    {
        File::ensureDirectoryExists($this->dir.'/locked');
        config(['bridge.providers.github.token_path' => $this->dir.'/locked/token']);
        chmod($this->dir.'/locked', 0o000);
        try {
            if (is_readable($this->dir.'/locked')) {
                $this->markTestSkipped('this process traverses a 0000 directory (root), so the arm cannot be reached');
            }
            Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);

            $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        } finally {
            chmod($this->dir.'/locked', 0o700);
        }

        $this->assertTrue($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame('unmeasured', $out->body()['result']['state']);
        $this->assertSame(1, CiAwait::query()->count());
    }

    public function test_a_writeback_json_that_does_not_load_is_retried_not_refused(): void
    {
        File::put($this->dir.'/writeback.json', '{not json');
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame('unmeasured', $out->body()['result']['state']);
        $this->assertStringContainsString('writeback.json did not load', (string) $out->body()['result']['read_error']);
        $this->assertSame(1, CiAwait::query()->count());
    }

    public function test_a_registration_with_no_github_read_token_is_refused_naming_where_it_looked(): void
    {
        File::delete($this->dir.'/github/token');
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertFalse($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame('repo_unreadable', $out->body()['reason']);
        $this->assertStringContainsString('no GitHub read token', (string) $out->body()['error']);
        $this->assertStringContainsString('token source: the single GitHub token file, file '.$this->dir.'/github/token', (string) $out->body()['error']);
        $this->assertSame(0, CiAwait::query()->count());
        $this->assertSentRunsReads(0);
    }

    public function test_a_token_file_this_process_cannot_read_is_retried_not_refused(): void
    {
        chmod($this->dir.'/github/token', 0o000);
        if (is_readable($this->dir.'/github/token')) {
            $this->markTestSkipped('this process reads a 0000 file (root), so the unreadable arm cannot be reached');
        }
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame('unmeasured', $out->body()['result']['state']);
        $this->assertSame(1, CiAwait::query()->count(), 'another process (the receiver) may read the file: the await is kept');
    }

    public function test_a_registration_whose_read_is_rate_limited_by_a_403_is_stored_and_waits(): void
    {
        Http::fake([self::RUNS_URL => Http::response(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0']), '127.0.0.1:*' => Http::response('ok', 200)]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame(1, CiAwait::query()->count());
        $this->assertSame([], $this->inbox());
    }

    public function test_a_registration_whose_read_answers_5xx_is_stored_and_waits(): void
    {
        Http::fake([self::RUNS_URL => Http::response(['message' => 'boom'], 503), '127.0.0.1:*' => Http::response('ok', 200)]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame(1, CiAwait::query()->count());
        $this->assertSame([], $this->inbox());
    }

    public function test_a_sweep_read_that_says_the_repo_cannot_be_read_ends_each_await_with_one_ci_await_unreadable(): void
    {
        $this->seedAwait('seat-a', pr: 5);
        $this->seedAwait('seat-b');
        Http::fake([self::RUNS_URL => Http::response(['message' => 'Not Found'], 404), '127.0.0.1:*' => Http::response('ok', 200)]);

        $this->runSweep();
        $this->runSweep();

        $this->assertSame(0, CiAwait::query()->count(), 'an unreadable repo is not waited on');
        $this->assertSentRunsReads(1);
        $lines = $this->inbox();
        $this->assertSame(['ci_await_unreadable', 'ci_await_unreadable'], array_column($lines, 'kind'));
        $this->assertChannelPushes(['seat-a' => ['ci_await_unreadable'], 'seat-b' => ['ci_await_unreadable']]);
        $mine = array_values(array_filter($lines, fn (array $l): bool => ($l['payload']['pr'] ?? null) === 5))[0];
        $this->assertSame('ci:'.self::REPO.'@'.self::SHA, $mine['subject_id']);
        $this->assertEquals([
            'repo' => self::REPO,
            'head_sha' => self::SHA,
            'pr' => 5,
            'registered_at' => '2026-10-03T10:00:00.000Z',
            'status' => 404,
            'error' => 'GitHub answered HTTP 404 to the workflow-run read (token source: the single GitHub token file, file '.$this->dir.'/github/token)',
            'remedy' => CiAwaitService::unreadableRemedy(self::REPO),
        ], $mine['payload']);
    }

    public function test_a_registration_refused_as_unreadable_ends_another_seats_await_on_the_head_with_one_event(): void
    {
        $this->seedAwait('seat-b');
        Http::fake([self::RUNS_URL => Http::response(['message' => 'Not Found'], 404), '127.0.0.1:*' => Http::response('ok', 200)]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('repo_unreadable', $out->body()['reason']);
        $this->assertSame(0, CiAwait::query()->count());
        $this->assertChannelPushes(['seat-b' => ['ci_await_unreadable']]);
    }

    public function test_two_concurrent_reads_that_find_the_repo_unreadable_emit_exactly_once(): void
    {
        $this->seedAwait('seat-a');
        $service = $this->app->make(CiAwaitService::class);
        $reentered = false;
        Http::fake([
            self::RUNS_URL => function () use ($service, &$reentered) {
                if (! $reentered) {
                    $reentered = true;
                    $service->onWorkflowRunCompleted(self::REPO, self::SHA);
                }

                return Http::response(['message' => 'Not Found'], 404);
            },
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $service->onWorkflowRunCompleted(self::REPO, self::SHA);

        $this->assertTrue($reentered, 'the interleaving never happened, so this measured nothing');
        $this->assertSentRunsReads(2);
        $this->assertSame(['ci_await_unreadable'], array_column($this->inbox(), 'kind'));
        $this->assertChannelPushes(['seat-a' => ['ci_await_unreadable']]);
    }

    // ---- expiry --------------------------------------------------------------------------

    public function test_expiry_emits_ci_await_expired_exactly_once(): void
    {
        $this->seedAwait('seat-a', pr: 3);
        Http::fake([self::RUNS_URL => Http::response($this->runs([['CI', 'in_progress', null]])), '127.0.0.1:*' => Http::response('ok', 200)]);

        $this->runSweep();
        $this->assertSame([], $this->inbox(), 'not yet expired');
        $this->assertSentRunsReads(1);

        Carbon::setTestNow('2026-10-03T16:00:00.000Z');
        $this->runSweep();
        $this->runSweep();

        $lines = $this->inbox();
        $this->assertSame(['ci_await_expired'], array_column($lines, 'kind'));
        $this->assertEquals([
            'repo' => self::REPO,
            'head_sha' => self::SHA,
            'pr' => 3,
            'registered_at' => '2026-10-03T10:00:00.000Z',
            'expires_at' => '2026-10-03T16:00:00.000Z',
            'last_read_at' => '2026-10-03T10:00:00.000Z',
            'last_error' => null,
        ], $lines[0]['payload']);
        $this->assertChannelPushes(['seat-a' => ['ci_await_expired']]);
        $this->assertSame(0, CiAwait::query()->count());
    }

    public function test_registration_declares_the_sweep_job(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'queued', null]])]]);

        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $job = ScheduledJob::query()->where('name', CiAwaitSweepJob::INSTANCE)->sole();
        $this->assertSame(CiAwaitSweepJob::NAME, $job->handler);
    }

    // ---- helpers -------------------------------------------------------------------------

    private function callTool(string $agent, string $tool, array $args): DispatchOutcome
    {
        CallingSeatSeal::forANewServingProcess();
        $cfg = new BoardToolsConfig(
            enabled: true, tokenPath: null, boardId: 10, swimlaneId: 4, createStageId: 55,
            sharedSwimlaneId: null, coordBoardId: null, addressTags: [], transport: 'ssh',
        );

        return (new BoardToolDispatcher(new BoardToolsRegistry))->dispatch($tool, $args, $cfg, $agent, CallProvenance::NotSshd, null, CallerReport::unreported());
    }

    private function seedAwait(string $agent, ?int $pr = null, string $sha = self::SHA, int $expiresIn = 21600): void
    {
        CiAwait::query()->create(['agent' => $agent, 'repo' => self::REPO, 'repo_name' => self::REPO, 'head_sha' => $sha, 'pr' => $pr, 'expires_at' => Carbon::now()->addSeconds($expiresIn)]);
    }

    private function runSweep(): JobOutcome
    {
        return $this->app->make(CiAwaitSweepJob::class)->run(new JobContext(CiAwaitSweepJob::INSTANCE, [], null, 300, JobPassSource::Tick));
    }

    /** @return list<string> the head SHAs the runs reads asked for, in order */
    private function readHeads(): array
    {
        return array_values(array_map(static function (array $pair): string {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return (string) $query['head_sha'];
        }, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/repos/octo/widgets/actions/runs'))->all()));
    }

    /**
     * One GitHub fake for the whole test (G-020): each element is one runs read, answered page by page.
     *
     * @param  list<list<array<string, mixed>>>  $reads
     */
    private function fakeGitHub(array $reads): void
    {
        $pages = [];
        foreach ($reads as $read) {
            array_push($pages, ...$read);
        }
        $sequence = Http::sequence();
        foreach ($pages as $page) {
            $sequence->push($page);
        }
        Http::fake([self::RUNS_URL => $sequence, '127.0.0.1:*' => Http::response('ok', 200)]);
    }

    /**
     * Runs `[name, status, conclusion, run_attempt = 1, id = $firstId + index]`.
     *
     * @param  list<array{0: string, 1: string, 2: ?string, 3?: int, 4?: int}>  $runs
     * @return array<string, mixed>
     */
    private function runs(array $runs, ?int $total = null, int $firstId = 1): array
    {
        $list = [];
        foreach (array_values($runs) as $i => $run) {
            [$name, $status, $conclusion] = $run;
            $id = $run[4] ?? $firstId + $i;
            $list[] = ['id' => $id, 'name' => $name, 'head_sha' => self::SHA, 'status' => $status, 'conclusion' => $conclusion, 'event' => 'pull_request', 'run_attempt' => $run[3] ?? 1, 'html_url' => "https://github.com/octo/widgets/actions/runs/{$id}"];
        }

        return ['total_count' => $total ?? count($list), 'workflow_runs' => $list];
    }

    /**
     * A delivery for run `$runId` — by default run 1, which every list fixture here carries, so the
     * overlay of the delivered run changes nothing unless a test means it to.
     */
    private function postRunCompleted(string $sha, int $runId = 1, string $conclusion = 'success', int $attempt = 1): TestResponse
    {
        return $this->postWorkflowRun('completed', $sha, $runId, $conclusion, $attempt);
    }

    private function postWorkflowRun(string $action, string $sha, int $runId = 1, string $conclusion = 'success', int $attempt = 1): TestResponse
    {
        $body = (string) json_encode([
            'action' => $action,
            'workflow_run' => ['id' => $runId, 'name' => 'CI', 'head_sha' => $sha, 'status' => $action, 'conclusion' => $action === 'completed' ? $conclusion : null, 'run_attempt' => $attempt, 'html_url' => "https://github.com/octo/widgets/actions/runs/{$runId}"],
            'repository' => ['full_name' => self::REPO],
            'sender' => ['id' => 4242],
        ]);

        return $this->call('POST', '/webhooks/github?b='.self::REPO, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::HOOK_SECRET),
            'HTTP_X_GITHUB_DELIVERY' => uniqid('d', true),
            'HTTP_X_GITHUB_EVENT' => 'workflow_run',
        ], $body);
    }

    /** @return list<array<string, mixed>> */
    private function inbox(): array
    {
        $path = $this->dir.'/state/inbox.jsonl';
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (string $l): array => (array) json_decode($l, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
            fn (array $line): bool => str_starts_with((string) ($line['kind'] ?? ''), 'ci_'),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function agentInbox(string $agent): array
    {
        $path = $this->dir."/state/inbox-{$agent}.jsonl";

        return is_file($path) ? array_map(fn (string $l): array => (array) json_decode($l, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    }

    private function assertSentRunsReads(int $n, string $message = ''): void
    {
        $this->assertCount($n, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/repos/octo/widgets/actions/runs')), $message);
    }

    private function assertSentRunsReadsMatching(string $path, int $n): void
    {
        $this->assertCount($n, Http::recorded(fn (Request $r): bool => str_contains($r->url(), $path)));
    }

    private function assertNoChannelPush(): void
    {
        $this->assertCount(0, Http::recorded(fn (Request $r): bool => str_starts_with($r->url(), 'http://127.0.0.1:')));
    }

    /** @param  array<string, list<string>>  $expected  agent => kinds pushed to it, in order */
    private function assertChannelPushes(array $expected): void
    {
        $ports = ['seat-a' => 8701, 'seat-b' => 8702];
        $seen = [];
        foreach (Http::recorded(fn (Request $r): bool => str_starts_with($r->url(), 'http://127.0.0.1:')) as [$request]) {
            $agent = array_search((int) parse_url($request->url(), PHP_URL_PORT), $ports, true);
            $seen[$agent][] = json_decode($request->body(), true)['intent']['kind'];
        }
        ksort($seen);
        ksort($expected);
        $this->assertSame($expected, $seen);
    }
}
