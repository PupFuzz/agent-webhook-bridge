<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\Tools\ToolsCallStdio;
use App\Models\BoardToolsClientCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\Support\ClientPackFixture;
use Tests\Support\FakeToolsCallStdio;
use Tests\TestCase;

/**
 * The client-update door (DL-430), through BOTH transports: `POST /agent-tools/client` and
 * `bridge:tools-call` given a body carrying `op`. Each op is asserted on each door, and the two
 * doors are held to the same body bytes.
 */
class ClientUpdateDoorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $bearer = 'http-seat-bearer-xyz';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/client-update-door-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        $this->secret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->secret($this->dir.'/httpseat-tools-token', $this->bearer);
        File::put($this->dir.'/httpseat.yml', "identity:\n  kanban_user_id: 11\nsubscriptions: []\nboard_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$this->dir}/httpseat-tools-token\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");
        File::put($this->dir.'/sshseat.yml', "identity:\n  kanban_user_id: 12\nsubscriptions: []\nboard_tools:\n  transport: ssh\n  board_id: 10\n  swimlane_id: 5\n  create_stage_id: 55\n");
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
     * @param  array<string, string>  $server
     * @return array{status: int, raw: string}
     */
    private function http(array $body, ?string $bearer = null, array $server = []): array
    {
        $server += ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        $bearer ??= $this->bearer;
        if ($bearer !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $response = $this->call('POST', '/agent-tools/client', [], [], [], $server, (string) json_encode($body));

        return ['status' => $response->getStatusCode(), 'raw' => (string) $response->getContent()];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{exit: int, raw: string}
     */
    private function ssh(array $body, string $agent = 'sshseat'): array
    {
        CallingSeatSeal::forANewServingProcess();
        $io = new FakeToolsCallStdio((string) json_encode($body));
        $this->app->instance(ToolsCallStdio::class, $io);
        $exit = $this->artisan('bridge:tools-call', ['--agent' => $agent])->run();

        return ['exit' => $exit, 'raw' => $io->capturedOut()];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(string $raw): array
    {
        $d = json_decode($raw, true);
        self::assertIsArray($d);

        return $d;
    }

    public function test_client_manifest_serves_the_published_manifest_on_both_doors(): void
    {
        $f = new ClientPackFixture;
        $this->publish($f);

        $http = $this->http(['op' => 'client_manifest']);
        $ssh = $this->ssh(['op' => 'client_manifest']);

        $this->assertSame(200, $http['status']);
        $this->assertSame(0, $ssh['exit']);
        $this->assertSame($http['raw'], $ssh['raw']);
        $body = self::decoded($http['raw']);
        $this->assertTrue($body['ok']);
        $this->assertSame('client_manifest', $body['op']);
        $this->assertSame('0.91.0', $body['offer']);
        $this->assertSame('0.91.0', $body['published']['bridge_release']);
        $this->assertSame('0.9.28', $body['published']['client_version']);
        $this->assertSame($f->manifest, base64_decode($body['published']['manifest_b64'], true));
        $this->assertSame(hash('sha256', $f->manifest), $body['published']['manifest_sha256']);
    }

    public function test_client_pack_serves_the_published_bytes_on_both_doors(): void
    {
        $f = new ClientPackFixture;
        $this->publish($f);

        $http = $this->http(['op' => 'client_pack', 'bridge_release' => '0.91.0']);
        $ssh = $this->ssh(['op' => 'client_pack', 'bridge_release' => '0.91.0']);

        $this->assertSame(200, $http['status']);
        $this->assertSame(0, $ssh['exit']);
        $this->assertSame($http['raw'], $ssh['raw']);
        $body = self::decoded($http['raw']);
        $this->assertSame('base64', $body['encoding']);
        $this->assertSame($f->pack, base64_decode($body['data'], true));
        $this->assertSame(hash('sha256', $f->pack), $body['sha256']);
        $this->assertSame(strlen($f->pack), $body['size']);
    }

    /**
     * @param  array<string, mixed>  $request
     */
    #[DataProvider('refusals')]
    public function test_each_refusal_is_the_same_on_both_doors(bool $publish, array $request, int $status, int $exit, string $says, ?string $tamper = null): void
    {
        if ($publish) {
            $f = new ClientPackFixture;
            $this->publish($f);
            if ($tamper === 'record') {
                file_put_contents($this->dir.'/state/client-packs/published.json', '{"bridge_release": "0.91.0"}');
            } elseif ($tamper !== null) {
                file_put_contents($this->dir.'/state/client-packs/0.91.0/'.($tamper === 'pack' ? $f->packName() : $f->manifestName()), 'tampered after publication');
            }
        }

        $http = $this->http($request);
        $ssh = $this->ssh($request);

        $this->assertSame($status, $http['status']);
        $this->assertSame($exit, $ssh['exit']);
        $this->assertSame($http['raw'], $ssh['raw']);
        $body = self::decoded($http['raw']);
        $this->assertFalse($body['ok']);
        $this->assertStringContainsString($says, $body['error']);
        $this->assertStringNotContainsString($this->dir, $http['raw'], 'a store fault names no path to a seat');
    }

    /**
     * @return array<string, array{0: bool, 1: array<string, mixed>, 2: int, 3: int, 4: string, 5?: string}>
     */
    public static function refusals(): array
    {
        return [
            'nothing published, manifest' => [false, ['op' => 'client_manifest'], 503, 2, 'publishes no client pack yet'],
            'nothing published, pack' => [false, ['op' => 'client_pack', 'bridge_release' => '0.91.0'], 503, 2, 'publishes no client pack yet'],
            'another release' => [true, ['op' => 'client_pack', 'bridge_release' => '0.90.0'], 404, 1, 'for release 0.91.0 only, not 0.90.0'],
            'no release named' => [true, ['op' => 'client_pack'], 422, 1, 'needs `bridge_release`'],
            'a v-prefixed release' => [true, ['op' => 'client_pack', 'bridge_release' => 'v0.91.0'], 422, 1, 'bare X.Y.Z'],
            'unknown op' => [true, ['op' => 'client_bogus'], 422, 1, 'serves client_manifest, client_pack, client_report, client_fleet'],
            // card#10567 B4: the fleet is the PM's view; neither fixture agent has `fleet_view`.
            'client_fleet without fleet_view' => [true, ['op' => 'client_fleet'], 403, 1, 'only to an agent whose board_tools.fleet_view is true'],
            'non-string op' => [true, ['op' => 7], 422, 1, 'unknown client-update `op` 7'],
            // Every stored file is re-checked against published.json before it is served.
            'stored pack altered after publication' => [true, ['op' => 'client_pack', 'bridge_release' => '0.91.0'], 503, 2, 'cannot be served', 'pack'],
            'stored manifest altered after publication' => [true, ['op' => 'client_manifest'], 503, 2, 'cannot be served', 'manifest'],
            'publication record malformed, manifest' => [true, ['op' => 'client_manifest'], 503, 2, 'cannot be served', 'record'],
            'publication record malformed, pack' => [true, ['op' => 'client_pack', 'bridge_release' => '0.91.0'], 503, 2, 'cannot be served', 'record'],
        ];
    }

    /**
     * The update path never runs through the board-tools dispatcher: no board request is made and
     * no client-half row is stamped, whatever a tool call would do.
     */
    public function test_an_op_request_never_reaches_the_board_tools_dispatcher(): void
    {
        $this->publish(new ClientPackFixture);

        $this->http(['op' => 'client_manifest', 'tool' => 'board_my_cards']);
        $this->ssh(['op' => 'client_manifest', 'tool' => 'board_my_cards']);

        Http::assertNothingSent();
        $this->assertSame(0, BoardToolsClientCall::query()->count());
    }

    public function test_the_http_door_needs_the_agents_bearer(): void
    {
        $this->publish(new ClientPackFixture);

        $this->assertSame(401, $this->http(['op' => 'client_manifest'], '')['status']);
        $this->assertSame(401, $this->http(['op' => 'client_manifest'], 'not-a-known-bearer')['status']);
    }

    public function test_the_http_door_is_loopback_only(): void
    {
        $this->publish(new ClientPackFixture);

        $this->assertSame(403, $this->http(['op' => 'client_manifest'], null, ['REMOTE_ADDR' => '203.0.113.9'])['status']);
    }

    /**
     * The route names ITS body shape, {op, …}, not the board-tools {tool, …} one.
     */
    public function test_the_http_door_refuses_a_body_that_is_not_json_naming_the_op_shape(): void
    {
        $response = $this->call('POST', '/agent-tools/client', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearer, 'CONTENT_TYPE' => 'application/json',
        ], '{"op": "client_manifest"');

        $this->assertSame(422, $response->getStatusCode());
        $error = (string) $response->json('error');
        $this->assertStringContainsString('not valid JSON', $error);
        $this->assertStringEndsWith('expected a JSON object {op, …}', $error);
        $this->assertStringNotContainsString('{tool', $error);
    }

    public function test_the_http_door_refuses_a_body_that_is_not_declared_json(): void
    {
        $response = $this->call('POST', '/agent-tools/client', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearer, 'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], 'op=client_manifest');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('request Content-Type must be application/json — the body is read as a JSON object {op, …}', $response->json('error'));
    }

    /**
     * The ssh door's own agent guard still stands in front of the op branch: an HTTP-transport
     * agent named as the forced command's `--agent` is refused before any op is read.
     */
    public function test_the_ssh_door_serves_only_a_live_ssh_agent(): void
    {
        $this->publish(new ClientPackFixture);

        $r = $this->ssh(['op' => 'client_manifest'], 'httpseat');

        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('is not a live ssh board-tools agent', $r['raw']);
    }
}
