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
use Illuminate\Http\Client\ConnectionException;
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

    /** The run list only — `?` is literal to `Str::is`, so a run's jobs read is not matched. */
    private const RUNS_URL = 'api.github.com/repos/octo/widgets/actions/runs?*';

    private const JOBS_URL = 'api.github.com/repos/octo/widgets/actions/runs/*/jobs*';

    private const HOOK_SECRET = 'ci-await-hook-secret'; // gitleaks:allow — test fixture

    private string $dir;

    /**
     * What a run's jobs read answers: `run id => [job status, …]`. A run not named here answers one
     * completed job, so a test that is not about jobs reads every job as finished.
     *
     * @var array<int, list<string>>
     */
    private array $jobs = [];

    /** @var array<int, int> run id => the HTTP status its jobs read is refused with; 0: no answer at all */
    private array $jobsRefused = [];

    /**
     * What a one-run read (`GET …/actions/runs/{id}`, card#12197) answers: `run id => HTTP status`.
     * A run not named here answers 200 on {@see SHA} — it still exists.
     *
     * @var array<int, int>
     */
    private array $runReads = [];

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
        // Registered first, so it answers every jobs read: a later fake's catch-all cannot take one.
        Http::fake([self::JOBS_URL => function (Request $r) {
            $runId = (int) explode('/', (string) parse_url($r->url(), PHP_URL_PATH))[6];

            if (($this->jobsRefused[$runId] ?? null) === 0) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return isset($this->jobsRefused[$runId])
                ? Http::response(['message' => 'refused'], $this->jobsRefused[$runId])
                : Http::response($this->jobsOf($runId));
        }]);
        // A run's own read; registered after the jobs fake, so a jobs URL never reaches it.
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs/*' => function (Request $r) {
            $runId = (int) explode('/', (string) parse_url($r->url(), PHP_URL_PATH))[6];
            $status = $this->runReads[$runId] ?? 200;

            return Http::response($status === 200 ? ['id' => $runId, 'head_sha' => self::SHA, 'status' => 'in_progress'] : ['message' => 'Not Found'], $status);
        }]);
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
        $this->fakeGitHub([[$this->runs([['CI', 'in_progress', null]])], [$this->runs([['CI', 'in_progress', null]], sha: self::OTHER_SHA)]]);

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

    public function test_a_repo_declared_to_have_no_ci_is_refused_without_a_read_and_stores_nothing(): void
    {
        config(['bridge.ci_await.no_ci_repos' => ['Octo/Widgets']]);
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertFalse($out->ok);
        $this->assertSame('repo_not_ci', $out->body()['reason']);
        $this->assertStringContainsString('BRIDGE_CI_AWAIT_NO_CI_REPOS', (string) $out->body()['error']);
        $this->assertStringContainsString('Nothing was stored', (string) $out->body()['error']);
        $this->assertSame(0, CiAwait::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_no_ci_repo_entry_that_is_not_owner_name_refuses_every_call_as_an_install_fault(): void
    {
        config(['bridge.ci_await.no_ci_repos' => ['agent-roundtable']]);
        Http::fake();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertFalse($out->ok);
        $this->assertSame('install_fault.ci_await_config_invalid', $out->body()['reason']);
        $this->assertStringContainsString("BRIDGE_CI_AWAIT_NO_CI_REPOS lists 'agent-roundtable'", (string) $out->body()['error']);
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
                'basis' => 'github_read',
                'runs' => [
                    ['workflow' => 'CI', 'conclusion' => 'success', 'html_url' => 'https://github.com/octo/widgets/actions/runs/1', 'superseded' => false],
                    ['workflow' => 'E2E', 'conclusion' => 'failure', 'html_url' => 'https://github.com/octo/widgets/actions/runs/2', 'superseded' => false],
                ],
                'all_terminal' => true,
                'runs_verdict' => 'red',
                'non_success_runs' => [['workflow' => 'E2E', 'conclusion' => 'failure', 'html_url' => 'https://github.com/octo/widgets/actions/runs/2']],
                'measured_at' => '2026-10-03T10:00:00.000Z',
                'source' => 'wait',
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

    /**
     * card#12197: a list that does not carry the run just delivered is partial — it is not added to
     * the list and settled on. The sweep's read, once the list carries it, settles the head.
     */
    public function test_a_list_that_does_not_carry_the_delivered_run_is_a_failed_read_and_the_sweep_settles_it(): void
    {
        $this->seedAwait('seat-a');
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])], [$this->runs([['CI', 'completed', 'success'], ['E2E', 'completed', 'success', 1, 7]])]]);

        $this->postRunCompleted(self::SHA, runId: 7)->assertOk();

        $this->assertSame([], $this->inbox());
        $this->assertStringContainsString('omits 1 run(s) already known on this head (run 7)', (string) CiAwait::query()->sole()->last_error);

        $this->sweepAt('2026-10-03T10:05:00.000Z');

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

    // ---- only runs a commit started (card#11958) ------------------------------------------

    /** A nightly run still going on the awaited head is not this commit's CI and holds nothing open. */
    public function test_a_scheduled_run_still_in_progress_does_not_hold_the_settle(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success', 1, 1, 'push'], ['Nightly', 'in_progress', null, 1, 2, 'schedule']])]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('settled', $out->body()['result']['state'], json_encode($out->body()) ?: '');
        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertSame('green', $lines[0]['payload']['runs_verdict']);
        $this->assertSame(['CI'], array_column($lines[0]['payload']['runs'], 'workflow'), 'a run no commit started is not listed');
    }

    /** A CVE published overnight reds the scheduled audit; the awaited commit changed nothing, so its wait is not red. */
    public function test_scheduled_and_dispatched_failures_do_not_turn_the_verdict_red(): void
    {
        $this->seedAwait('seat-a');
        $this->fakeGitHub([[$this->runs([
            ['CI', 'completed', 'success', 1, 1, 'pull_request'],
            ['Security', 'completed', 'failure', 1, 2, 'schedule'],
            ['Matrix', 'completed', 'failure', 1, 3, 'workflow_dispatch'],
        ])]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertSame('green', $lines[0]['payload']['runs_verdict']);
        $this->assertSame([], $lines[0]['payload']['non_success_runs']);
        $this->assertStringContainsString('2 run(s) no commit started (not push or pull_request) not counted', $lines[0]['summary']);
    }

    /**
     * No run a commit started is no CI of this commit's yet — the same as a head with no runs at
     * all, which keeps waiting (the overdue cue, then the expiry, end it).
     */
    public function test_a_head_whose_only_runs_are_scheduled_or_dispatched_keeps_waiting(): void
    {
        $this->fakeGitHub([[$this->runs([['Security', 'completed', 'failure', 1, 1, 'schedule'], ['Matrix', 'completed', 'success', 1, 2, 'workflow_dispatch']])]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('waiting', $out->body()['result']['state']);
        $this->assertSame(0, $out->body()['result']['runs_total'], 'runs_total counts the runs the settle counts');
        $this->assertSame(1, CiAwait::query()->count());
        $this->assertSame([], $this->inbox());
    }

    /**
     * One workflow on `push` AND `schedule` (this repo's security.yml): the nightly run is the newer
     * run of the workflow on main's tip. It must not supersede the push run that judged the commit —
     * the commit's own failure still turns the wait red.
     */
    public function test_a_commit_runs_failure_is_red_though_a_newer_scheduled_run_of_its_workflow_passed(): void
    {
        $this->fakeGitHub([[$this->runs([
            ['Security', 'completed', 'failure', 1, 1, 'push'],
            ['CI', 'completed', 'success', 1, 2, 'pull_request'],
            ['Security', 'completed', 'success', 1, 3, 'schedule'],
        ])]]);

        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertSame('red', $lines[0]['payload']['runs_verdict']);
        $this->assertEquals([['workflow' => 'Security', 'conclusion' => 'failure', 'html_url' => 'https://github.com/octo/widgets/actions/runs/1']], $lines[0]['payload']['non_success_runs']);
    }

    /** The delivered run carries its event: a scheduled failure the list does not show yet is not counted either. */
    public function test_a_delivered_scheduled_run_the_list_does_not_carry_yet_is_not_counted(): void
    {
        $this->seedAwait('seat-a');
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success', 1, 1, 'push']])]]);

        $this->postRunCompleted(self::SHA, runId: 7, conclusion: 'failure', event: 'schedule')->assertOk();

        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertSame('green', $lines[0]['payload']['runs_verdict']);
        $this->assertCount(1, $lines[0]['payload']['runs']);
    }

    // ---- a run list missing runs already known on the head (card#12197) ---------------------

    /** Eight runs: run 1 completed, runs 2-8 still going — what the first read of sola-backend#950 saw. */
    private function eightRunsMostlyOpen(): array
    {
        return $this->runs(array_map(static fn (int $i): array => ["W{$i}", $i === 1 ? 'completed' : 'in_progress', $i === 1 ? 'success' : null], range(1, 8)));
    }

    /**
     * rt#590 (sola-backend#950): a read saw eight runs, a later read of the same head listed only one
     * of them, completed. That one-run list is not the head's CI finishing, so nothing is sent; the
     * await is kept, recorded as a failed read, and read again.
     */
    public function test_a_later_read_listing_fewer_runs_than_an_earlier_one_is_a_failed_read_not_a_settle(): void
    {
        $this->fakeGitHub([[$this->eightRunsMostlyOpen()], [$this->runs([['DL traceability', 'completed', 'success', 1, 3]])]]);
        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->assertSame('waiting', $out->body()['result']['state']);
        $this->assertSame(8, $out->body()['result']['runs_total']);

        $this->sweepAt('2026-10-03T10:05:00.000Z');

        $this->assertSentRunsReads(2);
        $this->assertSame([], $this->inbox(), 'a one-run list for a head with eight known runs settled the await');
        $this->assertNoChannelPush();
        $await = CiAwait::query()->sole();
        $this->assertStringContainsString('omits 7 run(s) already known on this head', (string) $await->last_error);
        $this->assertStringContainsString('run 1, run 2, run 4', (string) $await->last_error);
    }

    /** An empty list for a head that had runs settles nothing, and is recorded as the failed read it is. */
    public function test_an_empty_list_after_a_non_empty_read_is_a_failed_read_not_a_settle(): void
    {
        $this->fakeGitHub([[$this->eightRunsMostlyOpen()], [$this->runs([])]]);
        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->sweepAt('2026-10-03T10:05:00.000Z');

        $this->assertSame([], $this->inbox());
        $this->assertStringContainsString('omits 8 run(s) already known on this head', (string) CiAwait::query()->sole()->last_error);
    }

    /**
     * The delivered run was appended to a list that did not carry it, so an EMPTY list read for a
     * delivery became a one-run list holding just the delivered run — completed, and so settled.
     * All eight runs known on the head, the delivered one included, make it a failed read instead.
     */
    public function test_an_empty_list_read_for_a_delivery_does_not_settle_on_the_delivered_run_alone(): void
    {
        $this->fakeGitHub([[$this->eightRunsMostlyOpen()], [$this->runs([])]]);
        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->postRunCompleted(self::SHA, runId: 3)->assertOk();

        $this->assertSentRunsReads(2);
        $this->assertSame([], $this->inbox(), 'the delivered run alone settled a head with eight known runs');
        $this->assertSame(1, CiAwait::query()->count());
        $this->assertStringContainsString('omits 8 run(s) already known on this head', (string) CiAwait::query()->sole()->last_error);
    }

    /**
     * A run the bridge was told of by a `workflow_run` delivery is known before any read: a list that
     * omits it — here the registration's own read — is a failed read, answered `unmeasured`.
     */
    public function test_a_run_a_delivery_reported_that_the_list_omits_holds_the_await(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);
        $this->postWorkflowRun('requested', self::SHA, runId: 5)->assertOk();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('unmeasured', $out->body()['result']['state'], json_encode($out->body()) ?: '');
        $this->assertStringContainsString('omits 1 run(s) already known on this head', (string) $out->body()['result']['read_error']);
        $this->assertStringContainsString('run 5', (string) $out->body()['result']['read_error']);
        $this->assertSame([], $this->inbox());
        $this->assertSame(1, CiAwait::query()->count());
    }

    /** A delivered run no commit started (a nightly) is not held against the list: the settle never counts it. */
    public function test_a_delivered_scheduled_run_the_list_omits_does_not_hold_the_await(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);
        $this->postWorkflowRun('requested', self::SHA, runId: 5, event: 'schedule')->assertOk();

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('settled', $out->body()['result']['state'], json_encode($out->body()) ?: '');
    }

    /**
     * rt#590 c-6344280919: every early ci_settled carried ONE run, the one whose completion had just
     * been delivered, and the list read for it was empty. No earlier read saw any run here — the
     * registration read an empty list too — yet the delivered run itself is known, so an empty list
     * is partial.
     */
    public function test_an_empty_list_read_for_a_delivery_with_no_earlier_read_of_any_run_does_not_settle(): void
    {
        $this->fakeGitHub([[$this->runs([])], [$this->runs([])]]);
        $this->assertSame('waiting', $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA])->body()['result']['state']);

        $this->postRunCompleted(self::SHA, runId: 1)->assertOk();

        $this->assertSentRunsReads(2);
        $this->assertSame([], $this->inbox(), 'the delivered run alone settled the head');
        $this->assertStringContainsString('omits 1 run(s) already known on this head (run 1)', (string) CiAwait::query()->sole()->last_error);
    }

    /**
     * The same shape where the list DOES carry the delivered run, and only it. Runs 2-8 are known
     * only because their `workflow_run.requested` deliveries reached the bridge, which records every
     * delivery on the head (ci_head_runs) — without those deliveries nothing the bridge holds says
     * they exist, and this guard cannot see them.
     */
    public function test_a_list_of_only_the_delivered_run_does_not_settle_while_requested_runs_are_missing(): void
    {
        $this->fakeGitHub([[$this->runs([])], [$this->runs([['W1', 'completed', 'success']])]]);
        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        foreach (range(2, 8) as $runId) {
            $this->postWorkflowRun('requested', self::SHA, runId: $runId)->assertOk();
        }

        $this->postRunCompleted(self::SHA, runId: 1)->assertOk();

        $this->assertSentRunsReads(2);
        $this->assertSame([], $this->inbox(), 'a list of only the delivered run settled a head with seven requested runs');
        $this->assertStringContainsString('omits 7 run(s) already known on this head', (string) CiAwait::query()->sole()->last_error);
    }

    /**
     * The toolkit reproduction: the registration read four runs, none completed; the read for the
     * first run's completion listed only that run. The registration's own read made the other three
     * known.
     */
    public function test_runs_the_registration_read_are_known_to_the_read_a_later_delivery_makes(): void
    {
        $fourOpen = $this->runs([['CI', 'in_progress', null], ['Lint', 'queued', null], ['Changelog card entry', 'in_progress', null], ['Docs', 'queued', null]]);
        $this->fakeGitHub([[$fourOpen], [$this->runs([['Changelog card entry', 'completed', 'success', 1, 3]])]]);
        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->assertSame(4, $out->body()['result']['runs_total']);
        $this->assertSame(0, $out->body()['result']['runs_completed']);
        $this->assertSame([1, 2, 3, 4], CiAwait::query()->sole()->known_run_ids);

        $this->postRunCompleted(self::SHA, runId: 3)->assertOk();

        $this->assertSentRunsReads(2);
        $this->assertSame([], $this->inbox(), 'a one-run list settled a head whose registration read four runs');
        $this->assertStringContainsString('omits 3 run(s) already known on this head (run 1, run 2, run 4)', (string) CiAwait::query()->sole()->last_error);
    }

    /** @return array<string, array{string}> */
    public static function outerRunStatuses(): array
    {
        return ['the outer list\'s run is open' => ['in_progress'], 'the outer list\'s run is completed' => ['completed']];
    }

    /**
     * Review of PR #969: two reads of one head overlap. The inner one answers eight runs and records
     * them; the outer one, which loaded the await before that, answers one open run. Its write must
     * not replace the eight with its one — or a third read listing that one run completed settles
     * with seven still open — and its own read must be judged against the eight, read under the
     * lock, or an outer list of that one run already completed settles on the stale snapshot.
     */
    #[DataProvider('outerRunStatuses')]
    public function test_an_overlapping_read_neither_shrinks_the_known_runs_nor_passes_on_its_stale_snapshot(string $outerStatus): void
    {
        $this->seedAwait('seat-a');
        $service = $this->app->make(CiAwaitService::class);
        $reads = 0;
        Http::fake([
            self::RUNS_URL => function () use ($service, &$reads, $outerStatus) {
                $n = ++$reads;
                if ($n === 1) {
                    $service->onWorkflowRunCompleted(self::REPO, self::SHA);
                }

                return Http::response(match ($n) {
                    1 => $this->runs([['W1', $outerStatus, $outerStatus === 'completed' ? 'success' : null]]),
                    2 => $this->eightRunsMostlyOpen(),
                    default => $this->runs([['W1', 'completed', 'success']]),
                });
            },
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);

        $service->onWorkflowRunCompleted(self::REPO, self::SHA);
        $this->assertSame(2, $reads, 'the inner read never ran inside the outer one, so this measured nothing');
        $this->assertSame([], $this->inbox(), 'the outer read settled on its stale snapshot with seven runs open');

        $service->onWorkflowRunCompleted(self::REPO, self::SHA);

        $this->assertSame(3, $reads);
        $this->assertSame([], $this->inbox(), 'a one-run list settled a head with eight known runs');
        $await = CiAwait::query()->sole();
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $await->known_run_ids, 'an overlapping read replaced what the other recorded');
        $this->assertStringContainsString('omits 7 run(s) already known on this head', (string) $await->last_error);
    }

    /**
     * A run deleted after it was seen (the UI, `gh run delete`, a retention job) leaves the head's
     * list for good. GitHub answers 404 for it, so it is gone: dropped from what is known, and the
     * rest of the list settles the head.
     */
    public function test_a_known_run_github_answers_404_for_is_gone_and_the_rest_of_the_list_settles(): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'in_progress', null], ['Flaky', 'in_progress', null]])], [$this->runs([['CI', 'completed', 'success']])]]);
        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->runReads[2] = 404;

        $this->sweepAt('2026-10-03T10:05:00.000Z');

        $this->assertSentRunsReadsMatching('/actions/runs/2', 1);
        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertSame(['CI'], array_column($lines[0]['payload']['runs'], 'workflow'));
    }

    /** @return array<string, array{int, string}> */
    public static function missingRunAnswers(): array
    {
        return [
            'GitHub still has it' => [200, 'GitHub still has each of them'],
            'the run read fails' => [502, 'GitHub answered HTTP 502 to the read of run 2'],
        ];
    }

    /** Fail closed: a missing known run that GitHub still has, or that could not be read, keeps the list partial. */
    #[DataProvider('missingRunAnswers')]
    public function test_a_missing_known_run_not_shown_gone_keeps_the_read_failed(int $status, string $error): void
    {
        $this->fakeGitHub([[$this->runs([['CI', 'in_progress', null], ['E2E', 'in_progress', null]])], [$this->runs([['CI', 'completed', 'success']])]]);
        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->runReads[2] = $status;

        $this->sweepAt('2026-10-03T10:05:00.000Z');

        $this->assertSame([], $this->inbox());
        $await = CiAwait::query()->sole();
        $this->assertStringContainsString($error, (string) $await->last_error);
        $this->assertSame([1, 2], $await->known_run_ids, 'a run not shown gone is still known');
    }

    /** More missing runs than the lookup bound: a partial list, with no run read at all. */
    public function test_more_missing_runs_than_the_lookup_bound_are_not_looked_up(): void
    {
        $this->fakeGitHub([[$this->eightRunsMostlyOpen()], [$this->runs([['W1', 'completed', 'success']])]]);
        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->sweepAt('2026-10-03T10:05:00.000Z');

        $this->assertSame([], $this->inbox());
        $this->assertStringContainsString('more than '.CiAwaitService::MISSING_RUNS_LOOKED_UP.' are missing, so none was looked up', (string) CiAwait::query()->sole()->last_error);
        $this->assertSentRunsReadsMatching('/actions/runs/', 0);
    }

    /** A row of another head in the head's list is not that head's list — and its id is never recorded as one of its runs. */
    public function test_a_list_row_of_another_head_is_a_failed_read_and_records_nothing(): void
    {
        $this->fakeGitHub([[['total_count' => 2, 'workflow_runs' => [
            ...$this->runs([['CI', 'completed', 'success']])['workflow_runs'],
            ...$this->runs([['Other', 'completed', 'success', 1, 9]], sha: self::OTHER_SHA)['workflow_runs'],
        ]]]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('unmeasured', $out->body()['result']['state'], json_encode($out->body()) ?: '');
        $this->assertStringContainsString('carries run 9, whose head_sha is not the one asked for', (string) $out->body()['result']['read_error']);
        $this->assertSame([], $this->inbox());
        $this->assertNull(CiAwait::query()->sole()->known_run_ids);
    }

    /** The normal path: every known run listed again, each terminal — one ci_settled, carrying all eight. */
    public function test_a_read_listing_every_known_run_terminal_settles_once(): void
    {
        $allDone = $this->runs(array_map(static fn (int $i): array => ["W{$i}", 'completed', 'success'], range(1, 8)));
        $this->fakeGitHub([[$this->eightRunsMostlyOpen()], [$allDone]]);
        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->sweepAt('2026-10-03T10:05:00.000Z');
        $this->sweepAt('2026-10-03T10:10:00.000Z');

        $this->assertSentRunsReads(2);
        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertCount(8, $lines[0]['payload']['runs']);
        $this->assertChannelPushes(['seat-a' => ['ci_settled']]);
    }

    /**
     * The partial list's way out: once a read lists every known run again and
     * each is terminal, the await settles — exactly once, carrying all eight.
     */
    public function test_once_every_known_run_is_listed_and_terminal_the_await_settles_once(): void
    {
        $allDone = $this->runs(array_map(static fn (int $i): array => ["W{$i}", 'completed', 'success'], range(1, 8)));
        $this->fakeGitHub([[$this->eightRunsMostlyOpen()], [$this->runs([['W3', 'completed', 'success', 1, 3]])], [$allDone]]);
        $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], CiAwait::query()->sole()->known_run_ids);

        $this->sweepAt('2026-10-03T10:05:00.000Z');
        $this->assertSame([], $this->inbox());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], CiAwait::query()->sole()->known_run_ids, 'a partial list must not shrink what is known');

        $this->sweepAt('2026-10-03T10:10:00.000Z');
        $this->sweepAt('2026-10-03T10:15:00.000Z');

        $this->assertSentRunsReads(3);
        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertCount(8, $lines[0]['payload']['runs']);
        $this->assertSame('green', $lines[0]['payload']['runs_verdict']);
        $this->assertChannelPushes(['seat-a' => ['ci_settled']]);
        $this->assertSame(0, CiAwait::query()->count());
    }

    // ---- a completed run whose job is still running (card#12072) --------------------------

    /**
     * rt#638, as reported from the Actions API: the run list said `completed` / `success` while the run's own jobs read
     * still had a job `in_progress` — the read `ci-read` makes. A registration then must wait, not
     * send a GREEN ci_settled and forget the await.
     */
    public function test_a_registration_whose_run_is_completed_but_a_job_still_runs_waits_and_sends_nothing(): void
    {
        $this->jobs = [1 => ['completed', 'in_progress']];
        $this->fakeGitHub([[$this->runs([['SAST + secret scan', 'completed', 'success']])]]);

        $out = $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertSame('waiting', $out->body()['result']['state'], json_encode($out->body()) ?: '');
        $this->assertSame([], $this->inbox());
        $this->assertNoChannelPush();
        $this->assertSame(1, CiAwait::query()->count(), 'the await is kept for the read that sees the job finish');
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/actions/runs/1/jobs')));
    }

    /** @return array<string, array{0: string}> every job status GitHub documents besides `completed` */
    public static function openJobStatuses(): array
    {
        return ['queued' => ['queued'], 'in_progress' => ['in_progress'], 'waiting' => ['waiting'], 'requested' => ['requested'], 'pending' => ['pending']];
    }

    /** Any status but `completed` holds the await: the test is "is it completed", not a list of open ones. */
    #[DataProvider('openJobStatuses')]
    public function test_a_job_in_any_status_but_completed_holds_the_await(string $status): void
    {
        $this->jobs = [1 => [$status]];
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);

        $this->assertSame('waiting', $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA])->body()['result']['state']);
        $this->assertSame([], $this->inbox());
    }

    /** The same sequence on a delivery: the run's completion arrives while its job still runs. */
    public function test_a_delivery_whose_run_is_completed_but_a_job_still_runs_sends_nothing(): void
    {
        $this->seedAwait('seat-a');
        $this->jobs = [1 => ['in_progress']];
        $this->fakeGitHub([[$this->runs([['SAST + secret scan', 'completed', 'success']])]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame([], $this->inbox());
        $this->assertNoChannelPush();
        $await = CiAwait::query()->sole();
        $this->assertNotNull($await->last_read_at);
        $this->assertNull($await->last_error);
    }

    /**
     * The follow-up the report lost: nothing more is delivered once the run has said `completed`,
     * so the sweep's read after the job finishes is what settles it — exactly once, and a later
     * pass and a re-delivery send nothing more.
     */
    public function test_once_the_job_finishes_the_sweep_sends_exactly_one_ci_settled(): void
    {
        $this->jobs = [1 => ['completed', 'in_progress']];
        $this->fakeGitHub(array_fill(0, 4, [$this->runs([['SAST + secret scan', 'completed', 'success']])]));
        $this->assertSame('waiting', $this->callTool('seat-a', 'ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA])->body()['result']['state']);

        Carbon::setTestNow('2026-10-03T10:05:00.000Z');
        $this->runSweep();
        $this->assertSame([], $this->inbox(), 'the job is still running at this pass');

        $this->jobs = [1 => ['completed', 'completed']];
        Carbon::setTestNow('2026-10-03T10:10:00.000Z');
        $this->runSweep();
        Carbon::setTestNow('2026-10-03T10:15:00.000Z');
        $this->runSweep();
        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame(0, CiAwait::query()->count());
        $lines = $this->inbox();
        $this->assertSame(['ci_settled'], array_column($lines, 'kind'));
        $this->assertSame('green', $lines[0]['payload']['runs_verdict']);
        $this->assertChannelPushes(['seat-a' => ['ci_settled']]);
    }

    /**
     * card#12089: a job that holds the wait is recorded on the await, and the expiry names it — it
     * does not say a run was unfinished, nor that a read failed.
     */
    public function test_an_await_held_by_an_open_job_records_it_and_its_expiry_names_the_job(): void
    {
        $this->seedAwait('seat-a', pr: 3);
        $this->jobs = [1 => ['completed', 'in_progress', 'queued']];
        $this->fakeGitHub([[$this->runs([['SAST + secret scan', 'completed', 'success']])]]);

        $this->runSweep();

        $open = ['count' => 2, 'first' => [
            'workflow' => 'SAST + secret scan', 'run_id' => 1, 'html_url' => 'https://github.com/octo/widgets/actions/runs/1', 'job' => 'job-1', 'status' => 'in_progress',
        ]];
        $await = CiAwait::query()->sole();
        $this->assertNull($await->last_error);
        $this->assertEquals($open, $await->open_jobs);

        Carbon::setTestNow('2026-10-03T16:00:00.000Z');
        $this->runSweep();

        $lines = $this->inbox();
        $this->assertSame(['ci_await_expired'], array_column($lines, 'kind'));
        $this->assertEquals($open, $lines[0]['payload']['open_jobs']);
        $this->assertNull($lines[0]['payload']['last_error']);
        $summary = $lines[0]['summary'];
        $this->assertStringContainsString('every run and job', $summary);
        $this->assertStringContainsString('job-1 (in_progress)', $summary);
        $this->assertStringContainsString('SAST + secret scan', $summary);
        $this->assertStringContainsString('1 more', $summary);
        $this->assertStringNotContainsString('every workflow run', $summary);
        $this->assertStringNotContainsString('failed', $summary);
    }

    /** The record is the LAST read's: a later read that finds no job open, or that fails, clears it. */
    public function test_a_later_read_that_finds_no_open_job_or_fails_clears_the_record(): void
    {
        $this->seedAwait('seat-a');
        $this->jobs = [1 => ['in_progress']];
        $completed = [$this->runs([['CI', 'completed', 'success']])];
        $this->fakeGitHub([$completed, $completed, $completed, [$this->runs([['CI', 'in_progress', null, 2]])]]);

        $this->sweepAt('2026-10-03T10:00:00.000Z');
        $this->assertSame(1, CiAwait::query()->sole()->open_jobs['count'] ?? null);

        $this->jobsRefused = [1 => 502];
        $this->sweepAt('2026-10-03T10:05:00.000Z');
        $await = CiAwait::query()->sole();
        $this->assertNull($await->open_jobs, 'a failed read found nothing open');
        $this->assertNotNull($await->last_error);

        $this->jobsRefused = [];
        $this->sweepAt('2026-10-03T10:10:00.000Z');
        $this->assertSame(1, CiAwait::query()->sole()->open_jobs['count'] ?? null);

        $this->sweepAt('2026-10-03T10:15:00.000Z');
        $this->assertNull(CiAwait::query()->sole()->open_jobs, 'a re-run holds the head open by its run, not by the old job');
        $this->assertSame([], $this->inbox());
    }

    /** Only the deciding runs' jobs are read, and only once the run list already says settled. */
    public function test_jobs_are_read_only_for_deciding_runs_of_a_head_the_run_list_says_is_settled(): void
    {
        $this->seedAwait('seat-a');
        $this->fakeGitHub([
            [$this->runs([['CI', 'in_progress', null]])],
            [$this->runs([['CI', 'completed', 'failure', 1, 1], ['CI', 'completed', 'success', 1, 2], ['Nightly', 'completed', 'success', 1, 3, 'schedule']])],
        ]);

        $this->runSweep();
        $this->assertSentRunsReads(1);
        $this->assertCount(0, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/jobs')), 'a head the run list holds open needs no jobs read');

        CiAwait::query()->update(['last_read_at' => null]);
        $this->runSweep();

        $jobReads = array_map(static fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_PATH), Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/jobs'))->all());
        $this->assertSame(['/repos/octo/widgets/actions/runs/2/jobs'], array_values($jobReads), 'run 1 is superseded by run 2 and run 3 no commit started');
        $this->assertSame(['ci_settled'], array_column($this->inbox(), 'kind'));
    }

    /** A jobs read that fails is a failed read: nothing is sent and the await is kept with the error. */
    public function test_a_jobs_read_that_fails_keeps_the_await_and_sends_nothing(): void
    {
        $this->seedAwait('seat-a');
        $this->jobsRefused = [1 => 502];
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame([], $this->inbox());
        $this->assertSame('GitHub answered HTTP 502 to the jobs read of run 1', CiAwait::query()->sole()->last_error);
    }

    public function test_a_jobs_read_github_does_not_answer_names_the_jobs_read(): void
    {
        $this->seedAwait('seat-a');
        $this->jobsRefused = [1 => 0];
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame([], $this->inbox());
        $this->assertSame('GitHub did not answer the jobs read of run 1: cURL error 28: Operation timed out', CiAwait::query()->sole()->last_error);
    }

    /**
     * A 404 on a jobs read is NOT the repo being unreadable: the same token just listed the run, so
     * the run went away between the reads. It is retried, never ended as `ci_await_unreadable`.
     */
    public function test_a_jobs_read_answering_404_is_retried_not_ended_as_unreadable(): void
    {
        $this->seedAwait('seat-a');
        $this->jobsRefused = [1 => 404];
        $this->fakeGitHub([[$this->runs([['CI', 'completed', 'success']])]]);

        $this->postRunCompleted(self::SHA)->assertOk();

        $this->assertSame([], $this->inbox(), 'no ci_await_unreadable');
        $this->assertStringContainsString('404', (string) CiAwait::query()->sole()->last_error);
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

        $this->fakeGitHub([[$this->runs([['CI', 'queued', null]], firstId: 1, sha: self::OTHER_SHA)]]);
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
            'open_jobs' => null,
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

    private function sweepAt(string $at): JobOutcome
    {
        Carbon::setTestNow($at);

        return $this->runSweep();
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
        }, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/repos/octo/widgets/actions/runs?'))->all()));
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
     * Runs `[name, status, conclusion, run_attempt = 1, id = $firstId + index, event = 'pull_request']`.
     *
     * @param  list<array{0: string, 1: string, 2: ?string, 3?: int, 4?: int, 5?: string}>  $runs
     * @return array<string, mixed>
     */
    private function runs(array $runs, ?int $total = null, int $firstId = 1, string $sha = self::SHA): array
    {
        $list = [];
        foreach (array_values($runs) as $i => $run) {
            [$name, $status, $conclusion] = $run;
            $id = $run[4] ?? $firstId + $i;
            $list[] = ['id' => $id, 'name' => $name, 'head_sha' => $sha, 'status' => $status, 'conclusion' => $conclusion, 'event' => $run[5] ?? 'pull_request', 'run_attempt' => $run[3] ?? 1, 'html_url' => "https://github.com/octo/widgets/actions/runs/{$id}"];
        }

        return ['total_count' => $total ?? count($list), 'workflow_runs' => $list];
    }

    /** @return array<string, mixed> run `$runId`'s jobs, as GitHub's jobs read answers them ({@see $jobs}) */
    private function jobsOf(int $runId): array
    {
        $list = [];
        foreach ($this->jobs[$runId] ?? ['completed'] as $i => $status) {
            $id = $runId * 1000 + $i;
            $list[] = ['id' => $id, 'run_id' => $runId, 'name' => "job-{$i}", 'status' => $status, 'conclusion' => $status === 'completed' ? 'success' : null, 'html_url' => "https://github.com/octo/widgets/actions/runs/{$runId}/job/{$id}"];
        }

        return ['total_count' => count($list), 'jobs' => $list];
    }

    /**
     * A delivery for run `$runId` — by default run 1, which every list fixture here carries, so the
     * overlay of the delivered run changes nothing unless a test means it to.
     */
    private function postRunCompleted(string $sha, int $runId = 1, string $conclusion = 'success', int $attempt = 1, string $event = 'pull_request'): TestResponse
    {
        return $this->postWorkflowRun('completed', $sha, $runId, $conclusion, $attempt, $event);
    }

    private function postWorkflowRun(string $action, string $sha, int $runId = 1, string $conclusion = 'success', int $attempt = 1, string $event = 'pull_request'): TestResponse
    {
        $body = (string) json_encode([
            'action' => $action,
            'workflow_run' => ['id' => $runId, 'name' => 'CI', 'head_sha' => $sha, 'event' => $event, 'status' => $action, 'conclusion' => $action === 'completed' ? $conclusion : null, 'run_attempt' => $attempt, 'html_url' => "https://github.com/octo/widgets/actions/runs/{$runId}"],
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
        $this->assertCount($n, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/repos/octo/widgets/actions/runs?')), $message);
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
