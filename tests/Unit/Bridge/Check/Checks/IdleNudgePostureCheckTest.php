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

        $this->assertOne(Severity::Warn, 'every push-routed agent was UNMEASURED on the last pass (no_declaring_seat 2, not_push_routed 1)');
    }

    public function test_no_push_routed_agent_at_all_warns(): void
    {
        $this->nudgeInstance(lastRunAt: Carbon::now());
        $this->record(['measured' => true, 'agents' => ['quiet' => 'not_push_routed'], 'failed_agents' => []]);

        $this->assertOne(Severity::Warn, 'no declared agent sets `channel.route_intents: true`');
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

        $this->assertOne(Severity::Ok, 'last pass measured 1 of 2 push-routed agent(s) (no_declaring_seat 1, not_idle 1, not_push_routed 1)');
    }
}
