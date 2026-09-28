<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\ClientUpdate\ClientUpdateDoor;
use App\Bridge\ClientUpdate\InstallLogEntry;
use App\Bridge\ClientUpdate\SeatClientLedger;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Tools\ToolsCallStdio;
use App\Models\SeatClientEvent;
use App\Models\SeatClientState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\CallingSeatSeal;
use Tests\Support\ClientPackFixture;
use Tests\Support\FakeToolsCallStdio;
use Tests\TestCase;

/**
 * The fleet ledger through the real doors (card#10567 B4): `client_report`, the approval gate on
 * `client_manifest`'s offer, `client_fleet`, and what a board-tools call's `caller` / `launch` keys
 * write — on BOTH transports.
 */
class FleetLedgerDoorTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALL = '6f1c2d3e-0000-4000-8000-000000000001';

    private string $dir;

    private string $bearer = 'http-seat-bearer-xyz';   // gitleaks:allow — test fixture

    private string $pmBearer = 'http-pm-bearer-abc';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/fleet-ledger-door-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        $this->secret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->secret($this->dir.'/httpseat-tools-token', $this->bearer);
        $this->secret($this->dir.'/httppm-tools-token', $this->pmBearer);
        $scope = "  board_id: 10\n  create_stage_id: 55\n";
        File::put($this->dir.'/httpseat.yml', "identity:\n  kanban_user_id: 11\nsubscriptions: []\nboard_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$this->dir}/httpseat-tools-token\n  swimlane_id: 4\n{$scope}");
        File::put($this->dir.'/httppm.yml', "identity:\n  kanban_user_id: 13\nsubscriptions: []\nboard_tools:\n  enabled: true\n  transport: http\n  fleet_view: true\n  auth:\n    token_path: {$this->dir}/httppm-tools-token\n  swimlane_id: 6\n{$scope}");
        File::put($this->dir.'/sshseat.yml', "identity:\n  kanban_user_id: 12\nsubscriptions: []\nboard_tools:\n  transport: ssh\n  swimlane_id: 5\n{$scope}");
        File::put($this->dir.'/sshpm.yml', "identity:\n  kanban_user_id: 14\nsubscriptions: []\nboard_tools:\n  transport: ssh\n  fleet_view: true\n  swimlane_id: 7\n{$scope}");
        File::put($this->dir.'/gated.yml', "identity:\n  kanban_user_id: 15\nsubscriptions: []\nboard_tools:\n  transport: ssh\n  client_update:\n    approval_required: true\n  swimlane_id: 8\n{$scope}");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
        Http::fake();
    }

    protected function tearDown(): void
    {
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

    private function secret(string $path, string $value): void
    {
        File::put($path, $value);
        chmod($path, 0o600);
    }

    private function publish(ClientPackFixture $f): void
    {
        (new ClientPackStore)->publish(ClientPackManifest::parse($f->manifest), $f->manifest, $f->pack, '2026-09-27T00:00:00Z');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: array<string, mixed>, raw: string}
     */
    private function http(string $route, array $body, ?string $bearer = null): array
    {
        // Each request is its own serving process in production; the seat seal is per process.
        CallingSeatSeal::forANewServingProcess();
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        $bearer ??= $this->bearer;
        if ($bearer !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $response = $this->call('POST', $route, [], [], [], $server, (string) json_encode($body));
        $raw = (string) $response->getContent();

        return ['status' => $response->getStatusCode(), 'body' => (array) json_decode($raw, true), 'raw' => $raw];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{exit: int, body: array<string, mixed>, raw: string}
     */
    private function ssh(array $body, string $agent = 'sshseat'): array
    {
        CallingSeatSeal::forANewServingProcess();
        $io = new FakeToolsCallStdio((string) json_encode($body));
        $this->app->instance(ToolsCallStdio::class, $io);
        $exit = $this->artisan('bridge:tools-call', ['--agent' => $agent])->run();
        $raw = $io->capturedOut();

        return ['exit' => $exit, 'body' => (array) json_decode($raw, true), 'raw' => $raw];
    }

    /**
     * Install-log lines as the updater writes them: each names the sha256 of the line before it.
     *
     * @param  list<array<string, mixed>>  $entries  fields beyond install_id/seq/prev_sha256
     * @return list<string>
     */
    private static function log(array $entries, int $firstSeq = 1, ?string $prev = null, string $install = self::INSTALL): array
    {
        $lines = [];
        foreach ($entries as $i => $fields) {
            $fields += ['actor' => 'launch', 'result' => 'ok'];
            if ($fields['actor'] === 'launch') {
                $fields += ['launch_id' => 'L1'];   // every launch line names its launch
            }
            $line = (string) json_encode(['install_id' => $install, 'seq' => $firstSeq + $i, 'time' => '2026-09-28T10:00:00Z'] + $fields + ['prev_sha256' => $prev], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $lines[] = $line;
            $prev = hash('sha256', $line);
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private static function install(string $release = '0.91.0', string $launch = 'L1'): array
    {
        return ['action' => 'install', 'from_bridge_release' => '0.90.0', 'to_bridge_release' => $release, 'client_version' => '0.9.28', 'files_json_sha256' => str_repeat('b', 64), 'launch_id' => $launch];
    }

    // ───────────────────────── client_report ─────────────────────────

    public function test_client_report_stores_the_log_and_both_doors_answer_the_same_head(): void
    {
        $lines = self::log([self::install(), ['action' => 'prune', 'launch_id' => 'L1']]);

        $http = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => $lines]);
        $this->assertSame(200, $http['status']);
        $this->assertSame(['install_id' => self::INSTALL, 'seq' => 2, 'sha256' => hash('sha256', $lines[1])], $http['body']['log_head']);
        $this->assertSame(2, $http['body']['stored']);
        $this->assertFalse($http['body']['discontinuity']);

        $ssh = $this->ssh(['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => $lines]);
        $this->assertSame(0, $ssh['exit']);
        $this->assertSame(2, $ssh['body']['stored']);

        $row = SeatClientState::query()->where('agent', 'httpseat')->firstOrFail();
        $this->assertSame('0.91.0', $row->installed_bridge_release);
        $this->assertSame('0.9.28', $row->installed_client_version);
        $this->assertSame('ok', $row->last_launch_result);
        $this->assertSame('L1', $row->last_launch_id);
        $this->assertSame(2, SeatClientEvent::query()->where('agent', 'httpseat')->count());
        $this->assertSame(2, SeatClientEvent::query()->where('agent', 'sshseat')->count());
    }

    public function test_client_manifest_carries_the_log_head_a_report_stored(): void
    {
        $this->publish(new ClientPackFixture);
        $this->assertNull($this->http('/agent-tools/client', ['op' => 'client_manifest'])['body']['log_head']);

        $lines = self::log([self::install()]);
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => $lines]);

        $this->assertSame(['install_id' => self::INSTALL, 'seq' => 1, 'sha256' => hash('sha256', $lines[0])], $this->http('/agent-tools/client', ['op' => 'client_manifest'])['body']['log_head']);
    }

    public function test_a_resent_line_with_the_same_bytes_is_skipped(): void
    {
        $lines = self::log([self::install(), ['action' => 'prune']]);
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => [$lines[0]]]);
        $again = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => $lines]);

        $this->assertSame(1, $again['body']['stored']);
        $this->assertFalse($again['body']['discontinuity']);
        $this->assertSame(2, SeatClientEvent::query()->where('agent', 'httpseat')->count());
    }

    /**
     * @return array<string, array{0: callable(): list<list<string>>, 1: string}>
     */
    public static function discontinuities(): array
    {
        return [
            'a gap' => [static fn (): array => [self::log([self::install()]), self::log([['action' => 'prune']], 3, hash('sha256', self::log([self::install()])[0]))], 'seq 2–2 of install'],
            'a first report that does not start at seq 1' => [static fn (): array => [self::log([self::install()], 4)], 'so seq 1–3 never arrived'],
            'a broken link to the stored head' => [static fn (): array => [self::log([self::install()]), self::log([['action' => 'prune']], 2, str_repeat('0', 64))], 'does not chain to the entry before it'],
            'a broken link inside one report' => [static function (): array {
                $lines = self::log([self::install(), ['action' => 'prune']]);
                $tampered = json_decode($lines[1], true);
                $tampered['prev_sha256'] = str_repeat('1', 64);

                return [[$lines[0], (string) json_encode($tampered)]];
            }, 'does not chain to the entry before it'],
            'a seq re-sent with different bytes' => [static fn (): array => [self::log([self::install()]), self::log([self::install('0.92.0')])], 'arrived again with different content'],
        ];
    }

    /**
     * @param  callable(): list<list<string>>  $reports
     */
    #[DataProvider('discontinuities')]
    public function test_a_broken_chain_is_stored_and_marked(callable $reports, string $reason): void
    {
        $last = null;
        foreach ($reports() as $entries) {
            $last = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => $entries]);
            $this->assertSame(200, $last['status'], 'a discontinuity is stored, not refused');
        }

        $this->assertTrue($last['body']['discontinuity']);
        $row = SeatClientState::query()->where('agent', 'httpseat')->firstOrFail();
        $this->assertTrue($row->log_discontinuity);
        $this->assertStringContainsString($reason, (string) $row->log_discontinuity_reason);
    }

    public function test_a_new_install_id_is_a_logged_rebootstrap_that_starts_a_fresh_chain(): void
    {
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => self::log([self::install()], 5)]);
        $this->assertTrue(SeatClientState::query()->where('agent', 'httpseat')->value('log_discontinuity'));

        $fresh = '6f1c2d3e-0000-4000-8000-000000000002';
        $r = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => $fresh, 'entries' => self::log([['action' => 'bootstrap', 'actor' => 'provision', 'to_bridge_release' => '0.91.0']], 1, null, $fresh)]);

        $this->assertFalse($r['body']['discontinuity']);
        $this->assertSame($fresh, $r['body']['log_head']['install_id']);
        $event = SeatClientEvent::query()->where('agent', 'httpseat')->where('install_id', SeatClientLedger::BRIDGE_INSTALL_ID)->firstOrFail();
        $this->assertSame('rebootstrap', $event->action);
        $this->assertStringContainsString(self::INSTALL, (string) $event->reason);
    }

    /** A seat whose root was restored to an install id this bridge already holds resumes that log. */
    public function test_returning_to_an_earlier_install_id_resumes_its_stored_log(): void
    {
        $other = '6f1c2d3e-0000-4000-8000-000000000003';
        $first = self::log([self::install()]);
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => $first]);
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => $other, 'entries' => self::log([['action' => 'bootstrap', 'actor' => 'provision']], 1, null, $other)]);

        $back = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => array_merge($first, self::log([['action' => 'prune']], 2, hash('sha256', $first[0])))]);

        $this->assertSame(200, $back['status']);
        $this->assertSame(1, $back['body']['stored'], 'seq 1 is recognised as held, seq 2 is new');
        $this->assertFalse($back['body']['discontinuity']);
        $this->assertSame(2, $back['body']['log_head']['seq']);
    }

    /** The largest report the contract allows fits the smallest door, whatever its lines escape to. */
    public function test_a_maximum_size_report_fits_the_ssh_door(): void
    {
        // Lines as large as allowed, padded with `"` — each becomes `\"` in the line and `\\\"` once the
        // line rides as a JSON string: the worst case for the report body's size.
        $budget = SeatClientLedger::MAX_REPORT_BYTES;
        $count = (int) ceil($budget / InstallLogEntry::MAX_LINE_BYTES);
        $fields = [];
        for ($i = 0; $i < $count; $i++) {
            $fields[] = ['action' => 'skip', 'pad' => ''];
        }
        // Grow each line's unknown `pad` key until the lines total exactly the budget.
        $install = str_repeat('a', 64);   // the longest install id, so the envelope is at its largest
        $base = array_sum(array_map('strlen', self::log($fields, 1, null, $install)));
        $room = $budget - $base;
        foreach ($fields as $i => $f) {
            $share = intdiv($room, $count) + ($i < $room % $count ? 1 : 0);
            $fields[$i]['pad'] = str_repeat('"', intdiv($share, 2)).($share % 2 === 1 ? 'x' : '');
        }
        $lines = self::log($fields, 1, null, $install);
        $this->assertSame($budget, array_sum(array_map('strlen', $lines)));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(InstallLogEntry::MAX_LINE_BYTES, strlen($line));
        }
        $body = ['op' => 'client_report', 'install_id' => $install, 'entries' => $lines];
        $this->assertLessThanOrEqual(ToolsCallStdio::MAX_STDIN_BYTES, strlen((string) json_encode($body)));

        $r = $this->ssh($body);

        $this->assertSame(0, $r['exit'], $r['raw']);
        $this->assertSame($count, $r['body']['stored']);
    }

    /** An open format: an unknown key is accepted, ignored, and kept in the bytes the chain hashes. */
    public function test_an_unknown_key_is_accepted_and_kept_in_the_hashed_line(): void
    {
        $lines = self::log([self::install() + ['future_field' => ['nested' => 1]], ['action' => 'prune']]);

        $r = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => $lines]);

        $this->assertSame(200, $r['status'], $r['raw']);
        $this->assertFalse($r['body']['discontinuity']);
        $held = SeatClientEvent::query()->where('agent', 'httpseat')->where('seq', 1)->firstOrFail();
        $this->assertSame(hash('sha256', $lines[0]), $held->line_sha256);
        $this->assertStringContainsString('future_field', $lines[0]);
    }

    /** The open format holds at any depth: a deeply nested unknown key is accepted and hashed as sent. */
    public function test_a_deeply_nested_unknown_key_is_accepted_and_kept_in_the_hashed_line(): void
    {
        $lines = self::log([self::install() + ['future' => ['a' => ['b' => ['c' => ['d' => [1]]]]]]]);

        $r = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => $lines]);

        $this->assertSame(200, $r['status'], $r['raw']);
        $this->assertSame(hash('sha256', $lines[0]), SeatClientEvent::query()->where('agent', 'httpseat')->where('seq', 1)->value('line_sha256'));
    }

    /** A late line filling a gap already marked is stored, and moves neither the head nor the row. */
    public function test_a_late_line_filling_a_gap_is_stored_not_flagged_as_changed(): void
    {
        $lines = self::log([self::install(), ['action' => 'prune'], ['action' => 'prune']]);
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => [$lines[0], $lines[2]]]);
        $row = SeatClientState::query()->where('agent', 'httpseat')->firstOrFail();
        $this->assertStringContainsString('seq 2–2 of install', (string) $row->log_discontinuity_reason);

        $late = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => [$lines[1]]]);

        $this->assertSame(1, $late['body']['stored']);
        $this->assertSame(3, $late['body']['log_head']['seq']);
        $row->refresh();
        $this->assertStringContainsString('seq 2–2 of install', (string) $row->log_discontinuity_reason, 'the first reason stands; the late line is not "different content"');
        $this->assertSame(3, SeatClientEvent::query()->where('agent', 'httpseat')->count());
    }

    /** Only a genuinely new install clears a broken-log mark; returning to a held one does not. */
    /** The mark is re-derived for a resumed install: A's gap survives a clean install B in between. */
    public function test_resuming_a_broken_install_after_a_clean_one_reads_broken(): void
    {
        $other = '6f1c2d3e-0000-4000-8000-000000000006';
        $a = self::log([self::install(), ['action' => 'prune'], ['action' => 'prune']]);
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => [$a[0], $a[2]]]);
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => $other, 'entries' => self::log([['action' => 'bootstrap', 'actor' => 'provision']], 1, null, $other)]);
        $this->assertFalse(SeatClientState::query()->where('agent', 'httpseat')->value('log_discontinuity'), 'B is clean');

        $back = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => self::log([['action' => 'prune']], 4, hash('sha256', $a[2]))]);

        $this->assertTrue($back['body']['discontinuity']);
        $this->assertStringContainsString('seq 2–2 of install '.self::INSTALL.' never arrived', (string) SeatClientState::query()->where('agent', 'httpseat')->value('log_discontinuity_reason'));
    }

    /** …and a resumed install whose stored chain is whole reads clean again. */
    public function test_resuming_a_whole_install_after_a_broken_one_reads_clean(): void
    {
        $other = '6f1c2d3e-0000-4000-8000-000000000007';
        $a = self::log([self::install(), ['action' => 'prune']]);
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => [$a[0]]]);
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => $other, 'entries' => self::log([['action' => 'bootstrap', 'actor' => 'provision']], 3, null, $other)]);
        $this->assertTrue(SeatClientState::query()->where('agent', 'httpseat')->value('log_discontinuity'), 'B broke');

        $back = $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => [$a[1]]]);

        $this->assertFalse($back['body']['discontinuity']);
    }

    /**
     * Both doors refuse a malformed agent YAML before any op runs, so the door's own config read is
     * reached only if the YAMLs change between the two reads — and then it names the config, not
     * the ledger. Driven on the door directly for that reason.
     */
    public function test_client_fleet_names_a_config_fault_as_a_config_fault(): void
    {
        $pm = AgentConfig::fromArray('sshpm', (array) Yaml::parseFile($this->dir.'/sshpm.yml'))->boardTools;
        $this->assertNotNull($pm);
        File::put($this->dir.'/broken.yml', "identity: [unterminated\n");

        $outcome = $this->app->make(ClientUpdateDoor::class)->handle(['op' => 'client_fleet'], 'sshpm', $pm);

        $this->assertSame(503, $outcome->status);
        $this->assertSame('this bridge could not load its agent configs, so which seats make up the fleet is unknown (see the bridge log)', $outcome->body['error']);
    }

    public function test_a_failed_launch_is_recorded_with_its_reason(): void
    {
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => self::log([
            ['action' => 'fail', 'result' => 'failed', 'reason' => 'bridge unreachable', 'launch_id' => 'L9'],
            ['action' => 'prune', 'result' => 'ok', 'launch_id' => 'L9'],
        ])]);

        $row = SeatClientState::query()->where('agent', 'httpseat')->firstOrFail();
        $this->assertSame('failed', $row->last_launch_result, 'a later ok line of the same launch does not clear its failure');
        $this->assertSame('bridge unreachable', $row->last_launch_error);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function reportRefusals(): array
    {
        $ok = self::log([self::install()]);
        $line = static fn (array $over): string => (string) json_encode(array_merge(json_decode($ok[0], true), $over));

        return [
            'no install_id' => [['entries' => $ok], 'needs `install_id`'],
            'an install_id with a space' => [['install_id' => 'a b', 'entries' => $ok], 'needs `install_id`'],
            'the bridge\'s own install id' => [['install_id' => 'bridge', 'entries' => $ok], 'needs `install_id`'],
            'no entries' => [['install_id' => self::INSTALL], 'needs `entries`'],
            'empty entries' => [['install_id' => self::INSTALL, 'entries' => []], 'needs `entries`'],
            'entries as an object' => [['install_id' => self::INSTALL, 'entries' => ['a' => $ok[0]]], 'needs `entries`'],
            // Counted before any line is read, so the lines need not parse (and stay under the ssh door's stdin cap).
            'too many entries' => [['install_id' => self::INSTALL, 'entries' => array_fill(0, SeatClientLedger::MAX_REPORT_ENTRIES + 1, 'x')], 'at most '.SeatClientLedger::MAX_REPORT_ENTRIES.' entries AND at most '.SeatClientLedger::MAX_REPORT_BYTES.' bytes of lines — split the backlog by both'],
            'too many bytes' => [['install_id' => self::INSTALL, 'entries' => array_fill(0, intdiv(SeatClientLedger::MAX_REPORT_BYTES, 4000) + 1, str_repeat('x', 4000))], 'bytes of lines — split the backlog by both'],
            'the bridge\'s own install id in capitals' => [['install_id' => 'BRIDGE', 'entries' => $ok], 'needs `install_id`'],
            'a launch line with no launch_id' => [['install_id' => self::INSTALL, 'entries' => [$line(['launch_id' => null])]], 'an `actor: launch` line with no `launch_id`'],
            'a malformed manifest_sha256' => [['install_id' => self::INSTALL, 'entries' => [$line(['manifest_sha256' => 'nothex'])]], 'malformed `manifest_sha256`'],
            'a line that is not a string' => [['install_id' => self::INSTALL, 'entries' => [['seq' => 1]]], 'entry 0 is not a non-empty string'],
            'a line that is not JSON' => [['install_id' => self::INSTALL, 'entries' => ['{nope']], 'entry 0 is not valid JSON'],
            'a line that is a JSON list' => [['install_id' => self::INSTALL, 'entries' => ['[1,2]']], 'entry 0 is not a JSON object'],
            'a line too long' => [['install_id' => self::INSTALL, 'entries' => [$line(['reason' => str_repeat('x', 5000)])]], 'longer than 4096 bytes'],
            'a line of another install' => [['install_id' => self::INSTALL, 'entries' => [$line(['install_id' => 'other'])]], 'different install_id'],
            'a line with no seq' => [['install_id' => self::INSTALL, 'entries' => [$line(['seq' => null])]], 'no positive integer `seq`'],
            'an unknown action' => [['install_id' => self::INSTALL, 'entries' => [$line(['action' => 'explode'])]], 'no `action` among'],
            'an unknown result' => [['install_id' => self::INSTALL, 'entries' => [$line(['result' => 'meh'])]], 'no `result` among'],
            'an unknown actor' => [['install_id' => self::INSTALL, 'entries' => [$line(['actor' => 'someone'])]], 'no `actor` among'],
            'a v-prefixed release' => [['install_id' => self::INSTALL, 'entries' => [$line(['to_bridge_release' => 'v0.91.0'])]], 'malformed `to_bridge_release`'],
            'a malformed digest' => [['install_id' => self::INSTALL, 'entries' => [$line(['files_json_sha256' => 'abc'])]], 'malformed `files_json_sha256`'],
            'a malformed client_version' => [['install_id' => self::INSTALL, 'entries' => [$line(['client_version' => "0.9.28\n"])]], 'malformed `client_version`'],
            'a non-string reason' => [['install_id' => self::INSTALL, 'entries' => [$line(['reason' => 7])]], 'a `reason` that is not a string'],
            'lines out of order' => [['install_id' => self::INSTALL, 'entries' => [$line(['seq' => 2]), $line(['seq' => 1])]], 'entries go oldest first'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    #[DataProvider('reportRefusals')]
    public function test_a_malformed_report_is_refused_on_both_doors_and_stores_nothing(array $fields, string $says): void
    {
        $body = ['op' => 'client_report'] + $fields;
        $http = $this->http('/agent-tools/client', $body);
        $ssh = $this->ssh($body);

        $this->assertSame(422, $http['status']);
        $this->assertSame(1, $ssh['exit']);
        $this->assertSame($http['raw'], $ssh['raw']);
        $this->assertFalse($http['body']['ok']);
        $this->assertStringContainsString($says, (string) $http['body']['error']);
        $this->assertSame(0, SeatClientEvent::query()->count());
        $this->assertSame(0, SeatClientState::query()->count());
    }

    // ───────────────────────── approval ─────────────────────────

    public function test_an_approval_seat_is_offered_nothing_until_the_published_content_is_approved(): void
    {
        $this->publish(new ClientPackFixture);

        $owed = $this->ssh(['op' => 'client_manifest'], 'gated')['body'];
        $this->assertNull($owed['offer']);
        $this->assertSame(['required' => true, 'owed' => '0.91.0'], $owed['approval']);

        $this->artisan('bridge:client-approve', ['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => 'reviewed the diff'])->assertExitCode(0);

        $offered = $this->ssh(['op' => 'client_manifest'], 'gated')['body'];
        $this->assertSame('0.91.0', $offered['offer']);
        $this->assertSame(['required' => true, 'owed' => null], $offered['approval']);
    }

    public function test_a_seat_that_requires_no_approval_is_always_offered(): void
    {
        $this->publish(new ClientPackFixture);

        $body = $this->ssh(['op' => 'client_manifest'])['body'];
        $this->assertSame('0.91.0', $body['offer']);
        $this->assertSame(['required' => false, 'owed' => null], $body['approval']);
    }

    /** Operator ruling 6: approval gates the offer; the pack is served to any authenticated seat. */
    public function test_the_pack_is_served_to_an_unapproved_seat(): void
    {
        $this->publish(new ClientPackFixture);

        $this->assertSame(0, $this->ssh(['op' => 'client_pack', 'bridge_release' => '0.91.0'], 'gated')['exit']);
    }

    /** Operator ruling, comment 6774: approval is of CONTENT, so an unchanged client re-owes none. */
    public function test_an_approval_covers_a_later_release_with_the_same_content_and_not_one_with_new_content(): void
    {
        $this->publish(new ClientPackFixture);
        $this->artisan('bridge:client-approve', ['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => 'ok'])->assertExitCode(0);

        $this->publish(new ClientPackFixture('0.92.0'));
        $this->assertSame('0.92.0', $this->ssh(['op' => 'client_manifest'], 'gated')['body']['offer'], 'same files_json_sha256 ⇒ already approved');

        $this->publish(new ClientPackFixture('0.93.0', '0.9.29', 'other bytes', ['files_json_sha256' => str_repeat('d', 64)]));
        $body = $this->ssh(['op' => 'client_manifest'], 'gated')['body'];
        $this->assertNull($body['offer']);
        $this->assertSame('0.93.0', $body['approval']['owed']);
    }

    public function test_client_approve_records_one_logged_event_and_is_idempotent(): void
    {
        $this->publish(new ClientPackFixture);

        $this->artisan('bridge:client-approve', ['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => 'reviewed'])
            ->expectsOutputToContain('bridge:client-approve: approved release 0.91.0\'s client pack (client 0.9.28, content bbbbbbbbbbbb) for gated — event 1, by ')
            ->assertExitCode(0);
        $this->artisan('bridge:client-approve', ['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => 'again'])
            ->expectsOutputToContain('already has an approval of this content — event 1')
            ->assertExitCode(0);

        $this->assertSame(0, Artisan::call('bridge:client-approve', ['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => 'a third']));
        $events = SeatClientEvent::query()->where('agent', 'gated')->get();
        $this->assertCount(1, $events);
        $this->assertSame('approve', $events[0]->action);
        $this->assertSame(SeatClientLedger::BRIDGE_INSTALL_ID, $events[0]->install_id);
        $this->assertSame(str_repeat('b', 64), $events[0]->files_json_sha256);
        $this->assertSame('reviewed', $events[0]->reason);
        $this->assertNotSame('', (string) $events[0]->actor);
    }

    public function test_client_approve_says_the_door_now_offers_the_pack(): void
    {
        $this->publish(new ClientPackFixture);

        Artisan::call('bridge:client-approve', ['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => 'ok']);

        $this->assertStringEndsWith('. The update door now offers it to that seat.', trim(Artisan::output()));
    }

    /** A database it cannot write is "could not read": exit 2, as the command documents. */
    public function test_client_approve_exits_2_when_the_ledger_cannot_be_written(): void
    {
        $this->publish(new ClientPackFixture);
        $this->withADeadDatabase(function (): void {
            $this->artisan('bridge:client-approve', ['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => 'x'])
                ->expectsOutputToContain('database unreachable')
                ->assertExitCode(2)
                ->run();
        });
    }

    public function test_client_approve_notes_an_agent_that_requires_no_approval(): void
    {
        $this->publish(new ClientPackFixture);

        $this->artisan('bridge:client-approve', ['agent' => 'sshseat', 'bridge_release' => '0.91.0', '--reason' => 'x'])
            ->expectsOutputToContain('sshseat does not require approval (board_tools.client_update.approval_required is not true), so the door already offered it and this approval gates nothing today; it is recorded all the same.')
            ->doesntExpectOutputToContain('now offers it')
            ->assertExitCode(0);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: bool, 2: int, 3: string}>
     */
    public static function approveRefusals(): array
    {
        return [
            'no reason' => [['agent' => 'gated', 'bridge_release' => '0.91.0'], true, 1, '--reason is required'],
            'a blank reason' => [['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => '   '], true, 1, '--reason is required'],
            'a v-prefixed release' => [['agent' => 'gated', 'bridge_release' => 'v0.91.0', '--reason' => 'x'], true, 1, 'must be bare X.Y.Z'],
            'an unknown agent' => [['agent' => 'nobody', 'bridge_release' => '0.91.0', '--reason' => 'x'], true, 1, 'nobody is not an agent with an enabled board_tools block'],
            'nothing published' => [['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => 'x'], false, 1, 'publishes no client pack yet'],
            'a reason longer than the record holds' => [['agent' => 'gated', 'bridge_release' => '0.91.0', '--reason' => str_repeat('r', SeatClientEvent::REASON_MAX_CHARS + 1)], true, 1, 'the approval record holds at most '.SeatClientEvent::REASON_MAX_CHARS],
            'a release that is not the published one' => [['agent' => 'gated', 'bridge_release' => '0.90.0', '--reason' => 'x'], true, 1, 'publishes release 0.91.0, not 0.90.0'],
        ];
    }

    /**
     * @param  array<string, string>  $args
     */
    #[DataProvider('approveRefusals')]
    public function test_client_approve_refuses_and_records_nothing(array $args, bool $publish, int $exit, string $says): void
    {
        if ($publish) {
            $this->publish(new ClientPackFixture);
        }

        $this->artisan('bridge:client-approve', $args)->expectsOutputToContain($says)->assertExitCode($exit);
        $this->assertSame(0, SeatClientEvent::query()->count());
    }

    // ───────────────────────── client_fleet ─────────────────────────

    public function test_client_fleet_needs_a_seat_token_on_the_http_door(): void
    {
        $this->assertSame(401, $this->http('/agent-tools/client', ['op' => 'client_fleet'], '')['status']);
        $this->assertSame(401, $this->http('/agent-tools/client', ['op' => 'client_fleet'], 'not-a-bearer')['status']);
    }

    public function test_client_fleet_is_refused_to_a_seat_without_fleet_view_on_both_doors(): void
    {
        $http = $this->http('/agent-tools/client', ['op' => 'client_fleet']);
        $ssh = $this->ssh(['op' => 'client_fleet']);

        $this->assertSame(403, $http['status']);
        $this->assertSame(1, $ssh['exit']);
        $this->assertSame($http['raw'], $ssh['raw']);
    }

    public function test_client_fleet_serves_the_pms_seat_the_same_fleet_on_both_doors(): void
    {
        $this->publish(new ClientPackFixture);
        Carbon::setTestNow('2026-09-28T12:00:00Z');
        $this->http('/agent-tools/client', ['op' => 'client_report', 'install_id' => self::INSTALL, 'entries' => self::log([self::install()])]);

        $http = $this->http('/agent-tools/client', ['op' => 'client_fleet'], $this->pmBearer);
        $ssh = $this->ssh(['op' => 'client_fleet'], 'sshpm');

        $this->assertSame(200, $http['status']);
        $this->assertSame(0, $ssh['exit']);
        $this->assertSame($http['raw'], $ssh['raw']);
        $fleet = $http['body'];
        $this->assertSame('0.91.0', $fleet['published']['bridge_release']);
        $byAgent = array_column($fleet['seats'], null, 'agent');
        $this->assertSame(['gated', 'httppm', 'httpseat', 'sshpm', 'sshseat'], array_keys($byAgent));
        $this->assertSame('applies_next_launch', $byAgent['httpseat']['state'], 'installed through the door, no launch has called yet');
        $this->assertSame('0.91.0', $byAgent['httpseat']['installed']['bridge_release']);
        $this->assertSame('approval_owed', $byAgent['gated']['state']);
        $this->assertSame('needs_bootstrap', $byAgent['sshseat']['state']);
        Carbon::setTestNow();
    }

    // ───────────────────────── caller / launch on a tool call ─────────────────────────

    /**
     * r2 M-3: a probe or self-certification carries no version and no launch; it must not overwrite
     * what the seat's own call recorded. The call is refused as an unknown tool so no board is read —
     * the ledger is stamped at the dispatcher's ENTRY, whatever the tool then answers.
     */
    public function test_an_exempt_call_stamps_only_its_own_column_on_both_doors(): void
    {
        $this->http('/agent-tools/call', ['tool' => 'no_such_tool', 'client_version' => '0.9.28', 'launch' => ['id' => 'L7', 'bridge_release' => '0.91.0']]);
        $this->ssh(['tool' => 'no_such_tool', 'client_version' => '0.9.28', 'launch' => ['id' => 'L8', 'bridge_release' => '0.91.0']]);
        $before = SeatClientState::query()->orderBy('agent')->get()->map(fn (SeatClientState $r) => $r->only(['agent', 'running_bridge_release', 'running_client_version', 'running_launch_id', 'last_call_client_version', 'last_call_launch_id']))->all();

        foreach (['probe', 'self-cert', 'operator'] as $caller) {
            $this->http('/agent-tools/call', ['tool' => 'no_such_tool', 'caller' => $caller]);
            $this->ssh(['tool' => 'no_such_tool', 'caller' => $caller]);
        }

        $after = SeatClientState::query()->orderBy('agent')->get();
        $this->assertSame($before, $after->map(fn (SeatClientState $r) => $r->only(['agent', 'running_bridge_release', 'running_client_version', 'running_launch_id', 'last_call_client_version', 'last_call_launch_id']))->all());
        foreach ($after as $row) {
            $this->assertSame('operator', $row->last_exempt_caller, $row->agent.' '.json_encode($row->toArray()));
            $this->assertNotNull($row->last_exempt_call_at);
        }
    }

    public function test_a_seat_call_without_a_launch_writes_the_last_call_and_never_the_running_fields(): void
    {
        $this->ssh(['tool' => 'no_such_tool', 'client_version' => '0.9.27']);

        $row = SeatClientState::query()->where('agent', 'sshseat')->firstOrFail();
        $this->assertSame('0.9.27', $row->last_call_client_version);
        $this->assertNull($row->last_call_launch_id);
        $this->assertNull($row->running_bridge_release);
        $this->assertNull($row->running_launch_id);
    }

    public function test_a_launch_is_first_seen_once_and_a_new_launch_moves_it(): void
    {
        Carbon::setTestNow('2026-09-28T10:00:00Z');
        $this->ssh(['tool' => 'no_such_tool', 'client_version' => '0.9.28', 'launch' => ['id' => 'L1', 'bridge_release' => '0.91.0']]);
        Carbon::setTestNow('2026-09-28T11:00:00Z');
        $this->ssh(['tool' => 'no_such_tool', 'client_version' => '0.9.28', 'launch' => ['id' => 'L1', 'bridge_release' => '0.91.0']]);

        $row = SeatClientState::query()->where('agent', 'sshseat')->firstOrFail();
        $this->assertSame('2026-09-28T10:00:00Z', $row->running_launch_first_seen_at?->toIso8601ZuluString());
        $this->assertSame('2026-09-28T11:00:00Z', $row->running_seen_at?->toIso8601ZuluString());

        Carbon::setTestNow('2026-09-28T12:00:00Z');
        $this->ssh(['tool' => 'no_such_tool', 'client_version' => '0.9.29', 'launch' => ['id' => 'L2', 'bridge_release' => '0.92.0']]);
        $row->refresh();
        $this->assertSame('L2', $row->running_launch_id);
        $this->assertSame('0.92.0', $row->running_bridge_release);
        $this->assertSame('0.9.29', $row->running_client_version);
        $this->assertSame('2026-09-28T12:00:00Z', $row->running_launch_first_seen_at?->toIso8601ZuluString());
        Carbon::setTestNow();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedObservations(): array
    {
        return [
            'an unknown caller' => [['caller' => 'root']],
            'a non-string caller' => [['caller' => ['probe']]],
            'a launch with no release' => [['launch' => ['id' => 'L1']]],
            'a launch with a v-prefixed release' => [['launch' => ['id' => 'L1', 'bridge_release' => 'v0.91.0']]],
            'a launch id with a newline' => [['launch' => ['id' => "L1\n", 'bridge_release' => '0.91.0']]],
            'a launch that is a string' => [['launch' => 'L1']],
        ];
    }

    /**
     * Neither key can refuse a call: a malformed one reads as absent, and the call answers exactly
     * what it answers without it.
     *
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('malformedObservations')]
    public function test_a_malformed_caller_or_launch_refuses_nothing_and_is_read_as_absent(array $extra): void
    {
        $plain = $this->ssh(['tool' => 'no_such_tool', 'client_version' => '0.9.27']);
        $with = $this->ssh(['tool' => 'no_such_tool', 'client_version' => '0.9.27'] + $extra);

        $this->assertSame($plain['raw'], $with['raw']);
        $this->assertSame($plain['exit'], $with['exit']);
        $row = SeatClientState::query()->where('agent', 'sshseat')->firstOrFail();
        $this->assertNull($row->running_launch_id);
        $this->assertNull($row->last_exempt_caller);
        $this->assertSame('0.9.27', $row->last_call_client_version);
    }
}
