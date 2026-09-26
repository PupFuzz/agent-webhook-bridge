<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\Checks\IdleNudgePostureCheck;
use App\Bridge\IdleNudge\IdleNudgePassRecord;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The `idle_nudge.posture` leg (card#9422 / DL-380). No golden capture turns the nudge on, so
 * every arm is pinned here — severity AND content, because the leg exists to make an absence
 * of nudges legible and a line that spoke without saying why would satisfy a presence check.
 */
class IdleNudgePostureCheckTest extends TestCase
{
    use MaterializesChecks;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/idle-nudge-check-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/fleet-token', 'mzr_canary.a~b/c-9');   // gitleaks:allow — test fixture
        chmod($this->dir.'/fleet-token', 0o600);
        // A push-routed Mezzanine-sourced agent: what makes the Mezzanine keys binding.
        $this->agentYaml('pm', "channel:\n  url: http://127.0.0.1:8788/\n  route_intents: true\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.idle_nudge.enabled' => true,
            'bridge.idle_nudge.base_url' => 'https://mezzanine.example',
            'bridge.idle_nudge.token_path' => $this->dir.'/fleet-token',
            'bridge.idle_nudge.install' => 'inst-a',
            'bridge.idle_nudge.timeout' => '5',
            'bridge.idle_nudge.default_after' => '1800',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function agentYaml(string $name, string $body): void
    {
        File::put($this->dir."/{$name}.yml", "identity:\n  kanban_user_id: 5\nsubscriptions: []\n".$body);
    }

    private function nudgeInstance(string $name = 'idle-nudge', ?Carbon $lastRunAt = null): void
    {
        $this->app->make(JobRegistry::class)->insert(new JobSpec(
            name: $name,
            handler: 'idle_nudge',
            intervalS: 300,
            owner: 'pm',
            docsRef: 'docs/periodic-jobs.md',
            justification: 'an idle seat makes no webhook traffic and its state lives in Mezzanine, which sends none',
        ));
        if ($lastRunAt !== null) {
            ScheduledJob::query()->where('name', $name)->update(['last_run_at' => $lastRunAt]);
        }
    }

    /** @param  array<string, mixed>  $record */
    private function record(array $record): void
    {
        File::put(IdleNudgePassRecord::path(), json_encode($record));
    }

    /** @return list<Finding> */
    private function findings(): array
    {
        return $this->findingsOf(new IdleNudgePostureCheck);
    }

    private function assertOne(Severity $severity, string $contains): void
    {
        $findings = $this->findings();
        $matching = array_values(array_filter($findings, fn (Finding $f): bool => str_contains($f->message, $contains)));
        $this->assertCount(1, $matching, implode("\n", array_map(fn (Finding $f) => $f->severity->name.': '.$f->message, $findings)));
        $this->assertSame($severity, $matching[0]->severity);
        foreach ($findings as $f) {
            $this->assertStringNotContainsString('canary', $f->message);
        }
    }

    public function test_off_is_silent(): void
    {
        config(['bridge.idle_nudge.enabled' => false]);

        $this->assertSame([], $this->findings());
    }

    public function test_a_missing_install_fails(): void
    {
        config(['bridge.idle_nudge.install' => null]);

        $this->assertOne(Severity::Fail, 'BRIDGE_IDLE_NUDGE_INSTALL is unset');
    }

    public function test_an_out_of_bound_default_horizon_fails_rather_than_clamping(): void
    {
        config(['bridge.idle_nudge.default_after' => '60']);

        $this->assertOne(Severity::Fail, 'BRIDGE_IDLE_NUDGE_DEFAULT_AFTER must be a whole number of seconds in 120…86400');
    }

    public function test_an_insecure_token_file_fails(): void
    {
        chmod($this->dir.'/fleet-token', 0o644);
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['pm' => 'not_idle'], 'failed_agents' => []]);

        $this->assertOne(Severity::Fail, 'is group/world-readable');
    }

    public function test_enabled_with_no_instance_warns(): void
    {
        $this->assertOne(Severity::Warn, 'no ENABLED `idle_nudge` job instance exists');
    }

    public function test_more_than_one_enabled_instance_fails(): void
    {
        $this->nudgeInstance('one');
        $this->nudgeInstance('two');

        $this->assertOne(Severity::Fail, '2 ENABLED `idle_nudge` instances (one, two)');
    }

    public function test_an_instance_that_never_ran_warns(): void
    {
        $this->nudgeInstance();

        $this->assertOne(Severity::Warn, "instance 'idle-nudge' has NOT RUN yet");
    }

    public function test_a_stale_last_run_is_judged_against_the_instances_own_interval_with_the_tick_grace(): void
    {
        // interval 300 + TickPosture::graceS(300) = 300 + 360 = 660 s.
        $this->record(['measured' => true, 'agents' => ['pm' => 'not_idle'], 'failed_agents' => []]);

        $this->nudgeInstance(lastRunAt: Carbon::now()->subSeconds(650));
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f) => str_contains($f->message, 'last ran')));

        ScheduledJob::query()->update(['last_run_at' => Carbon::now()->subSeconds(700)]);
        $this->assertOne(Severity::Warn, 'past its interval of 300s plus a grace of 360s');
    }

    public function test_an_unmeasured_last_pass_warns_with_its_reason(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => false, 'reason' => 'the fleet snapshot answered HTTP 401 (token_expired)']);

        $this->assertOne(Severity::Warn, 'the last pass was UNMEASURED — the fleet snapshot answered HTTP 401 (token_expired)');
    }

    public function test_no_record_is_unvalidated_not_ok(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());

        $this->assertOne(Severity::Unvalidated, 'no readable last-pass record');
    }

    public function test_every_push_routed_agent_unmeasured_warns_naming_the_reasons(): void
    {
        // Today's live reading: no seat publishes protocol_agent_name.
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['impl' => 'no_declaring_seat', 'pm' => 'no_declaring_seat', 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, 'every push-routed or seat-record agent was UNMEASURED on the last pass (no_declaring_seat 2, not_push_routed 1)');
    }

    public function test_every_agent_unreadable_on_push_time_names_the_migration_and_the_receiver_config(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['impl' => 'push_time_unreadable', 'pm' => 'push_time_unreadable', 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, 'every push-routed agent read push_time_unreadable on the last pass (not_push_routed 1, push_time_unreadable 2)');
        $message = $this->findings()[0]->message;
        $this->assertStringContainsString('(a) `php artisan migrate` was not run', $message);
        $this->assertStringContainsString("(b) the webhook receiver's resolved config does not have the nudge enabled", $message);
        $this->assertStringContainsString('reload PHP-FPM', $message);
        $this->assertStringNotContainsString('no_declaring_seat', $message);
    }

    public function test_a_mix_of_unmeasured_reasons_keeps_the_generic_warning(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['impl' => 'push_time_unreadable', 'pm' => 'no_declaring_seat'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, 'every push-routed or seat-record agent was UNMEASURED on the last pass');
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f) => str_contains($f->message, 'reload PHP-FPM')));
    }

    public function test_no_push_routed_agent_at_all_warns(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, 'no declared agent declares `idle_nudge.seat_record` or sets `channel.route_intents: true`');
    }

    public function test_a_push_that_failed_this_pass_warns_and_is_not_also_reported_ok(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['pm' => 'nudge'], 'failed_agents' => ['pm']]);

        $this->assertOne(Severity::Warn, 'the last pass FAILED to push to pm');
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f) => $f->severity === Severity::Ok));
    }

    public function test_a_measured_pass_is_ok_with_its_tally(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['impl' => 'no_declaring_seat', 'pm' => 'not_idle', 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertOne(Severity::Ok, 'last pass measured 1 of 2 push-routed or seat-record agent(s) (no_declaring_seat 1, not_idle 1, not_push_routed 1)');
    }

    private function seatRecordOnly(): void
    {
        File::delete($this->dir.'/pm.yml');
        $this->agentYaml('pm', "idle_nudge:\n  seat_record: /home/seat/.cache/coord/pm-lane-wake-offer.json\n");
        $this->agentYaml('quiet', "channel:\n  url: http://127.0.0.1:8790/\n");
        config(['bridge.idle_nudge.install' => null, 'bridge.idle_nudge.base_url' => null, 'bridge.idle_nudge.token_path' => null]);
    }

    public function test_the_mezzanine_keys_do_not_bind_on_an_install_where_no_agent_needs_mezzanine(): void
    {
        $this->seatRecordOnly();
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'agents' => ['pm' => 'idle_within_horizon', 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $findings = $this->findings();
        $this->assertSame([], array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
        $this->assertOne(Severity::Ok, 'last pass measured 1 of 1 push-routed or seat-record agent(s) (idle_within_horizon 1, not_push_routed 1)');
    }

    public function test_the_mezzanine_keys_still_bind_while_any_agent_needs_mezzanine(): void
    {
        $this->seatRecordOnly();
        $this->agentYaml('impl', "channel:\n  url: http://127.0.0.1:8789/\n  route_intents: true\n");

        $this->assertOne(Severity::Fail, 'idle_nudge: enabled but MISCONFIGURED — BRIDGE_IDLE_NUDGE_BASE_URL');
    }

    /** @return array<string, array{string, string}> */
    public static function seatRecordFaults(): array
    {
        return [
            'absent' => ['seat_record_absent', "pm's seat record /home/seat/.cache/coord/pm-lane-wake-offer.json was ABSENT on the last pass"],
            'not visible' => ['seat_record_not_visible', 'a directory above it is not traversable by the OS user the pass ran as'],
            'unreadable' => ['seat_record_unreadable', 'was present but not read on the last pass'],
            'malformed' => ['seat_record_malformed', 'is not a valid schema-v1 offer record'],
            'unknown version' => ['seat_record_unknown_version', 'carries a schema version this build does not read'],
            'stale' => ['offer_stale', 'has NOT CHANGED for a whole horizon since its notice was pushed'],
        ];
    }

    #[DataProvider('seatRecordFaults')]
    public function test_a_declared_seat_record_the_pass_could_not_act_on_is_named_per_agent(string $code, string $says): void
    {
        $this->seatRecordOnly();
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'agents' => ['pm' => $code, 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, $says);
        $this->assertOne(Severity::Warn, 'This seat is not nudged.');
    }

    public function test_a_fleet_read_that_did_not_measure_is_named_beside_the_verdicts(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => 'the fleet snapshot answered HTTP 401 (token_expired)', 'agents' => ['pm' => 'fleet_unmeasured'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, 'the last pass could not read the fleet snapshot — the fleet snapshot answered HTTP 401 (token_expired)');
    }

    public function test_agent_yamls_that_do_not_load_leave_the_source_question_unvalidated(): void
    {
        $this->agentYaml('broken', "idle_nudge:\n  seat_record: relative/offer.json\n");

        $this->assertOne(Severity::Unvalidated, 'the agent YAMLs could not be loaded');
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f): bool => $f->severity === Severity::Fail));
    }
}
