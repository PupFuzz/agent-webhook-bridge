<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\IdleNudge\IdleNudgePassRecord;
use App\Bridge\IdleNudge\IdleNudgeState;
use App\Bridge\IdleNudge\IdleNudgeUnmeasured;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobSpec;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\IdleNudge\FleetSnapshotReaderTest;
use Tests\TestCase;
use Tests\Unit\IdleNudge\SeatRecordReaderTest;

/**
 * The seat-record source end to end (rt#562): real agent YAMLs, a real offer record, a real state
 * dir, the channel push faked — never a live seat. The bridge's clock is pinned with
 * `Carbon::setTestNow()`, which is the clock the pass reads.
 *
 * ⚑ Mezzanine is NOT configured in setUp: the install id, base URL and token path are all unset,
 * which is the install rt#562's operator plan produces. Any Mezzanine request reaches no stub and
 * `Tests\TestCase` refuses it.
 */
class IdleNudgeSeatRecordJobTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-09-14T12:00:00.125Z, the turn end every fixture record carries. */
    private const TURN_S = 1789387200.125;

    private string $dir;

    private int $channelStatus = 200;

    private bool $fleetThrows = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/idle-nudge-seat-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::ensureDirectoryExists($this->dir.'/seat');
        $this->agent('pm', 8788, "idle_nudge:\n  seat_record: {$this->dir}/seat/pm-lane-wake-offer.json\n");
        $this->agent('quiet', 8790);

        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.idle_nudge.enabled' => true,
            'bridge.idle_nudge.base_url' => null,
            'bridge.idle_nudge.token_path' => null,
            'bridge.idle_nudge.install' => null,
            'bridge.jobs.enabled' => true,
            'bridge.jobs.min_pass_interval' => 60,
        ]);
        Cache::flush();
        $this->at(3600);

        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'http://127.0.0.1:87')) {
                return Http::response('ok', $this->channelStatus);
            }
            if (str_starts_with($request->url(), 'https://mezzanine.example/')) {
                if ($this->fleetThrows) {
                    throw new \LogicException('an unnamed fault on the Mezzanine-sourced half');
                }

                return Http::response(['error' => 'unauthenticated', 'message' => 'bad token '.FleetSnapshotReaderTest::CANARY], 401);
            }

            return null;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function agent(string $name, int $port, string $extra = '', bool $routeIntents = false): void
    {
        File::put($this->dir."/{$name}.yml", "identity:\n  kanban_user_id: 5\nsubscriptions: []\nchannel:\n  url: http://127.0.0.1:{$port}/\n  route_intents: ".($routeIntents ? 'true' : 'false')."\n".$extra);
    }

    /** Pin the bridge's clock $afterS seconds after the fixture turn end. */
    private function at(float $afterS): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::TURN_S + $afterS));
    }

    /** @param  array<string, mixed>  $over */
    private function offer(array $over = []): void
    {
        File::put($this->dir.'/seat/pm-lane-wake-offer.json', (string) json_encode(SeatRecordReaderTest::record(array_merge(['turn_ended_at' => self::TURN_S], $over))));
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

    private function verdictOf(string $agent): string
    {
        return (string) IdleNudgePassRecord::read()['agents'][$agent];
    }

    public function test_a_quiet_seat_with_an_offer_gets_one_live_event_carrying_its_prompt_and_nothing_reads_mezzanine(): void
    {
        $this->offer();

        $this->pass();
        $this->pass();

        $pushes = $this->pushes();
        $this->assertCount(1, $pushes);
        $this->assertSame('http://127.0.0.1:8788/', $pushes[0]->url());
        $intent = json_decode($pushes[0]->body(), true)['intent'];
        $this->assertSame('seat_idle_nudge', $intent['kind']);
        $this->assertSame('idle-nudge:pm:2026-09-14T12:00:00.125Z', $intent['subject_id']);
        $this->assertSame('bridge', $intent['provider']);
        $this->assertSame('Lanes idle with pullable work: impl (card#42), review. Continue working.', $intent['summary']);
        $this->assertSame([
            'agent' => 'pm',
            'source' => 'seat_record',
            'verdict' => 'idle_past_declared_horizon',
            'session_id' => 'sess-1',
            'idle_since' => '2026-09-14T12:00:00.125Z',
            'idle_age_s' => 3600,
            'horizon_s' => 1800,
            'horizon_source' => 'declared',
            'cooldown_s' => 3600,
            'pending_total' => 2,
            'pending_shown' => 2,
            'pending' => [['lane' => 'impl', 'detail' => 'card#42'], ['lane' => 'review', 'detail' => 'pullable work']],
        ], $intent['payload']);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'mezzanine'));
        $record = IdleNudgePassRecord::read();
        $this->assertSame(['pm' => 'already_nudged', 'quiet' => 'not_push_routed'], $record['agents']);
        $this->assertNull($record['seats']);
        $this->assertNull($record['fleet_unmeasured']);
        $this->assertSame(['nudged' => ['pm' => [
            'idle_since' => '2026-09-14T12:00:00.125Z',
            'session_id' => 'sess-1',
            'nudged_at' => '2026-09-14T13:00:00.125Z',
        ]]], json_decode((string) File::get(IdleNudgeState::path()), true));
    }

    public function test_an_offer_inside_its_horizon_is_not_yet_delivered(): void
    {
        $this->offer();
        $this->at(1799);

        $this->pass();

        $this->assertSame([], $this->pushes());
        $this->assertSame('idle_within_horizon', $this->verdictOf('pm'));
    }

    public function test_a_later_turn_end_re_arms_only_once_the_cooldown_has_passed(): void
    {
        $this->offer();
        $this->pass();

        // The notice produced a turn: a new turn end with work still on offer, 30 min later.
        $this->offer(['turn_ended_at' => self::TURN_S + 1800]);
        $this->at(1800 + 1800);
        $this->pass();
        $this->assertCount(1, $this->pushes());
        $this->assertSame('cooldown', $this->verdictOf('pm'));

        // Exactly cooldown_s after the first notice.
        $this->at(3600 + 3600);
        $this->pass();
        $this->assertCount(2, $this->pushes());
        $this->assertSame('idle-nudge:pm:2026-09-14T12:30:00.125Z', json_decode($this->pushes()[1]->body(), true)['intent']['subject_id']);
    }

    public function test_an_offer_unchanged_a_whole_horizon_after_its_notice_reads_stale(): void
    {
        $this->offer();
        $this->pass();

        $this->at(3600 + 1800);
        $this->pass();

        $this->assertCount(1, $this->pushes());
        $this->assertSame('offer_stale', $this->verdictOf('pm'));
    }

    /** @return array<string, array{?array<string, mixed>, string}> */
    public static function nothingToDeliver(): array
    {
        return [
            'nothing on offer' => [['lanes' => [], 'prompt' => null, 'reason' => 'every idle lane is waived'], 'nothing_pending'],
            'census unmeasured' => [['lanes' => null, 'waivers' => null, 'prompt' => null, 'reason' => 'census timed out'], 'offer_unmeasured'],
            'unknown version' => [['v' => 2], 'seat_record_unknown_version'],
            'no record at all' => [null, 'seat_record_absent'],
        ];
    }

    /** @param  array<string, mixed>|null  $over */
    #[DataProvider('nothingToDeliver')]
    public function test_nothing_is_delivered_unless_the_record_offers_work(?array $over, string $verdict): void
    {
        if ($over !== null) {
            $this->offer($over);
        }

        $this->pass();

        $this->assertSame([], $this->pushes());
        $this->assertSame($verdict, $this->verdictOf('pm'));
        $this->assertFalse(File::exists(IdleNudgeState::path()) && str_contains((string) File::get(IdleNudgeState::path()), '"pm"'));
    }

    public function test_an_old_state_file_loads_and_its_slot_owes_no_cooldown(): void
    {
        File::put(IdleNudgeState::path(), json_encode(['nudged' => ['pm' => ['idle_since' => '2026-09-14T11:00:00.000Z']]]));
        $this->offer();

        $this->pass();

        $this->assertCount(1, $this->pushes());
    }

    public function test_a_failed_push_is_recorded_and_the_offer_is_not_repeated(): void
    {
        $this->offer();
        $this->channelStatus = 500;
        $this->pass();
        $this->assertSame(['pm'], IdleNudgePassRecord::read()['failed_agents']);

        $this->channelStatus = 200;
        $this->pass();

        $this->assertCount(1, $this->pushes());
        $this->assertSame('already_nudged', $this->verdictOf('pm'));
    }

    public function test_a_seat_record_agent_is_judged_even_when_mezzanine_is_needed_and_refuses(): void
    {
        $this->needsMezzanine();
        $this->app->make(JobRegistry::class)->insert(new JobSpec(
            name: 'idle-nudge',
            handler: 'idle_nudge',
            intervalS: 300,
            owner: 'pm',
            docsRef: 'docs/periodic-jobs.md#shipped-handlers',
            justification: 'an idle seat makes no webhook traffic, and its own offer record is a file on its own box',
        ));
        $this->offer();

        $this->app->make(JobScheduler::class)->pass(JobPassSource::Manual);

        $this->assertCount(1, $this->pushes());
        $record = IdleNudgePassRecord::read();
        $this->assertTrue($record['measured']);
        $this->assertSame('the fleet snapshot answered HTTP 401 (unauthenticated)', $record['fleet_unmeasured']);
        $this->assertSame(['impl' => 'fleet_unmeasured', 'pm' => 'nudge', 'quiet' => 'not_push_routed'], $record['agents']);
        $row = ScheduledJob::query()->where('name', 'idle-nudge')->firstOrFail();
        $this->assertSame(ScheduledJob::STATUS_FAILED, $row->last_status, 'the fleet failure keeps the row\'s failure streak');
        $this->assertStringContainsString('HTTP 401 (unauthenticated)', (string) $row->last_error);
        foreach ([(string) $row->last_error, File::get(IdleNudgePassRecord::path())] as $text) {
            $this->assertStringNotContainsString('canary', $text);
        }
    }

    public function test_an_unset_mezzanine_config_is_not_a_fault_on_an_install_that_needs_none(): void
    {
        $this->offer(['lanes' => [], 'prompt' => null]);

        $this->pass();

        $this->assertTrue(IdleNudgePassRecord::read()['measured']);
        Http::assertNothingSent();
    }

    public function test_a_misconfigured_mezzanine_install_that_needs_it_still_judges_the_seat_record_agent_and_throws_after(): void
    {
        $this->agent('impl', 8789, routeIntents: true);
        $this->offer();

        try {
            $this->pass();
            $this->fail('a needed but misconfigured fleet read must still fail the pass');
        } catch (IdleNudgeUnmeasured $e) {
            $this->assertStringContainsString('misconfigured — ', $e->reason);
            $this->assertTrue($e->passRecorded);
        }

        $this->assertCount(1, $this->pushes());
        $record = IdleNudgePassRecord::read();
        $this->assertSame('fleet_unmeasured', $record['agents']['impl']);
        $this->assertSame('nudge', $record['agents']['pm']);
        $this->assertStringStartsWith('misconfigured — ', (string) $record['fleet_unmeasured']);
    }

    private function needsMezzanine(): void
    {
        $this->agent('impl', 8789, routeIntents: true);
        File::put($this->dir.'/fleet-token', FleetSnapshotReaderTest::CANARY);
        chmod($this->dir.'/fleet-token', 0o600);
        config([
            'bridge.idle_nudge.base_url' => 'https://mezzanine.example',
            'bridge.idle_nudge.token_path' => $this->dir.'/fleet-token',
            'bridge.idle_nudge.install' => 'inst-a',
            'bridge.idle_nudge.timeout' => '5',
            'bridge.idle_nudge.default_after' => '1800',
        ]);
    }

    public function test_an_unnamed_throw_on_the_mezzanine_half_still_judges_pushes_and_records_the_seat_record_agent(): void
    {
        $this->needsMezzanine();
        $this->fleetThrows = true;
        $this->offer();

        try {
            $this->pass();
            $this->fail('a Mezzanine half that threw must still fail the pass');
        } catch (IdleNudgeUnmeasured $e) {
            $this->assertTrue($e->passRecorded);
        }

        $this->assertCount(1, $this->pushes());
        $record = IdleNudgePassRecord::read();
        $this->assertTrue($record['measured']);
        $this->assertSame(['impl' => 'fleet_unmeasured', 'pm' => 'nudge', 'quiet' => 'not_push_routed'], $record['agents']);
        $this->assertSame('the Mezzanine-sourced half of the pass threw LogicException before it finished — see the log', $record['fleet_unmeasured']);
    }

    public function test_the_path_the_pass_read_is_recorded(): void
    {
        $this->offer();

        $this->pass();

        $this->assertSame(['pm' => $this->dir.'/seat/pm-lane-wake-offer.json'], IdleNudgePassRecord::read()['seat_records']);
    }

    public function test_a_tilde_record_resolves_against_the_home_of_the_process_running_the_pass(): void
    {
        $this->agent('pm', 8788, "idle_nudge:\n  seat_record: ~/pm-lane-wake-offer.json\n");
        $this->offer();
        $home = getenv('HOME');
        putenv('HOME='.$this->dir.'/seat');
        try {
            $this->pass();
        } finally {
            putenv($home === false ? 'HOME' : 'HOME='.$home);
        }

        $this->assertCount(1, $this->pushes());
        $this->assertSame(['pm' => $this->dir.'/seat/pm-lane-wake-offer.json'], IdleNudgePassRecord::read()['seat_records']);
    }

    /**
     * The receiver loads these same YAMLs under PHP-FPM, whose `clear_env` default leaves it no
     * HOME: the load must not throw, and a pass with no HOME reads the record as unresolved.
     */
    public function test_a_tilde_record_in_a_process_with_no_home_is_unmeasured_by_name_and_every_yaml_still_loads(): void
    {
        $this->agent('pm', 8788, "idle_nudge:\n  seat_record: ~/pm-lane-wake-offer.json\n");
        $this->offer();
        $home = getenv('HOME');
        putenv('HOME');
        try {
            $this->pass();
        } finally {
            putenv($home === false ? 'HOME' : 'HOME='.$home);
        }

        $this->assertSame([], $this->pushes());
        $record = IdleNudgePassRecord::read();
        $this->assertTrue($record['measured']);
        $this->assertSame(['pm' => 'seat_record_home_unresolved', 'quiet' => 'not_push_routed'], $record['agents']);
        $this->assertSame(['pm' => null], $record['seat_records']);
    }

    public function test_a_record_written_for_another_agent_is_never_delivered(): void
    {
        $this->offer(['agent' => 'impl']);

        $this->pass();

        $this->assertSame([], $this->pushes());
        $this->assertSame('seat_record_agent_mismatch', $this->verdictOf('pm'));
    }

    /**
     * The install shape `idle_nudge.seat_agent` exists for: the bridge agent `kanban-solo` serves
     * the seat whose `$COORD_AGENT` — and so whose record's `agent` — is `kanban`.
     */
    private function kanbanSolo(string $recordAgent): void
    {
        $path = $this->dir.'/seat/kanban-lane-wake-offer.json';
        $this->agent('kanban-solo', 8791, "idle_nudge:\n  seat_record: {$path}\n  seat_agent: kanban\n");
        File::put($path, (string) json_encode(SeatRecordReaderTest::record(['agent' => $recordAgent, 'turn_ended_at' => self::TURN_S])));
    }

    public function test_a_seat_agent_key_makes_the_seats_own_name_the_one_the_record_must_carry(): void
    {
        $this->kanbanSolo('kanban');

        $this->pass();

        $pushes = $this->pushes();
        $this->assertCount(1, $pushes);
        $this->assertSame('http://127.0.0.1:8791/', $pushes[0]->url());
        $this->assertSame('kanban-solo', json_decode($pushes[0]->body(), true)['intent']['payload']['agent']);
        $this->assertSame('nudge', $this->verdictOf('kanban-solo'));
    }

    public function test_a_seat_agent_key_replaces_the_yaml_agent_name_rather_than_adding_to_it(): void
    {
        $this->kanbanSolo('kanban-solo');

        $this->pass();

        $this->assertSame([], $this->pushes());
        $this->assertSame('seat_record_agent_mismatch', $this->verdictOf('kanban-solo'));
    }

    /**
     * DL-424 Decision 9: one seat's record wakes at most one channel. A second YAML claiming the
     * same seat — a copied `idle_nudge:` block — refuses BOTH, by name, rather than waking two.
     */
    public function test_two_yamls_claiming_one_seat_through_seat_agent_wake_neither(): void
    {
        $this->kanbanSolo('kanban');
        $this->agent('prod-agent', 8792, "idle_nudge:\n  seat_record: {$this->dir}/seat/kanban-lane-wake-offer.json\n  seat_agent: kanban\n");

        $this->pass();

        $this->assertSame([], $this->pushes());
        $record = IdleNudgePassRecord::read();
        $this->assertSame('seat_record_seat_claimed_twice', $record['agents']['kanban-solo']);
        $this->assertSame('seat_record_seat_claimed_twice', $record['agents']['prod-agent']);
        $this->assertSame(['kanban-solo' => 'kanban', 'pm' => 'pm', 'prod-agent' => 'kanban'], $record['record_agents']);
    }

    public function test_a_yaml_named_for_the_seat_and_one_adopting_it_through_seat_agent_wake_neither(): void
    {
        $this->kanbanSolo('kanban');
        $this->agent('kanban', 8793, "idle_nudge:\n  seat_record: {$this->dir}/seat/kanban-lane-wake-offer.json\n");

        $this->pass();

        $this->assertSame([], $this->pushes());
        $this->assertSame('seat_record_seat_claimed_twice', $this->verdictOf('kanban'));
        $this->assertSame('seat_record_seat_claimed_twice', $this->verdictOf('kanban-solo'));
    }

    public function test_the_comparand_each_seat_record_agent_was_judged_against_is_recorded(): void
    {
        $this->kanbanSolo('kanban-solo');
        $this->offer();

        $this->pass();

        $this->assertSame(['kanban-solo' => 'kanban', 'pm' => 'pm'], IdleNudgePassRecord::read()['record_agents']);
    }
}
