<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\ClientFleetCheck;
use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Severity;
use App\Models\SeatClientState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\ClientPackFixture;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The two operator surfaces over the fleet (card#10567 B4): `bridge:check`'s
 * `board_tools.client_fleet` leg and `bridge:client-fleet`. Both print what `ClientFleet` derived;
 * the states themselves are `ClientFleetStateTest`'s.
 */
class ClientFleetSurfacesTest extends TestCase
{
    use MaterializesChecks;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/client-fleet-surfaces-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir, 'bridge.state_dir' => $this->dir.'/state']);
        Carbon::setTestNow('2026-09-28T12:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function agent(string $name, string $extra = '', string $transport = 'ssh'): AgentConfig
    {
        $auth = $transport === 'http' ? "  auth:\n    token_path: {$this->dir}/{$name}-token\n" : '';
        File::put("{$this->dir}/{$name}.yml", "identity:\n  kanban_user_id: 1\nsubscriptions: []\nboard_tools:\n  enabled: true\n  transport: {$transport}\n{$auth}  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n{$extra}");

        return AgentConfig::fromArray($name, (array) Yaml::parseFile("{$this->dir}/{$name}.yml"));
    }

    private function publish(): void
    {
        $f = new ClientPackFixture;
        (new ClientPackStore)->publish(ClientPackManifest::parse($f->manifest), $f->manifest, $f->pack, '2026-09-27T00:00:00Z');
    }

    /**
     * @param  list<AgentConfig>  $agents
     * @return list<array{0: string, 1: string}>
     */
    private function check(array $agents): array
    {
        $ctx = new CheckContext;
        $ctx->boardToolsEnabled = $agents;

        return array_map(static fn ($f): array => [$f->severity->value, $f->message], $this->findingsOf(new ClientFleetCheck, $ctx));
    }

    public function test_the_leg_is_one_ok_line_when_nothing_needs_the_operator(): void
    {
        $findings = $this->check([$this->agent('seat')]);

        $this->assertSame([[Severity::Ok->value, 'client_fleet: 1 seat(s), none needing you — this bridge publishes no client pack yet, so no seat can be on the update path; needs_bootstrap ×1.']], $findings);
    }

    public function test_the_leg_warns_once_per_seat_that_needs_the_operator_and_never_fails(): void
    {
        $this->publish();
        SeatClientState::query()->create(['agent' => 'legacy', 'last_call_at' => Carbon::parse('2026-09-28T11:00:00Z'), 'last_call_client_version' => '0.9.27']);
        SeatClientState::query()->create(['agent' => 'ok', 'last_call_at' => Carbon::parse('2026-09-28T11:00:00Z'), 'last_call_launch_id' => 'L1', 'running_launch_id' => 'L1', 'running_bridge_release' => '0.91.0', 'running_launch_first_seen_at' => Carbon::parse('2026-09-28T10:00:00Z')]);

        $findings = $this->check([$this->agent('legacy'), $this->agent('ok')]);

        $this->assertCount(1, $findings);
        [$severity, $message] = $findings[0];
        $this->assertSame(Severity::Warn->value, $severity);
        $this->assertSame('client_fleet: seat legacy is OFF THE UPDATE PATH — its latest board-tools call (1h ago) came from client 0.9.27 with no launch identity — a channel server not started by the client updater, so it will not update itself until its client is bootstrapped from this bridge\'s published pack. `php artisan bridge:client-fleet` shows every seat.', $message);
    }

    public function test_the_leg_warns_on_an_approval_seat_that_runs_as_the_bridge(): void
    {
        $findings = $this->check([$this->agent('colo', "  client_update:\n    approval_required: true\n", 'http')]);

        $this->assertContains([Severity::Warn->value, 'client_fleet: seat colo requires client-update approval but uses the http transport, so it runs as this bridge\'s own OS user and can run `bridge:client-approve` for itself — its approval is a record, not a gate.'], $findings);
    }

    public function test_the_leg_is_unvalidated_when_the_ledger_cannot_be_read(): void
    {
        Schema::drop('seat_client_states');

        $findings = $this->check([$this->agent('seat')]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated->value, $findings[0][0]);
        $this->assertStringStartsWith('client_fleet: the fleet ledger could not be read (', $findings[0][1]);
        $this->assertStringEndsWith('An install that has not run `php artisan migrate` is the usual cause.', $findings[0][1]);
    }

    public function test_client_fleet_prints_each_seat_and_the_publication(): void
    {
        $this->publish();
        $this->agent('legacy');
        SeatClientState::query()->create(['agent' => 'legacy', 'last_call_at' => Carbon::parse('2026-09-28T11:00:00Z'), 'last_call_client_version' => '0.9.27']);

        $this->artisan('bridge:client-fleet')
            ->expectsOutput('bridge:client-fleet: published client pack: release 0.91.0 (client 0.9.28, content bbbbbbbbbbbb), published 2026-09-27T00:00:00Z.')
            ->expectsOutputToContain('seat legacy [ssh] — OFF THE UPDATE PATH: its latest board-tools call (1h ago) came from client 0.9.27 with no launch identity')
            ->expectsOutputToContain('    running: not reported · installed: not reported · last seen: 1h ago · approval: not required · capability gap: none')
            ->expectsOutput('1 seat(s) — off_update_path ×1; 1 need(s) you (bridge:check warns on the same seats).')
            ->assertExitCode(0);
    }

    public function test_client_fleet_says_when_nothing_is_published_or_configured(): void
    {
        $this->artisan('bridge:client-fleet')
            ->expectsOutput('bridge:client-fleet: this bridge publishes no client pack yet (`php artisan bridge:client-pack:install`), so no seat can be on the update path.')
            ->expectsOutput('bridge:client-fleet: no agent has an enabled board_tools block, so there is no fleet to show.')
            ->assertExitCode(0);
    }

    public function test_client_fleet_json_is_the_fleet_document(): void
    {
        $this->agent('seat');

        $this->assertSame(0, Artisan::call('bridge:client-fleet', ['--json' => true]));
        $doc = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($doc);
        $this->assertSame(['published', 'published_error', 'spread', 'seats'], array_keys($doc));
        $this->assertSame('needs_bootstrap', $doc['seats'][0]['state']);
    }

    public function test_client_fleet_exits_non_zero_when_the_ledger_cannot_be_read(): void
    {
        $this->agent('seat');
        Schema::drop('seat_client_states');

        $this->artisan('bridge:client-fleet')->expectsOutputToContain('database query failed, but the server ANSWERED')->assertExitCode(1);
    }
}
