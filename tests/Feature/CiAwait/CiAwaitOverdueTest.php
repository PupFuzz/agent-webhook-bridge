<?php

namespace Tests\Feature\CiAwait;

use App\Bridge\CiAwait\CiAwaitService;
use App\Bridge\CiAwait\CiHeadRunTracker;
use App\Bridge\CiAwait\CiHeadSettlementLedger;
use App\Bridge\CiAwait\HeadRuns;
use App\Bridge\CiAwait\OverdueDeadline;
use App\Bridge\ClientUpdate\CallerReport;
use App\Bridge\Scheduling\Handlers\CiAwaitSweepJob;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\DispatchOutcome;
use App\Models\CiAwait;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\TestCase;

/**
 * `ci_await_overdue` (card#11674): an await still unsettled past the repo's normal CI time gets ONE
 * event, the await stays, and its terminal event still follows. Registration through the
 * board-tools dispatcher, the overdue pass through the real sweep job, the repo's history seeded
 * as `ci_head_runs` rows — GitHub and the seat's channel faked, every other request refused.
 */
class CiAwaitOverdueTest extends TestCase
{
    use RefreshDatabase;

    private const REPO = 'octo/widgets';

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const PORT = 8721;

    private string $dir;

    /** What the faked runs read reports for the awaited head's one run. */
    private string $runStatus = 'in_progress';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ci-overdue-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/github/token', 'gh-read-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/github/token', 0o600);
        File::put($this->dir.'/seat-a.yml', "subscriptions:\n  - provider: github\n    scopes: [".self::REPO."]\nchannel:\n  url: http://127.0.0.1:".self::PORT."/\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.inbox_layout' => 'shared',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.jobs.enabled' => false,
            'bridge.ci_await.ttl' => 21600,
            'bridge.ci_await.read_cooldown' => 0,
        ]);
        Http::fake([
            'api.github.com/repos/octo/widgets/actions/runs*' => fn () => Http::response(['total_count' => 1, 'workflow_runs' => [[
                'id' => 1, 'name' => 'CI', 'workflow_id' => 77, 'run_number' => 1, 'head_sha' => self::SHA, 'status' => $this->runStatus,
                'conclusion' => $this->runStatus === 'completed' ? 'success' : null, 'event' => 'pull_request', 'run_attempt' => 1,
                'html_url' => 'https://github.com/octo/widgets/actions/runs/1',
            ]]]),
            '127.0.0.1:*' => Http::response('ok', 200),
        ]);
        Carbon::setTestNow('2026-10-10T10:00:00.000Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ---- the card's three controls ----------------------------------------------------------

    public function test_a_slow_head_gets_exactly_one_overdue_event_then_ci_settled(): void
    {
        $this->seedHistory([600, 900, 1200, 1200, 1500]);   // p95 1500 + a quarter (375) = 1875 s

        $result = $this->register()->body()['result'];
        $this->assertSame('waiting', $result['state']);
        $this->assertSame('2026-10-10T10:31:15.000Z', $result['overdue_at']);
        $this->assertSame('history', $result['overdue_basis']);

        $this->sweepAt('2026-10-10T10:31:14.000Z');
        $this->assertSame([], $this->kinds(), 'nothing before the deadline');

        $this->sweepAt('2026-10-10T10:31:15.000Z');
        $this->sweepAt('2026-10-10T10:40:00.000Z');
        $this->sweepAt('2026-10-10T11:00:00.000Z');
        $this->assertSame(['ci_await_overdue'], $this->kinds(), 'one overdue event per await, however many passes');
        $this->assertSame(1, CiAwait::query()->count(), 'the await stays registered after the overdue event');

        $this->runStatus = 'completed';
        $this->sweepAt('2026-10-10T11:10:00.000Z');

        $this->assertSame(['ci_await_overdue', 'ci_settled'], $this->kinds());
        $this->assertSame(['ci_await_overdue', 'ci_settled'], $this->pushedKinds());
        $this->assertSame(0, CiAwait::query()->count());

        $overdue = $this->inbox()[0];
        $this->assertStringStartsWith('ci_await_overdue:', $overdue['id']);
        $this->assertSame('ci:'.self::REPO.'@'.self::SHA, $overdue['subject_id']);
        $payload = $overdue['payload'];
        $this->assertSame('2026-10-10T10:31:15.000Z', $payload['overdue_at']);
        $this->assertSame('history', $payload['overdue_basis']);
        $this->assertSame('2026-10-10T10:00:00.000Z', $payload['registered_at']);
        $this->assertSame('2026-10-10T16:00:00.000Z', $payload['expires_at']);
        $this->assertSame('2026-10-10T10:31:14.000Z', $payload['last_read_at'], 'the last runs read, one pass earlier');
        $this->assertNull($payload['last_error']);
        $this->assertSame([], $payload['runs_seen'], 'no workflow_run delivery for this head reached the bridge');
        $this->assertStringContainsString('OVERDUE', $overdue['summary']);
        $this->assertStringContainsString('Check it once now with ci-read', $overdue['summary']);
    }

    public function test_a_fast_head_gets_no_overdue_event(): void
    {
        $this->seedHistory([600, 900, 1200, 1200, 1500]);
        $this->register();

        $this->sweepAt('2026-10-10T10:20:00.000Z');
        $this->assertSame([], $this->kinds(), 'still running, but not overdue yet');

        $this->runStatus = 'completed';
        $this->sweepAt('2026-10-10T10:26:00.000Z');
        $this->sweepAt('2026-10-10T10:40:00.000Z');
        $this->sweepAt('2026-10-10T12:00:00.000Z');

        $this->assertSame(['ci_settled'], $this->kinds());
    }

    public function test_the_callers_overdue_after_seconds_is_honoured(): void
    {
        $this->seedHistory([600, 900, 1200, 1200, 1500]);

        $result = $this->register(['overdue_after_seconds' => 120])->body()['result'];

        $this->assertSame('2026-10-10T10:02:00.000Z', $result['overdue_at']);
        $this->assertSame('override', $result['overdue_basis']);
        $this->sweepAt('2026-10-10T10:01:59.000Z');
        $this->assertSame([], $this->kinds());
        $this->sweepAt('2026-10-10T10:02:00.000Z');
        $this->assertSame(['ci_await_overdue'], $this->kinds());
        $this->assertSame('override', $this->inbox()[0]['payload']['overdue_basis']);
        $this->assertStringContainsString('the deadline you set', $this->inbox()[0]['summary']);
    }

    public function test_a_head_that_finished_before_the_pass_that_finds_it_overdue_is_settled_not_reported_overdue(): void
    {
        $this->register(['overdue_after_seconds' => 600]);

        $this->runStatus = 'completed';
        $this->sweepAt('2026-10-10T10:10:00.000Z');

        $this->assertSame(['ci_settled'], $this->kinds(), 'the pass reads stale heads before it looks for overdue awaits');
    }

    public function test_an_await_whose_head_the_aggregate_already_settled_for_the_seat_gets_no_overdue_event(): void
    {
        $this->register(['overdue_after_seconds' => 600]);
        $this->trackSettledRun(1);
        CiHeadSettlementLedger::claim('seat-a', self::REPO, self::SHA, $this->currentFingerprint());

        $this->sweepAt('2026-10-10T10:10:00.000Z');

        $this->assertSame([], $this->kinds(), 'the seat was already sent the head\'s current settled state; the await stays registered');
        $this->assertNull(CiAwait::query()->firstOrFail()->overdue_sent_at, 'nothing was claimed, so no stamp');
    }

    public function test_another_seats_settlement_of_the_head_does_not_silence_this_seats_overdue_event(): void
    {
        $this->register(['overdue_after_seconds' => 600]);
        $this->trackSettledRun(1);
        CiHeadSettlementLedger::claim('seat-b', self::REPO, self::SHA, $this->currentFingerprint());

        $this->sweepAt('2026-10-10T10:10:00.000Z');

        $this->assertSame(['ci_await_overdue'], $this->kinds());
    }

    public function test_a_state_the_head_has_since_left_does_not_silence_the_overdue_event(): void
    {
        $this->register(['overdue_after_seconds' => 600]);
        $this->trackSettledRun(1);
        CiHeadSettlementLedger::claim('seat-a', self::REPO, self::SHA, $this->currentFingerprint());
        $this->trackSettledRun(2, status: 'in_progress');   // a re-run or late run: the head is open again

        $this->sweepAt('2026-10-10T10:10:00.000Z');

        $this->assertSame(['ci_await_overdue'], $this->kinds(), 'the ledger holds an OLD state of this head');
    }

    public function test_a_head_settled_once_and_then_re_run_gets_an_overdue_event_for_the_re_run(): void
    {
        $this->runStatus = 'completed';
        $this->assertSame('settled', $this->register()->body()['result']['state']);
        $this->assertSame(['ci_settled'], $this->kinds());
        $this->assertSame(1, DB::table('ci_head_settlements')->count());

        $this->runStatus = 'in_progress';
        Carbon::setTestNow('2026-10-10T10:01:00.000Z');
        $this->assertSame('waiting', $this->register(['overdue_after_seconds' => 120])->body()['result']['state']);
        $this->sweepAt('2026-10-10T10:10:00.000Z');
        $this->sweepAt('2026-10-10T10:20:00.000Z');

        $this->assertSame(['ci_settled', 'ci_await_overdue'], $this->kinds());
    }

    public function test_a_refresh_refused_for_a_missing_column_changes_nothing(): void
    {
        $this->register();
        $before = CiAwait::query()->firstOrFail()->expires_at->toIso8601String();
        Schema::table('ci_awaits', function (Blueprint $table) {
            $table->dropIndex(['overdue_at']);
            $table->dropColumn(['overdue_at', 'overdue_basis', 'overdue_sent_at']);
        });

        Carbon::setTestNow('2026-10-10T12:00:00.000Z');
        $out = $this->register();

        $this->assertFalse($out->ok);
        $this->assertSame('install_fault.ci_await_store_unavailable', $out->body()['reason']);
        $this->assertSame($before, CiAwait::query()->firstOrFail()->expires_at->toIso8601String(), 'the refusal says nothing was stored, so the expiry did not move');
    }

    public function test_awaits_skipped_for_a_sent_state_do_not_use_up_the_send_cap(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->dueAwait($n, skipped: true);
        }
        $this->dueAwait(4, skipped: false);
        Carbon::setTestNow('2026-10-10T10:10:00.000Z');

        $result = $this->app->make(CiAwaitService::class)->emitOverdue(1, 300);

        $this->assertSame(1, $result['emitted'], 'three skips sat ahead of the due await under a cap of one');
        $this->assertSame(['ci_await_overdue'], $this->kinds());
    }

    public function test_more_skips_than_the_scan_bound_can_starve_a_later_await(): void
    {
        foreach (range(1, 10) as $n) {   // the scan looks at 10x the cap, here 10
            $this->dueAwait($n, skipped: true);
        }
        $this->dueAwait(11, skipped: false);
        Carbon::setTestNow('2026-10-10T10:10:00.000Z');

        $result = $this->app->make(CiAwaitService::class)->emitOverdue(1, 300);

        $this->assertSame(0, $result['emitted'], 'the bound is real: skips are never stamped, so this await waits behind them');
        $this->assertSame([], $this->kinds());
    }

    // ---- the deadline -----------------------------------------------------------------------

    public function test_a_repo_with_too_little_history_is_overdue_after_the_configured_default(): void
    {
        config(['bridge.ci_await.overdue_default' => 900]);
        $this->seedHistory([60, 60, 60, 60]);   // one short of OverdueDeadline::MIN_HEADS

        $result = $this->register()->body()['result'];

        $this->assertSame('2026-10-10T10:15:00.000Z', $result['overdue_at']);
        $this->assertSame('default', $result['overdue_basis']);
    }

    public function test_a_re_run_head_is_not_a_sample(): void
    {
        $this->seedHistory([600, 600, 600, 600, 600]);
        $this->seedHead('re-run', 99999, attempt: 2);

        $this->assertSame(600 + OverdueDeadline::MARGIN_MIN_SECONDS, OverdueDeadline::forRepo(self::REPO, 1800)->seconds);
    }

    public function test_a_head_with_a_run_still_open_is_not_a_sample(): void
    {
        $this->seedHistory([600, 600, 600, 600, 600]);
        $this->seedHead('open', 99999, status: 'in_progress');

        $this->assertSame(600 + OverdueDeadline::MARGIN_MIN_SECONDS, OverdueDeadline::forRepo(self::REPO, 1800)->seconds);
    }

    public function test_a_heads_time_runs_from_its_first_run_to_its_last(): void
    {
        foreach (range(1, 5) as $i) {
            // Two runs per head: one from 0 to 300 s, the other from 100 to 2000 s.
            $start = Carbon::parse('2026-10-09T00:00:00Z')->addHours($i);
            $this->insertRun("{$i}a", $start, $start->copy()->addSeconds(300));
            $this->insertRun("{$i}b", $start->copy()->addSeconds(100), $start->copy()->addSeconds(2000));
        }

        $this->assertSame(2000 + 500, OverdueDeadline::forRepo(self::REPO, 1800)->seconds);
    }

    public function test_the_percentile_is_nearest_rank(): void
    {
        $this->assertSame(19, OverdueDeadline::percentile(range(1, 20), 95));
        $this->assertSame(5, OverdueDeadline::percentile([5, 1, 3, 2, 4], 95));
        $this->assertSame(7, OverdueDeadline::percentile([7], 95));
    }

    // ---- refresh and once-only --------------------------------------------------------------

    public function test_a_refresh_keeps_the_derived_deadline(): void
    {
        $this->seedHistory([600, 900, 1200, 1200, 1500]);
        $this->register();

        Carbon::setTestNow('2026-10-10T10:20:00.000Z');
        $result = $this->register()->body()['result'];

        $this->assertTrue($result['refreshed']);
        $this->assertSame('2026-10-10T10:31:15.000Z', $result['overdue_at']);
    }

    public function test_a_refresh_with_overdue_after_seconds_moves_a_deadline_not_yet_reached(): void
    {
        $this->register();

        Carbon::setTestNow('2026-10-10T10:20:00.000Z');
        $result = $this->register(['overdue_after_seconds' => 3600])->body()['result'];

        $this->assertSame('2026-10-10T11:20:00.000Z', $result['overdue_at']);
        $this->assertSame('override', $result['overdue_basis']);
    }

    public function test_a_refresh_after_the_overdue_event_never_sends_a_second(): void
    {
        $this->register(['overdue_after_seconds' => 120]);
        $this->sweepAt('2026-10-10T10:05:00.000Z');
        $this->assertSame(['ci_await_overdue'], $this->kinds());

        Carbon::setTestNow('2026-10-10T10:06:00.000Z');
        $result = $this->register(['overdue_after_seconds' => 60])->body()['result'];
        $this->sweepAt('2026-10-10T10:20:00.000Z');

        $this->assertSame('2026-10-10T10:02:00.000Z', $result['overdue_at'], 'the deadline that fired stands');
        $this->assertSame('2026-10-10T10:05:00.000Z', $result['overdue_sent_at']);
        $this->assertSame(['ci_await_overdue'], $this->kinds());
    }

    public function test_two_concurrent_passes_send_one_overdue_event(): void
    {
        $this->register(['overdue_after_seconds' => 120]);
        Carbon::setTestNow('2026-10-10T10:05:00.000Z');
        $service = $this->app->make(CiAwaitService::class);
        $reentered = false;
        DB::listen(function (QueryExecuted $q) use ($service, &$reentered): void {
            if (! $reentered && str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, 'overdue_at')) {
                $reentered = true;
                $service->emitOverdue(50, 300);
            }
        });

        $outer = $service->emitOverdue(50, 300);

        $this->assertTrue($reentered, 'the second pass never ran, so this measured nothing');
        $this->assertSame(0, $outer['emitted']);
        $this->assertSame(['ci_await_overdue'], $this->kinds());
    }

    public function test_an_overdue_event_that_cannot_reach_the_inbox_is_sent_on_a_later_pass(): void
    {
        config(['bridge.inbox_layout' => 'per-agent']);
        $blocked = $this->dir.'/state/inbox-seat-a.jsonl';
        File::ensureDirectoryExists($blocked);
        $this->register(['overdue_after_seconds' => 120]);

        $this->sweepAt('2026-10-10T10:05:00.000Z');
        $await = CiAwait::query()->sole();
        $this->assertNull($await->overdue_sent_at, 'a send that did not reach the inbox is not recorded as sent');
        $this->assertNotNull($await->emit_failed_at);

        File::deleteDirectory($blocked);
        $this->sweepAt('2026-10-10T10:12:00.000Z');

        $lines = is_file($blocked) ? array_map(fn (string $l): array => (array) json_decode($l, true), file($blocked, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $this->assertSame(['ci_await_overdue'], array_column($lines, 'kind'));
        $this->assertNull(CiAwait::query()->sole()->emit_failed_at, 'a send that reached the inbox clears the mark');
    }

    // ---- the answer and the argument ---------------------------------------------------------

    public function test_a_settled_registration_carries_no_overdue_deadline(): void
    {
        $this->runStatus = 'completed';

        $result = $this->register()->body()['result'];

        $this->assertSame('settled', $result['state']);
        $this->assertNull($result['overdue_at']);
        $this->assertNull($result['overdue_basis']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badOverrides(): array
    {
        return ['below the floor' => [59], 'above the ceiling' => [604801], 'a string' => ['600'], 'a float' => [600.5]];
    }

    #[DataProvider('badOverrides')]
    public function test_a_bad_overdue_after_seconds_is_refused_and_nothing_is_stored(mixed $value): void
    {
        $out = $this->register(['overdue_after_seconds' => $value]);

        $this->assertFalse($out->ok);
        $this->assertSame('bad_arguments', $out->body()['reason']);
        $this->assertStringContainsString('overdue_after_seconds', (string) $out->body()['error']);
        $this->assertSame(0, CiAwait::query()->count());
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $extra */
    private function register(array $extra = []): DispatchOutcome
    {
        CallingSeatSeal::forANewServingProcess();
        $cfg = new BoardToolsConfig(
            enabled: true, tokenPath: null, boardId: 10, swimlaneId: 4, createStageId: 55,
            sharedSwimlaneId: null, coordBoardId: null, addressTags: [], transport: 'ssh',
        );

        return (new BoardToolDispatcher(new BoardToolsRegistry))->dispatch('ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA] + $extra, $cfg, 'seat-a', CallProvenance::NotSshd, null, CallerReport::unreported());
    }

    private function sweepAt(string $at): JobOutcome
    {
        Carbon::setTestNow($at);

        return $this->app->make(CiAwaitSweepJob::class)->run(new JobContext(CiAwaitSweepJob::INSTANCE, [], null, 300, JobPassSource::Tick));
    }

    /**
     * One finished head per span, each a single completed run, the newest a day ago.
     *
     * @param  list<int>  $spans  seconds
     */
    private function seedHistory(array $spans): void
    {
        foreach ($spans as $i => $span) {
            $this->seedHead("h{$i}", $span, at: Carbon::parse('2026-10-09T00:00:00Z')->addHours($i));
        }
    }

    private function seedHead(string $tag, int $span, int $attempt = 1, string $status = 'completed', ?Carbon $at = null): void
    {
        $start = $at ?? Carbon::parse('2026-10-09T12:00:00Z');
        $this->insertRun($tag, $start, $start->copy()->addSeconds($span), $attempt, $status);
    }

    /** One `ci_head_runs` row on its own head (the head is keyed by `$tag`'s leading digits or letters). */
    private function insertRun(string $tag, Carbon $created, Carbon $updated, int $attempt = 1, string $status = 'completed'): void
    {
        static $runId = 1000;
        $runId++;
        DB::table('ci_head_runs')->insert([
            'run_id' => $runId, 'repo' => self::REPO, 'repo_name' => self::REPO,
            'head_sha' => substr(sha1('head-'.rtrim($tag, 'ab')), 0, 40),
            'workflow_id' => 77, 'workflow' => 'CI', 'run_number' => $runId, 'run_attempt' => $attempt, 'event' => 'push',
            'status' => $status, 'conclusion' => $status === 'completed' ? 'success' : null,
            'html_url' => "https://github.com/octo/widgets/actions/runs/{$runId}", 'pr' => null,
            'created_at' => $created, 'updated_at' => $updated,
        ]);
    }

    /** An await past its deadline on its own head; `$skipped` gives that head's current state as already sent to the seat. */
    private function dueAwait(int $n, bool $skipped): void
    {
        $sha = str_pad((string) $n, 40, 'f', STR_PAD_LEFT);
        CiAwait::query()->create([
            'agent' => 'seat-a', 'repo' => CiAwaitService::key(self::REPO), 'repo_name' => self::REPO, 'head_sha' => $sha, 'pr' => null,
            'expires_at' => Carbon::parse('2026-10-10T16:00:00Z'), 'overdue_at' => Carbon::parse('2026-10-10T10:00:00Z')->addSeconds($n), 'overdue_basis' => 'override',
        ]);
        if ($skipped) {
            DB::table('ci_head_runs')->insert([
                'run_id' => 900 + $n, 'repo' => CiAwaitService::key(self::REPO), 'repo_name' => self::REPO, 'head_sha' => $sha,
                'workflow_id' => 77, 'workflow' => 'CI', 'run_number' => 1, 'run_attempt' => 1, 'event' => 'push',
                'status' => 'completed', 'conclusion' => 'success', 'html_url' => "https://github.com/octo/widgets/actions/runs/{$n}", 'pr' => null,
                'created_at' => Carbon::parse('2026-10-10T09:00:00Z'), 'updated_at' => Carbon::parse('2026-10-10T09:05:00Z'),
            ]);
            CiHeadSettlementLedger::claim('seat-a', self::REPO, $sha, HeadRuns::of(CiHeadRunTracker::runsOf(self::REPO, $sha))->fingerprint());
        }
    }

    /** One tracked run on the awaited head, as the `workflow_run` deliveries would leave it. */
    private function trackSettledRun(int $runId, string $status = 'completed'): void
    {
        DB::table('ci_head_runs')->insert([
            'run_id' => 500 + $runId, 'repo' => self::REPO, 'repo_name' => self::REPO, 'head_sha' => self::SHA,
            'workflow_id' => 70 + $runId, 'workflow' => "W{$runId}", 'run_number' => 1, 'run_attempt' => 1, 'event' => 'push',
            'status' => $status, 'conclusion' => $status === 'completed' ? 'success' : null,
            'html_url' => "https://github.com/octo/widgets/actions/runs/{$runId}", 'pr' => null,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function currentFingerprint(): string
    {
        return HeadRuns::of(CiHeadRunTracker::runsOf(self::REPO, self::SHA))->fingerprint();
    }

    /** @return list<array<string, mixed>> */
    private function inbox(): array
    {
        $path = $this->dir.'/state/inbox.jsonl';

        return is_file($path) ? array_values(array_filter(
            array_map(fn (string $l): array => (array) json_decode($l, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
            fn (array $line): bool => str_starts_with((string) ($line['kind'] ?? ''), 'ci_'),
        )) : [];
    }

    /** @return list<string> */
    private function kinds(): array
    {
        return array_column($this->inbox(), 'kind');
    }

    /** @return list<string> */
    private function pushedKinds(): array
    {
        return array_values(array_map(
            static fn (array $pair): string => json_decode($pair[0]->body(), true)['intent']['kind'],
            Http::recorded(fn (Request $r): bool => str_starts_with($r->url(), 'http://127.0.0.1:'.self::PORT))->all(),
        ));
    }
}
