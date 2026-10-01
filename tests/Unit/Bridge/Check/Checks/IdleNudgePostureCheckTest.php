<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\Checks\IdleNudgePostureCheck;
use App\Bridge\IdleNudge\AgentVerdict;
use App\Bridge\IdleNudge\IdleNudgeConfig;
use App\Bridge\IdleNudge\IdleNudgePassRecord;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Since card#10918 / DL-441 the nudge is ON without anyone asking, so a Mezzanine key nobody
     * set is the NOT-SET-UP state of every install with a push-routed agent, not a broken config:
     * `warn`, naming every unset key at once and the switch that declines the nudge.
     */
    public function test_an_unset_mezzanine_key_warns_naming_it_and_the_switch_to_decline(): void
    {
        config(['bridge.idle_nudge.install' => null, 'bridge.idle_nudge.enabled_explicit' => null]);

        $this->assertOne(Severity::Warn, 'BRIDGE_IDLE_NUDGE_INSTALL is unset');
        $notSetUp = array_values(array_filter($this->findings(), fn (Finding $f): bool => str_contains($f->message, 'NOT SET UP')));
        $this->assertCount(1, $notSetUp);
        $this->assertStringContainsString('BRIDGE_IDLE_NUDGE_ENABLED=false', $notSetUp[0]->message);
    }

    /**
     * card#10918 / DL-441 review round 1: an EXPLICIT `BRIDGE_IDLE_NUDGE_ENABLED=true` with a
     * required Mezzanine key still unset is an operator who acted and left the job unusable — the
     * pre-DL-441 `fail`, not the default install's NOT-SET-UP `warn` above. The tri-state
     * `enabled_explicit` config key is the only thing that differs between this test and the one
     * above; both leave the same Mezzanine key unset.
     */
    public function test_an_explicit_enable_with_an_unset_mezzanine_key_fails_not_warns(): void
    {
        config(['bridge.idle_nudge.install' => null, 'bridge.idle_nudge.enabled_explicit' => true]);

        $this->assertOne(Severity::Fail, 'BRIDGE_IDLE_NUDGE_INSTALL is unset');
    }

    /**
     * `env()` casts only `true`/`(true)` (any case) to a bool; every other truthy spelling an
     * operator writes reaches `enabled_explicit` as the raw STRING (`Illuminate\Support\Env`).
     * Those are explicit enables too, so "was the key touched" is `!== null`, never `=== true`
     * (review round 2 of card#10918: `=1` fell into the NOT-SET-UP `warn`).
     *
     * @return array<string, array{string}>
     */
    public static function explicitTruthyStrings(): array
    {
        return ['1' => ['1'], 'yes' => ['yes'], 'on' => ['on']];
    }

    #[DataProvider('explicitTruthyStrings')]
    public function test_an_explicit_enable_spelled_as_a_string_with_an_unset_mezzanine_key_fails_not_warns(string $raw): void
    {
        config(['bridge.idle_nudge.install' => null, 'bridge.idle_nudge.enabled_explicit' => $raw]);

        $this->assertOne(Severity::Fail, 'BRIDGE_IDLE_NUDGE_INSTALL is unset');
    }

    /**
     * The NOT-SET-UP `warn` gates no other leg: the job still nudges seat-record agents and still
     * records its pass with the Mezzanine keys unset, so a second instance is the same `fail` it
     * is on a set-up install (review round 2 of card#10918).
     */
    public function test_the_not_set_up_warn_does_not_swallow_a_second_instance_fail(): void
    {
        config(['bridge.idle_nudge.install' => null, 'bridge.idle_nudge.enabled_explicit' => null]);
        $this->nudgeInstance('idle-nudge-a');
        $this->nudgeInstance('idle-nudge-b');

        $this->assertOne(Severity::Warn, 'BRIDGE_IDLE_NUDGE_INSTALL is unset');
        $this->assertOne(Severity::Fail, 'exactly one is supported');
    }

    /**
     * Both ways into the MISCONFIGURED `fail`: a value set wrongly, and an explicit enable with a
     * key left unset.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function misconfigured(): array
    {
        return [
            'invalid base url' => [['bridge.idle_nudge.base_url' => 'http://mezzanine.example'], 'BRIDGE_IDLE_NUDGE_BASE_URL'],
            'explicit enable, key unset' => [['bridge.idle_nudge.install' => null, 'bridge.idle_nudge.enabled_explicit' => true], 'BRIDGE_IDLE_NUDGE_INSTALL is unset'],
        ];
    }

    /**
     * The MISCONFIGURED `fail` gates no other leg, like the NOT-SET-UP `warn`: the job still
     * judges seat-record agents and records its pass with `problem` set (`IdleNudgeJob::mezzanine()`),
     * so a second instance is still its own `fail`.
     *
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('misconfigured')]
    public function test_the_misconfigured_fail_does_not_swallow_a_second_instance_fail(array $config, string $names): void
    {
        config($config);
        $this->nudgeInstance('idle-nudge-a');
        $this->nudgeInstance('idle-nudge-b');

        $this->assertOne(Severity::Fail, 'MISCONFIGURED');
        $this->assertOne(Severity::Fail, $names);
        $this->assertOne(Severity::Fail, 'exactly one is supported');
    }

    /**
     * The token file is judged whenever `BRIDGE_IDLE_NUDGE_TOKEN_PATH` names one, whatever the
     * OTHER Mezzanine keys are: an operator who set the path and never placed the file has a
     * fault the NOT-SET-UP `warn` for a different key says nothing about (review round 3 of
     * card#10918 — the `fail` was swallowed, so `bridge:check` exited 0 over it).
     */
    public function test_a_configured_token_path_with_no_file_fails_beside_the_not_set_up_warn(): void
    {
        config([
            'bridge.idle_nudge.install' => null,
            'bridge.idle_nudge.enabled_explicit' => null,
            'bridge.idle_nudge.token_path' => $this->dir.'/nonexistent-token',
        ]);

        $this->assertOne(Severity::Warn, 'NOT SET UP');
        $this->assertOne(Severity::Fail, 'the fleet token file '.$this->dir.'/nonexistent-token is absent or unreachable');
    }

    /** The same sibling leg beside the MISCONFIGURED `fail`: the two are different faults. */
    public function test_an_insecure_token_file_fails_beside_the_misconfigured_fail(): void
    {
        chmod($this->dir.'/fleet-token', 0o644);
        config(['bridge.idle_nudge.base_url' => 'http://mezzanine.example']);

        $this->assertOne(Severity::Fail, 'MISCONFIGURED');
        $this->assertOne(Severity::Fail, 'is group/world-readable');
    }

    public function test_every_unset_mezzanine_key_is_named_in_one_line(): void
    {
        config([
            'bridge.idle_nudge.base_url' => null,
            'bridge.idle_nudge.token_path' => null,
            'bridge.idle_nudge.install' => null,
        ]);

        $all = $this->findings();
        $findings = array_values(array_filter($all, fn (Finding $f): bool => str_contains($f->message, 'BRIDGE_IDLE_NUDGE_INSTALL')
            || str_contains($f->message, 'BRIDGE_IDLE_NUDGE_BASE_URL') || str_contains($f->message, 'BRIDGE_IDLE_NUDGE_TOKEN_PATH')));
        $this->assertCount(1, $findings, implode("\n", array_map(fn (Finding $f) => $f->message, $all)));
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        foreach (['BRIDGE_IDLE_NUDGE_BASE_URL', 'BRIDGE_IDLE_NUDGE_TOKEN_PATH', 'BRIDGE_IDLE_NUDGE_INSTALL'] as $key) {
            $this->assertStringContainsString($key, $findings[0]->message);
        }
    }

    /** A key somebody SET, wrongly, is still a broken config — the control for the two above. */
    public function test_a_set_but_invalid_base_url_still_fails(): void
    {
        config(['bridge.idle_nudge.base_url' => 'http://mezzanine.example', 'bridge.idle_nudge.install' => null]);

        $this->assertOne(Severity::Fail, 'BRIDGE_IDLE_NUDGE_BASE_URL');
    }

    /**
     * A value set wrongly AND a key left unset at once: `problem` names both, the invalid value
     * first (it decides the `fail`), so fixing the one it names does not reveal the other only on
     * the next run (review round 3 of card#10918 — the unset key used to be dropped).
     */
    public function test_an_invalid_value_beside_an_unset_key_names_both_in_one_misconfigured_fail(): void
    {
        config(['bridge.idle_nudge.timeout' => '99', 'bridge.idle_nudge.install' => null, 'bridge.idle_nudge.enabled_explicit' => null]);

        $cfg = IdleNudgeConfig::fromConfig();
        $this->assertSame(
            'BRIDGE_IDLE_NUDGE_TIMEOUT must be a whole number of seconds in 1…30 (refused, not clamped); BRIDGE_IDLE_NUDGE_INSTALL is unset — it is REQUIRED: the fleet token reads every install, '
            .'and without the install id this bridge serves, a seat of another install named like a local agent would be nudged here',
            $cfg->problem,
        );
        $this->assertSame([], $cfg->unsetKeys);
        $this->assertOne(Severity::Fail, 'MISCONFIGURED — BRIDGE_IDLE_NUDGE_TIMEOUT must be a whole number of seconds in 1…30 (refused, not clamped); BRIDGE_IDLE_NUDGE_INSTALL is unset');
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f): bool => str_contains($f->message, 'NOT SET UP')));
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
        $this->assertOne(Severity::Warn, 'BRIDGE_IDLE_NUDGE_ENABLED=false');
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

    private const NO_DECLARING_SEAT = 'Warn: idle_nudge: every push-routed Mezzanine-sourced agent read no_declaring_seat on the last pass (%s) — the expected reading until Mezzanine seats publish `protocol_agent_name`, so none of them can be nudged yet.';

    /** @return list<string> */
    private function lines(): array
    {
        return array_map(fn (Finding $f): string => $f->severity->name.': '.$f->message, $this->findings());
    }

    public function test_every_push_routed_agent_on_no_declaring_seat_is_named_as_the_expected_reading(): void
    {
        // Today's live reading: no seat publishes protocol_agent_name.
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['impl' => 'no_declaring_seat', 'pm' => 'no_declaring_seat', 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertSame([sprintf(self::NO_DECLARING_SEAT, 'no_declaring_seat 2, not_push_routed 1')], $this->lines());
    }

    public function test_a_measured_seat_record_agent_does_not_hide_the_no_declaring_seat_reading_from_the_mezzanine_agents(): void
    {
        $this->agentYaml('seat', "idle_nudge:\n  seat_record: /home/seat/.cache/coord/seat-lane-wake-offer.json\n");
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'seat_records' => ['seat' => '/home/seat/.cache/coord/seat-lane-wake-offer.json'],
            'agents' => ['impl' => 'no_declaring_seat', 'pm' => 'no_declaring_seat', 'seat' => 'nudge'], 'failed_agents' => []]);

        $this->assertSame([sprintf(self::NO_DECLARING_SEAT, 'no_declaring_seat 2, nudge 1')], $this->lines());
    }

    public function test_a_seat_record_only_install_with_nothing_measured_is_not_told_about_mezzanine(): void
    {
        $this->seatRecordOnly();
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'seat_records' => ['pm' => '/home/seat/.cache/coord/pm-lane-wake-offer.json'],
            'agents' => ['pm' => 'offer_unmeasured', 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertSame(['Warn: idle_nudge: every push-routed or seat-record agent was UNMEASURED on the last pass (not_push_routed 1, offer_unmeasured 1).'], $this->lines());
    }

    public function test_every_agent_unreadable_on_push_time_names_the_migration_and_the_receiver_config(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['impl' => 'push_time_unreadable', 'pm' => 'push_time_unreadable', 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, 'every push-routed Mezzanine-sourced agent read push_time_unreadable on the last pass (not_push_routed 1, push_time_unreadable 2)');
        $message = $this->findings()[0]->message;
        $this->assertStringContainsString('(a) `php artisan migrate` was not run', $message);
        $this->assertStringContainsString("(b) the webhook receiver's resolved config does not have the nudge enabled", $message);
        $this->assertStringContainsString('reload PHP-FPM', $message);
        $this->assertStringNotContainsString('no_declaring_seat', $message);
    }

    public function test_a_seat_record_agent_does_not_hide_the_push_time_cause_from_the_mezzanine_agents(): void
    {
        // A seat-record agent never reads push_time_unreadable; it must not dilute the predicate.
        $this->agentYaml('seat', "idle_nudge:\n  seat_record: /home/seat/.cache/coord/seat-lane-wake-offer.json\n");
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'seat_records' => ['seat' => '/home/seat/.cache/coord/seat-lane-wake-offer.json'],
            'agents' => ['impl' => 'push_time_unreadable', 'pm' => 'push_time_unreadable', 'quiet' => 'not_push_routed', 'seat' => 'nudge'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, 'reload PHP-FPM');
        $this->assertOne(Severity::Warn, 'read push_time_unreadable on the last pass (not_push_routed 1, nudge 1, push_time_unreadable 2)');
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

        $this->assertOne(Severity::Warn, 'idle_nudge: ON (the default since DL-441) but NOT SET UP for the agents it reads from Mezzanine');
    }

    /** @return array<string, array{string, string}> */
    public static function seatRecordFaults(): array
    {
        return [
            'home unresolved' => ['seat_record_home_unresolved', 'the process the pass ran in has no usable HOME'],
            'absent' => ['seat_record_absent', "pm's seat record /home/seat/.cache/coord/pm-lane-wake-offer.json was ABSENT on the last pass"],
            'not visible' => ['seat_record_not_visible', 'a directory above it is not traversable by the OS user the pass ran as'],
            'unreadable' => ['seat_record_unreadable', 'was present but not read on the last pass'],
            'malformed' => ['seat_record_malformed', 'is not a valid schema-v1 offer record'],
            'unknown version' => ['seat_record_unknown_version', 'carries a schema version this build does not read'],
            'another agent\'s record' => ['seat_record_agent_mismatch', 'was written for another agent: its `agent` is not `pm`, this YAML\'s agent name'],
            'claimed twice' => ['seat_record_seat_claimed_twice', 'each compare their seat record\'s `agent` against `pm`, and one seat\'s record wakes at most one channel'],
            'stale' => ['offer_stale', 'has NOT CHANGED for a whole horizon since its notice was pushed'],
        ];
    }

    public function test_every_seat_record_fault_has_its_own_line(): void
    {
        $codes = array_column(self::seatRecordFaults(), 0);
        $faults = AgentVerdict::SEAT_RECORD_FAULTS;
        sort($codes);
        sort($faults);

        $this->assertSame($faults, $codes);
    }

    #[DataProvider('seatRecordFaults')]
    public function test_a_declared_seat_record_the_pass_could_not_act_on_is_named_per_agent(string $code, string $says): void
    {
        $this->seatRecordOnly();
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'seat_records' => ['pm' => '/home/seat/.cache/coord/pm-lane-wake-offer.json'], 'record_agents' => ['pm' => 'pm'], 'agents' => ['pm' => $code, 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, $says);
        $this->assertOne(Severity::Warn, 'This seat is not nudged.');
    }

    /** @param  string|null  $compared  the pass record's `record_agents` member for the agent; null = a record from before it */
    private function mismatch(string $yaml, ?string $compared): string
    {
        $this->seatRecordOnly();
        File::delete($this->dir.'/pm.yml');
        $this->agentYaml('kanban-solo', $yaml);
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'seat_records' => ['kanban-solo' => '/home/seat/.cache/coord/kanban-lane-wake-offer.json'],
            ...($compared === null ? [] : ['record_agents' => ['kanban-solo' => $compared]]),
            'agents' => ['kanban-solo' => 'seat_record_agent_mismatch', 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        return $this->lines()[0];
    }

    public function test_a_mismatch_under_the_yaml_agent_name_names_it_and_the_seat_agent_remedy(): void
    {
        $this->assertSame(
            "Warn: idle_nudge: kanban-solo's seat record /home/seat/.cache/coord/kanban-lane-wake-offer.json was written for another agent: its `agent` is not `kanban-solo`, "
            .'this YAML\'s agent name (no `idle_nudge.seat_agent` is set). If this file is the intended seat\'s record — the seat\'s own agent name differs from the bridge agent name — '
            .'set `idle_nudge.seat_agent` to the `agent` value inside it; otherwise point `idle_nudge.seat_record` at the intended seat\'s record. This seat is not nudged.',
            $this->mismatch("idle_nudge:\n  seat_record: /home/seat/.cache/coord/kanban-lane-wake-offer.json\n", 'kanban-solo'),
        );
    }

    public function test_a_mismatch_under_a_seat_agent_key_names_the_key_as_the_comparand(): void
    {
        $this->assertSame(
            "Warn: idle_nudge: kanban-solo's seat record /home/seat/.cache/coord/kanban-lane-wake-offer.json was written for another agent: its `agent` is not `kanban`, "
            .'the value of this YAML\'s `idle_nudge.seat_agent`. If this file is the intended seat\'s record, set `idle_nudge.seat_agent` to the `agent` value inside it; '
            .'otherwise point `idle_nudge.seat_record` at the intended seat\'s record. This seat is not nudged.',
            $this->mismatch("idle_nudge:\n  seat_record: /home/seat/.cache/coord/kanban-lane-wake-offer.json\n  seat_agent: kanban\n", 'kanban'),
        );
    }

    /**
     * The adoption path: the operator sees the mismatch, adds `seat_agent`, and runs bridge:check
     * before the next tick. The verdict is the last pass's, so its reason must be too.
     */
    public function test_a_mismatch_after_the_yaml_changed_names_the_comparand_the_pass_used_and_the_one_it_will_use(): void
    {
        $this->assertSame(
            "Warn: idle_nudge: kanban-solo's seat record /home/seat/.cache/coord/kanban-lane-wake-offer.json was written for another agent: its `agent` is not `kanban-solo`, "
            .'the name the last pass compared it against. This YAML has changed since: the record must now carry `kanban`, the value of this YAML\'s `idle_nudge.seat_agent`, '
            .'which the next pass judges. This seat is not nudged.',
            $this->mismatch("idle_nudge:\n  seat_record: /home/seat/.cache/coord/kanban-lane-wake-offer.json\n  seat_agent: kanban\n", 'kanban-solo'),
        );
    }

    public function test_a_mismatch_on_a_pass_record_from_before_the_comparand_was_recorded_says_so(): void
    {
        $this->assertSame(
            "Warn: idle_nudge: kanban-solo's seat record /home/seat/.cache/coord/kanban-lane-wake-offer.json was written for another agent (the last-pass record predates the recorded comparand, "
            .'so the name that pass compared against is not known; as declared now it is `kanban`, the value of this YAML\'s `idle_nudge.seat_agent`). '
            .'If this file is the intended seat\'s record, set `idle_nudge.seat_agent` to the `agent` value inside it; '
            .'otherwise point `idle_nudge.seat_record` at the intended seat\'s record. This seat is not nudged.',
            $this->mismatch("idle_nudge:\n  seat_record: /home/seat/.cache/coord/kanban-lane-wake-offer.json\n  seat_agent: kanban\n", null),
        );
    }

    public function test_two_agents_claiming_one_seat_are_each_named_with_every_claimant_and_the_remedy(): void
    {
        $this->seatRecordOnly();
        File::delete($this->dir.'/pm.yml');
        $this->agentYaml('kanban-solo', "idle_nudge:\n  seat_record: /home/seat/.cache/coord/kanban-lane-wake-offer.json\n  seat_agent: kanban\n");
        $this->agentYaml('prod-agent', "idle_nudge:\n  seat_record: /home/seat/.cache/coord/kanban-lane-wake-offer.json\n  seat_agent: kanban\n");
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'seat_records' => ['kanban-solo' => null, 'prod-agent' => null],
            'record_agents' => ['kanban-solo' => 'kanban', 'prod-agent' => 'kanban'],
            'agents' => ['kanban-solo' => 'seat_record_seat_claimed_twice', 'prod-agent' => 'seat_record_seat_claimed_twice', 'quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $line = fn (string $agent): string => "Warn: idle_nudge: {$agent}'s seat record declared as /home/seat/.cache/coord/kanban-lane-wake-offer.json was not read on the last pass: "
            .'`kanban-solo`, `prod-agent` each compare their seat record\'s `agent` against `kanban`, and one seat\'s record wakes at most one channel, so none of them is sent it. '
            .'Keep that seat\'s `idle_nudge.seat_record` (and `idle_nudge.seat_agent`) in exactly one of those YAMLs and remove it from the others. This seat is not nudged.';
        $this->assertSame([
            $line('kanban-solo'),
            $line('prod-agent'),
            'Warn: idle_nudge: every push-routed or seat-record agent was UNMEASURED on the last pass (not_push_routed 1, seat_record_seat_claimed_twice 2).',
        ], $this->lines());
    }

    public function test_each_claim_line_names_only_the_agents_of_its_own_claim(): void
    {
        $this->seatRecordOnly();
        File::delete($this->dir.'/pm.yml');
        foreach (['kanban-solo' => 'kanban', 'prod-agent' => 'kanban', 'pm-a' => 'pm', 'pm-b' => 'pm'] as $agent => $seat) {
            $this->agentYaml($agent, "idle_nudge:\n  seat_record: /home/seat/.cache/coord/{$seat}-lane-wake-offer.json\n  seat_agent: {$seat}\n");
        }
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null,
            'seat_records' => ['kanban-solo' => null, 'pm-a' => null, 'pm-b' => null, 'prod-agent' => null],
            'record_agents' => ['kanban-solo' => 'kanban', 'pm-a' => 'pm', 'pm-b' => 'pm', 'prod-agent' => 'kanban'],
            'agents' => ['kanban-solo' => 'seat_record_seat_claimed_twice', 'pm-a' => 'seat_record_seat_claimed_twice', 'pm-b' => 'seat_record_seat_claimed_twice', 'prod-agent' => 'seat_record_seat_claimed_twice', 'quiet' => 'not_push_routed'],
            'failed_agents' => []]);

        $line = fn (string $agent, string $seat, string $claimants): string => "Warn: idle_nudge: {$agent}'s seat record declared as /home/seat/.cache/coord/{$seat}-lane-wake-offer.json was not read on the last pass: "
            ."{$claimants} each compare their seat record's `agent` against `{$seat}`, and one seat's record wakes at most one channel, so none of them is sent it. "
            .'Keep that seat\'s `idle_nudge.seat_record` (and `idle_nudge.seat_agent`) in exactly one of those YAMLs and remove it from the others. This seat is not nudged.';
        $this->assertSame([
            $line('kanban-solo', 'kanban', '`kanban-solo`, `prod-agent`'),
            $line('pm-a', 'pm', '`pm-a`, `pm-b`'),
            $line('pm-b', 'pm', '`pm-a`, `pm-b`'),
            $line('prod-agent', 'kanban', '`kanban-solo`, `prod-agent`'),
            'Warn: idle_nudge: every push-routed or seat-record agent was UNMEASURED on the last pass (not_push_routed 1, seat_record_seat_claimed_twice 4).',
        ], $this->lines());
    }

    public function test_a_fleet_read_that_did_not_measure_is_named_beside_the_verdicts(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => 'the fleet snapshot answered HTTP 401 (token_expired)', 'agents' => ['pm' => 'fleet_unmeasured'], 'failed_agents' => []]);

        // The whole output: the `no_declaring_seat` reading would be false guidance here.
        $this->assertSame(
            ['Warn: idle_nudge: the last pass could not read the fleet snapshot — the fleet snapshot answered HTTP 401 (token_expired). No Mezzanine-sourced agent was judged, so the absence of their nudges says nothing about idle seats. (fleet_unmeasured 1)'],
            array_map(fn (Finding $f): string => $f->severity->name.': '.$f->message, $this->findings()),
        );
    }

    public function test_a_fleet_read_that_did_not_measure_beside_a_measured_seat_record_agent_reports_both(): void
    {
        $this->agentYaml('seat', "idle_nudge:\n  seat_record: /home/seat/.cache/coord/seat-lane-wake-offer.json\n");
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => 'the fleet snapshot answered HTTP 401 (token_expired)', 'agents' => ['pm' => 'fleet_unmeasured', 'seat' => 'nudge'], 'failed_agents' => []]);

        $this->assertSame(
            [
                'Warn: idle_nudge: the last pass could not read the fleet snapshot — the fleet snapshot answered HTTP 401 (token_expired). No Mezzanine-sourced agent was judged, so the absence of their nudges says nothing about idle seats. (fleet_unmeasured 1, nudge 1)',
                'Ok: idle_nudge: last pass measured 1 of 2 push-routed or seat-record agent(s) (fleet_unmeasured 1, nudge 1)',
            ],
            array_map(fn (Finding $f): string => $f->severity->name.': '.$f->message, $this->findings()),
        );
    }

    public function test_a_seat_record_fault_names_the_path_the_tick_read_not_one_resolved_here(): void
    {
        File::delete($this->dir.'/pm.yml');
        $this->agentYaml('pm', "idle_nudge:\n  seat_record: ~/.cache/coord/pm-lane-wake-offer.json\n");
        config(['bridge.idle_nudge.install' => null, 'bridge.idle_nudge.base_url' => null, 'bridge.idle_nudge.token_path' => null]);
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'seat_records' => ['pm' => '/home/tick-user/.cache/coord/pm-lane-wake-offer.json'], 'agents' => ['pm' => 'seat_record_absent'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, "pm's seat record /home/tick-user/.cache/coord/pm-lane-wake-offer.json was ABSENT on the last pass");
    }

    public function test_a_pass_record_from_before_the_resolved_path_falls_back_to_the_declared_value_and_says_so(): void
    {
        File::delete($this->dir.'/pm.yml');
        $this->agentYaml('pm', "idle_nudge:\n  seat_record: ~/.cache/coord/pm-lane-wake-offer.json\n");
        config(['bridge.idle_nudge.install' => null, 'bridge.idle_nudge.base_url' => null, 'bridge.idle_nudge.token_path' => null]);
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'agents' => ['pm' => 'seat_record_absent'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, "pm's seat record declared as ~/.cache/coord/pm-lane-wake-offer.json (the last-pass record predates the resolved path, so the path the tick read is not known) was ABSENT");
    }

    public function test_an_unresolved_home_names_the_declared_value(): void
    {
        File::delete($this->dir.'/pm.yml');
        $this->agentYaml('pm', "idle_nudge:\n  seat_record: ~/.cache/coord/pm-lane-wake-offer.json\n");
        config(['bridge.idle_nudge.install' => null, 'bridge.idle_nudge.base_url' => null, 'bridge.idle_nudge.token_path' => null]);
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null, 'seat_records' => ['pm' => null], 'agents' => ['pm' => 'seat_record_home_unresolved'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, "pm's seat record declared as ~/.cache/coord/pm-lane-wake-offer.json could not be resolved on the last pass");
    }

    public function test_agent_yamls_that_do_not_load_leave_the_source_question_unvalidated(): void
    {
        $this->agentYaml('broken', "idle_nudge:\n  seat_record: relative/offer.json\n");

        $this->assertOne(Severity::Unvalidated, 'the agent YAMLs could not be loaded');
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f): bool => $f->severity === Severity::Fail));
    }

    /*
     * card#10918 review round 3: every leg skips only on its OWN missing input. Each test below
     * pins a leg that an earlier `return` used to swallow although it never read what that
     * branch tested.
     */

    public function test_agent_yamls_that_do_not_load_do_not_swallow_the_instance_or_last_pass_legs(): void
    {
        $this->agentYaml('broken', "idle_nudge:\n  seat_record: relative/offer.json\n");
        $this->nudgeInstance('one', Carbon::now());
        $this->nudgeInstance('two', Carbon::now());
        $this->record(['measured' => true, 'agents' => ['pm' => 'nudge'], 'failed_agents' => ['pm']]);

        $this->assertOne(Severity::Unvalidated, 'the agent YAMLs could not be loaded');
        $this->assertOne(Severity::Fail, '2 ENABLED `idle_nudge` instances (one, two)');
        $this->assertOne(Severity::Warn, 'the last pass FAILED to push to pm');
    }

    /** Without the YAMLs the Mezzanine/seat-record split is unknown: the generic reading speaks instead. */
    public function test_agent_yamls_that_do_not_load_skip_only_the_mezzanine_split_readings(): void
    {
        $this->agentYaml('broken', "idle_nudge:\n  seat_record: relative/offer.json\n");
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['impl' => 'no_declaring_seat', 'pm' => 'no_declaring_seat'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, 'every push-routed or seat-record agent was UNMEASURED on the last pass (no_declaring_seat 2)');
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f): bool => str_contains($f->message, 'Mezzanine-sourced agent read')));
    }

    public function test_a_seat_record_fault_without_the_yamls_names_the_path_the_tick_read_or_says_it_is_not_known(): void
    {
        $this->agentYaml('broken', "idle_nudge:\n  seat_record: relative/offer.json\n");
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'seats' => null, 'fleet_unmeasured' => null,
            'seat_records' => ['a' => '/home/seat/a-offer.json', 'b' => null], 'record_agents' => ['c' => 'seat-c'],
            'agents' => ['a' => 'seat_record_absent', 'b' => 'seat_record_home_unresolved', 'c' => 'seat_record_agent_mismatch'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, "a's seat record /home/seat/a-offer.json was ABSENT on the last pass");
        $this->assertOne(Severity::Warn, "b's seat record (path not known: the last pass recorded none and the agent YAMLs did not load) could not be resolved");
        $this->assertOne(Severity::Warn, "c's seat record (path not known: the last pass recorded none and the agent YAMLs did not load) was written for another agent: its `agent` is not `seat-c`, the name the last pass compared it against. If this file is the intended seat's record, set `idle_nudge.seat_agent`");
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f): bool => str_contains($f->message, 'no longer declared')));
    }

    public function test_more_than_one_instance_does_not_swallow_the_last_pass(): void
    {
        $this->nudgeInstance('one', Carbon::now());
        $this->nudgeInstance('two', Carbon::now());
        $this->record(['measured' => false, 'reason' => 'the fleet snapshot answered HTTP 401 (token_expired)']);

        $this->assertOne(Severity::Fail, 'exactly one is supported');
        $this->assertOne(Severity::Warn, 'the last pass was UNMEASURED — the fleet snapshot answered HTTP 401 (token_expired)');
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f): bool => str_contains($f->message, 'NOT RUN yet') || str_contains($f->message, 'last ran')));
    }

    public function test_an_unreadable_job_registry_does_not_swallow_the_last_pass(): void
    {
        $this->record(['measured' => true, 'agents' => ['pm' => 'nudge'], 'failed_agents' => ['pm']]);
        Schema::drop('scheduled_jobs');

        $this->assertOne(Severity::Unvalidated, 'could not read the periodic-job registry');
        $this->assertOne(Severity::Warn, 'the last pass FAILED to push to pm');
    }

    /** An unread registry cannot rule a pass out, so a missing record stays a finding. */
    public function test_an_unreadable_job_registry_still_reports_a_missing_record(): void
    {
        Schema::drop('scheduled_jobs');

        $this->assertOne(Severity::Unvalidated, 'no readable last-pass record');
    }

    public function test_no_instance_does_not_swallow_a_record_an_earlier_instance_left(): void
    {
        $this->record(['measured' => true, 'agents' => ['pm' => 'nudge'], 'failed_agents' => ['pm']]);

        $this->assertOne(Severity::Warn, 'no ENABLED `idle_nudge` job instance exists');
        $this->assertOne(Severity::Warn, 'the last pass FAILED to push to pm');
    }

    public function test_an_instance_that_never_ran_does_not_swallow_a_record_an_earlier_instance_left(): void
    {
        $this->nudgeInstance();
        $this->record(['measured' => false, 'reason' => 'the fleet snapshot answered HTTP 401 (token_expired)']);

        $this->assertOne(Severity::Warn, "instance 'idle-nudge' has NOT RUN yet");
        $this->assertOne(Severity::Warn, 'the last pass was UNMEASURED');
    }

    /**
     * Where the registry shows nothing has ever run, no record is expected: its absence is what
     * the instance warn already says, so it is not reported a second time as a blind read.
     *
     * @return array<string, array{\Closure(self): void, string}>
     */
    public static function nothingHasRun(): array
    {
        return [
            'no instance' => [fn (self $t) => null, 'no ENABLED `idle_nudge` job instance exists'],
            'never ran' => [fn (self $t) => $t->nudgeInstance(), 'has NOT RUN yet'],
            'two, neither ran' => [function (self $t): void {
                $t->nudgeInstance('one');
                $t->nudgeInstance('two');
            }, 'exactly one is supported'],
        ];
    }

    /** @param  \Closure(self): void  $arrange */
    #[DataProvider('nothingHasRun')]
    public function test_a_missing_record_is_not_reported_where_nothing_has_run(\Closure $arrange, string $says): void
    {
        $arrange($this);

        $this->assertCount(1, array_filter($this->findings(), fn (Finding $f): bool => str_contains($f->message, $says)));
        $this->assertSame([], array_filter($this->findings(), fn (Finding $f): bool => str_contains($f->message, 'last-pass record')));
    }

    /** The control for the one above: once anything has run, a missing record IS a blind read. */
    public function test_a_missing_record_is_reported_once_any_of_several_instances_has_run(): void
    {
        $this->nudgeInstance('one', Carbon::now());
        $this->nudgeInstance('two');

        $this->assertOne(Severity::Fail, 'exactly one is supported');
        $this->assertOne(Severity::Unvalidated, 'no readable last-pass record');
    }
}
