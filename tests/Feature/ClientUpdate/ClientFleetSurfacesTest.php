<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\ClientFleetCheck;
use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\ClientCapabilities;
use App\Models\SeatClientState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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

    /**
     * Run `$body` with the models pointed at a database that refuses connections, then point them
     * back — before RefreshDatabase's teardown, which rolls back on the DEFAULT connection. Not
     * `Schema::drop()`: on MariaDB that DDL commits RefreshDatabase's transaction and takes the
     * table from every later test (the WritebackBoardDivergenceLedgerTest precedent).
     */
    private function withADeadDatabase(\Closure $body): void
    {
        $default = config('database.default');
        config(['database.connections.dead-ledger' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
            'database' => 'nothing', 'username' => 'nobody', 'password' => '',
        ], 'database.default' => 'dead-ledger']);
        try {
            $body();
        } finally {
            config(['database.default' => $default]);
        }
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
        $this->assertSame('client_fleet: seat legacy is OFF THE UPDATE PATH — its latest board-tools call (1h ago) came from client 0.9.27 with no launch identity — a channel server not started by the client updater, so it will not update itself until its client is bootstrapped from this bridge\'s published pack: on the seat, as its own OS user, from a bridge checkout at this bridge\'s release tag, run `python3 bin/provision-board-tools.py --role b --bootstrap-client --agent legacy --project-dir <its-claude-project-dir> --channel-name <its-mcp-servers-key>`, then restart its session. `php artisan bridge:client-fleet` shows every seat.', $message);
    }

    private const HTTP_CAVEAT = 'seat colo requires client-update approval and uses the http transport, so its channel server is on this box: if it runs as an OS user that can run `php artisan` here, it can approve itself with `bridge:client-approve` — its approval is then a record, not a gate';

    public function test_the_leg_warns_on_an_approval_seat_on_the_http_transport(): void
    {
        $findings = $this->check([$this->agent('colo', "  client_update:\n    approval_required: true\n", 'http')]);

        $this->assertContains([Severity::Warn->value, 'client_fleet: '.self::HTTP_CAVEAT.'.'], $findings);
    }

    /**
     * The http-approval caveat is a per-seat fact in ClientFleet, so both surfaces print the same
     * verdict: the check warns, and the command prints the caveat and counts the seat as needing you.
     */
    public function test_both_surfaces_agree_on_the_http_approval_caveat(): void
    {
        $colo = $this->agent('colo', "  client_update:\n    approval_required: true\n", 'http');
        $findings = $this->check([$colo]);

        $warns = array_values(array_filter($findings, static fn (array $f): bool => $f[0] === Severity::Warn->value));
        $this->assertSame([[Severity::Warn->value, 'client_fleet: '.self::HTTP_CAVEAT.'.']], $warns);
        $this->artisan('bridge:client-fleet')
            ->expectsOutputToContain('    ⚠ '.self::HTTP_CAVEAT.'.')
            ->expectsOutput('1 seat(s) — needs_bootstrap ×1; 1 need(s) you (bridge:check warns on the same seats).')
            ->assertExitCode(0);
    }

    public function test_the_leg_is_unvalidated_when_the_ledger_cannot_be_read(): void
    {
        $seat = $this->agent('seat');
        $findings = [];
        $this->withADeadDatabase(function () use ($seat, &$findings): void {
            $findings = $this->check([$seat]);
        });

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
            // card#11579: the gap is against this bridge's OWN client, so a 0.9.27 seat lacks ci_await
            // even though the published pack is 0.9.28 — "none" here was the circular read.
            ->expectsOutputToContain('    running: not reported · installed: not reported · last seen: 1h ago · approval: not required · capability gap vs this bridge\'s client '.ClientCapabilities::bundled()->currentClientVersion.': ')
            ->expectsOutput('1 seat(s) — off_update_path ×1; 1 need(s) you (bridge:check warns on the same seats).')
            ->assertExitCode(0);

        // A second matcher on the SAME line never sees it (the first `expectsOutputToContain`
        // consumes it), so the gap's content is read off a plain run.
        Artisan::call('bridge:client-fleet');
        $this->assertMatchesRegularExpression('/capability gap vs [^\n]*\bci_await \(/', Artisan::output());
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
        $this->withADeadDatabase(function (): void {
            $this->artisan('bridge:client-fleet')->expectsOutputToContain('database unreachable')->assertExitCode(1)->run();
        });
    }
}
