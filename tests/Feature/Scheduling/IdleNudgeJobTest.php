<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\IdleNudge\AgentVerdict;
use App\Bridge\IdleNudge\Evaluation;
use App\Bridge\IdleNudge\IdleNudgePassRecord;
use App\Bridge\IdleNudge\IdleNudgeState;
use App\Bridge\IdleNudge\IdleNudgeUnmeasured;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Support\DbClock;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Feature\IdleNudge\FleetSnapshotReaderTest;
use Tests\TestCase;

/**
 * The idle nudge end to end (card#9422 / DL-380): real agent YAMLs, a real inbox file, a real
 * state dir, the Mezzanine read and the channel push both faked — never a live seat.
 *
 * ⚑ ONE STUB SET PER TEST, registered in setUp and steered through properties: a second
 * `Http::fake()` appends and the FIRST match wins, so "overriding" a stub later would silently
 * keep the old answer. `Tests\TestCase` refuses any request no stub answers.
 */
class IdleNudgeJobTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALL = 'inst-a';

    private const SERVER_TIME = '2026-09-14T12:00:00.000Z';

    private const IDLE_SINCE = '2026-09-14T11:00:00.000Z';

    private string $dir;

    /** @var list<array<string, mixed>> */
    private array $seats = [];

    private int $channelStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/idle-nudge-job-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/fleet-token', FleetSnapshotReaderTest::CANARY);
        chmod($this->dir.'/fleet-token', 0o600);
        $this->agent('pm', true, 8788);
        $this->agent('impl', true, 8789);
        $this->agent('quiet', false, 8790);

        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.idle_nudge.enabled' => true,
            'bridge.idle_nudge.base_url' => 'https://mezzanine.example',
            'bridge.idle_nudge.token_path' => $this->dir.'/fleet-token',
            'bridge.idle_nudge.install' => self::INSTALL,
            'bridge.idle_nudge.timeout' => '5',
            'bridge.idle_nudge.default_after' => '1800',
            'bridge.jobs.enabled' => true,
            'bridge.jobs.min_pass_interval' => 60,
        ]);
        Cache::flush();

        $this->seats = [$this->seat('pm', 'seat-pm')];

        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://mezzanine.example/')) {
                return Http::response(FleetSnapshotReaderTest::envelope([
                    'server_time' => self::SERVER_TIME,
                    'installs' => [['install_id' => self::INSTALL, 'seats' => $this->seats]],
                ]), 200);
            }
            if (str_starts_with($request->url(), 'http://127.0.0.1:87')) {
                return Http::response('ok', $this->channelStatus);
            }

            return null;
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function agent(string $name, bool $routeIntents, int $port): void
    {
        File::put($this->dir."/{$name}.yml", "identity:\n  kanban_user_id: 5\nsubscriptions: []\nchannel:\n  url: http://127.0.0.1:{$port}/\n  route_intents: ".($routeIntents ? 'true' : 'false')."\n");
    }

    /** @return array<string, mixed> */
    private function seat(string $agent, mixed $seatId, array $over = []): array
    {
        return array_merge([
            'install_id' => self::INSTALL,
            'seat_id' => $seatId,
            'render_state' => 'idle',
            'protocol_agent_name' => $agent,
            'protocol_agent_name_check' => 'checked',
            'retired' => null,
            'derivation' => ['fold_lag_ms' => 0],
            'idle_since' => self::IDLE_SINCE,
            'idle_nudge_after_s' => 600,
        ], $over);
    }

    /** Stage a line the way IntentLog does, aged on the DB's clock. */
    private function stage(string $agent, string $id, float $ageS): void
    {
        $ts = (float) DbClock::now()->format('U.u') - $ageS;
        File::append($this->dir.'/state/inbox.jsonl', json_encode(['id' => $id, 'ts' => $ts, 'agent' => $agent, 'kind' => 'card_moved', 'subject_id' => '42', 'summary' => 'card 42 moved', 'payload' => []])."\n");
    }

    private function pass(): void
    {
        $handler = $this->app->make(JobHandlerRegistry::class)->resolve('idle_nudge');
        $this->assertNotNull($handler);
        $handler->run(new JobContext('idle-nudge', [], null, 300, JobPassSource::Tick));
    }

    /** @return list<Request> */
    private function pushes(): array
    {
        return array_values(array_map(
            fn (array $pair): Request => $pair[0],
            Http::recorded(fn (Request $r): bool => str_starts_with($r->url(), 'http://127.0.0.1:87'))->all(),
        ));
    }

    public function test_an_idle_seat_with_pushed_work_is_nudged_once_with_the_documented_payload(): void
    {
        $this->stage('pm', 'd1:pm:0', 600);

        $this->pass();
        $this->pass();

        $pushes = $this->pushes();
        $this->assertCount(1, $pushes);
        $this->assertSame('http://127.0.0.1:8788/', $pushes[0]->url());
        $intent = json_decode($pushes[0]->body(), true)['intent'];
        $this->assertSame('seat_idle_nudge', $intent['kind']);
        $this->assertSame('idle-nudge:pm:2026-09-14T11:00:00.000Z', $intent['subject_id']);
        $this->assertSame(['id' => null, 'name' => null, 'is_known_agent' => false], $intent['actor']);
        $this->assertSame('idle_past_declared_horizon', $intent['payload']['verdict']);
        $this->assertSame('declared', $intent['payload']['horizon_source']);
        $this->assertSame(3600, $intent['payload']['idle_age_s']);
        $this->assertSame(1, $intent['payload']['pending_total']);
        $this->assertSame('d1:pm:0', $intent['payload']['pending'][0]['id']);
        $this->assertSame(['nudged' => ['pm' => ['idle_since' => self::IDLE_SINCE]]], json_decode((string) File::get(IdleNudgeState::path()), true));
    }

    public function test_a_new_idle_period_re_arms_by_overwriting_the_slot(): void
    {
        $this->stage('pm', 'd1:pm:0', 600);
        $this->pass();

        $this->seats = [$this->seat('pm', 'seat-pm', ['idle_since' => '2026-09-14T11:10:00.000Z'])];
        $this->pass();

        $this->assertCount(2, $this->pushes());
    }

    public function test_a_seat_missing_for_one_pass_and_back_with_the_same_idle_since_is_nudged_once(): void
    {
        $this->stage('pm', 'd1:pm:0', 600);
        $this->pass();

        $this->seats = [];
        $this->pass();

        $this->seats = [$this->seat('pm', 'seat-pm')];
        $this->pass();

        $this->assertCount(1, $this->pushes());
    }

    public function test_a_corrupt_dedupe_file_pushes_nothing_and_is_never_written_over(): void
    {
        $this->stage('pm', 'd1:pm:0', 600);
        File::put(IdleNudgeState::path(), '{"nudged": [broken');

        foreach ([1, 2] as $pass) {
            try {
                $this->pass();
                $this->fail("pass {$pass} must be unmeasured");
            } catch (IdleNudgeUnmeasured $e) {
                $this->assertStringContainsString('dedupe state file', $e->reason);
            }
        }

        $this->assertSame([], $this->pushes());
        $this->assertSame('{"nudged": [broken', File::get(IdleNudgeState::path()));
        $this->assertFalse(IdleNudgePassRecord::read()['measured']);
    }

    public function test_an_agent_whose_intents_are_not_push_routed_is_never_nudged(): void
    {
        $this->seats = [$this->seat('quiet', 'seat-quiet')];
        $this->stage('quiet', 'd1:quiet:0', 600);

        $this->pass();

        $this->assertSame([], $this->pushes());
        $this->assertSame('not_push_routed', IdleNudgePassRecord::read()['agents']['quiet']);
    }

    public function test_the_dedupe_key_is_the_local_agent_never_a_snapshot_identity_field(): void
    {
        // Two agents on one install whose idle periods carry the same `idle_since` but are seen
        // on different passes, and a third whose seat id is null. Everything the snapshot says
        // about the two seats' identity is shared except the seat id, so any key built from
        // snapshot fields other than the local agent collides; each resolvable agent must still
        // be nudged exactly once across three passes, and the null-id seat never.
        $this->agent('ops', true, 8791);
        foreach (['pm', 'impl', 'ops'] as $agent) {
            $this->stage($agent, "d1:{$agent}:0", 600);
        }

        $this->seats = [
            $this->seat('pm', 'seat-pm'),
            $this->seat('impl', 'seat-impl', ['render_state' => 'working']),
            $this->seat('ops', null),
        ];
        $this->pass();

        $this->seats[1] = $this->seat('impl', 'seat-impl');
        $this->pass();
        $this->pass();

        $urls = array_map(fn (Request $r): string => $r->url(), $this->pushes());
        sort($urls);
        $this->assertSame(['http://127.0.0.1:8788/', 'http://127.0.0.1:8789/'], $urls);
        $this->assertSame('malformed_seat_identity', IdleNudgePassRecord::read()['agents']['ops']);
    }

    public function test_an_unset_install_is_a_misconfiguration_and_reads_nothing(): void
    {
        config(['bridge.idle_nudge.install' => '']);
        $this->stage('pm', 'd1:pm:0', 600);

        try {
            $this->pass();
            $this->fail('an enabled nudge with no install must throw');
        } catch (IdleNudgeUnmeasured $e) {
            $this->assertStringContainsString('BRIDGE_IDLE_NUDGE_INSTALL is unset', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_a_line_the_agent_has_already_seen_is_not_pending(): void
    {
        $this->stage('pm', 'd1:pm:0', 600);
        File::put($this->dir.'/state/inbox-seen-pm.json', json_encode(['d1:pm:0']));

        $this->pass();

        $this->assertSame([], $this->pushes());
        $this->assertSame('nothing_pending', IdleNudgePassRecord::read()['agents']['pm']);
    }

    public function test_a_failed_push_is_recorded_for_this_pass_and_not_retried_in_the_period(): void
    {
        $this->stage('pm', 'd1:pm:0', 600);
        $this->channelStatus = 500;

        $this->pass();
        $record = IdleNudgePassRecord::read();
        $this->assertSame(['pm'], $record['failed_agents']);
        $this->assertSame(['accepted_by_transport' => 0, 'failed' => 1], $record['pushes']);

        $this->channelStatus = 200;
        $this->pass();

        $this->assertCount(1, $this->pushes());
        $this->assertSame([], IdleNudgePassRecord::read()['failed_agents']);
    }

    public function test_a_pass_that_dies_on_an_unnamed_fault_replaces_the_previous_verdict(): void
    {
        $this->stage('pm', 'd1:pm:0', 600);
        IdleNudgePassRecord::measured(new Evaluation(['declarer' => 1], [new AgentVerdict('pm', 'not_idle')]), 0, []);
        // A directory where the dedupe file goes: it reads as "no slots", and the write before
        // the push then fails — a RuntimeException, not a named unmeasured reason.
        File::ensureDirectoryExists(IdleNudgeState::path().'/occupied');

        try {
            $this->pass();
            $this->fail('the failed slot write must throw');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(IdleNudgeUnmeasured::class, $e);
        }

        $record = IdleNudgePassRecord::read();
        $this->assertFalse($record['measured']);
        $this->assertStringStartsWith('the pass threw RuntimeException', $record['reason']);
        $this->assertSame([], $this->pushes());
    }

    public function test_slots_for_agents_no_longer_declared_are_dropped(): void
    {
        File::put(IdleNudgeState::path(), json_encode(['nudged' => ['gone' => ['idle_since' => self::IDLE_SINCE]]]));

        $this->pass();

        $this->assertSame(['nudged' => []], json_decode((string) File::get(IdleNudgeState::path()), true));
    }

    public function test_the_disabled_handler_reads_nothing(): void
    {
        config(['bridge.idle_nudge.enabled' => false]);

        $this->pass();

        Http::assertNothingSent();
        $this->assertNull(IdleNudgePassRecord::read());
    }

    public function test_it_runs_from_the_registry_and_the_token_reaches_no_durable_or_printed_surface(): void
    {
        $this->app->make(JobRegistry::class)->insert(new JobSpec(
            name: 'idle-nudge',
            handler: 'idle_nudge',
            intervalS: 300,
            owner: 'pm',
            docsRef: 'docs/periodic-jobs.md#shipped-handlers',
            justification: 'an idle seat makes no webhook traffic and its state lives in Mezzanine, which sends none',
        ));
        $log = $this->dir.'/laravel.log';
        config(['logging.default' => 'single', 'logging.channels.single.path' => $log]);
        app('log')->forgetChannel('single');
        $this->stage('pm', 'd1:pm:0', 600);

        $this->app->make(JobScheduler::class)->pass(JobPassSource::Manual);

        $row = ScheduledJob::query()->where('name', 'idle-nudge')->firstOrFail();
        $this->assertSame(ScheduledJob::STATUS_OK, $row->last_status);
        $this->assertCount(1, $this->pushes());
        $this->assertStringStartsWith('nudged 1 (unconfirmed; failed 0)', (string) $row->last_summary);

        Artisan::call('bridge:jobs');
        $surfaces = [
            'last_summary' => (string) $row->last_summary,
            'bridge:jobs' => Artisan::output(),
            'log' => File::exists($log) ? File::get($log) : '',
            'dedupe state' => File::get(IdleNudgeState::path()),
            'pass record' => File::get(IdleNudgePassRecord::path()),
            'pushed body' => $this->pushes()[0]->body(),
        ];
        foreach ($surfaces as $name => $text) {
            $this->assertStringNotContainsString('canary', $text, $name);
        }
        $this->assertStringContainsString('idle nudge pass', $surfaces['log'], 'the log leg must actually have been written');
        Http::assertSent(fn (Request $r): bool => $r->header('Authorization') === ['Bearer '.FleetSnapshotReaderTest::CANARY]);
    }

    public function test_an_echoed_token_in_a_refusal_reaches_no_row_or_command_output(): void
    {
        $this->app->make(JobRegistry::class)->insert(new JobSpec(
            name: 'idle-nudge',
            handler: 'idle_nudge',
            intervalS: 300,
            owner: 'pm',
            docsRef: 'docs/periodic-jobs.md#shipped-handlers',
            justification: 'an idle seat makes no webhook traffic and its state lives in Mezzanine, which sends none',
        ));
        config(['bridge.idle_nudge.base_url' => 'https://mezzanine-refusing.example']);
        Http::fake(['mezzanine-refusing.example/*' => Http::response(['error' => 'unauthenticated', 'message' => 'bad token '.FleetSnapshotReaderTest::CANARY], 401)]);

        $this->app->make(JobScheduler::class)->pass(JobPassSource::Manual);

        $row = ScheduledJob::query()->where('name', 'idle-nudge')->firstOrFail();
        $this->assertSame(ScheduledJob::STATUS_FAILED, $row->last_status);
        $this->assertStringContainsString('HTTP 401 (unauthenticated)', (string) $row->last_error);
        Artisan::call('bridge:jobs');
        foreach ([(string) $row->last_error, (string) $row->last_summary, Artisan::output(), File::get(IdleNudgePassRecord::path())] as $text) {
            $this->assertStringNotContainsString('canary', $text);
        }
    }
}
