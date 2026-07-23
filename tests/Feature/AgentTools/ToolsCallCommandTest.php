<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\ToolsCallStdio;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `bridge:tools-call` — the SSH-forced-command board-tools front door (card 4952).
 * These assert the transport-native contract the ssh channel relies on: identity
 * from the trusted `--agent` (never SSH_ORIGINAL_COMMAND), the STDIN request shape,
 * the exit-code class mapping (0 ok / 1 caller-fixable / 2 bridge-side fault), and
 * — load-bearing — that fd 1 carries NOTHING but the one JSON envelope even when the
 * command emits an internal diagnostic.
 */
class ToolsCallCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-call-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture

        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeSecret(string $path, string $value): void
    {
        File::put($path, $value);
        chmod($path, 0o600);
    }

    /** An ssh-transport agent (no bearer — identity is the forced-command --agent). */
    private function writeSshAgent(string $name = 'me', int $board = 10, int $swimlane = 4, int $stage = 55): void
    {
        File::put($this->dir."/{$name}.yml", "identity:\n  kanban_user_id: ".crc32($name)."\nsubscriptions: []\n"
            ."board_tools:\n  transport: ssh\n  board_id: {$board}\n  swimlane_id: {$swimlane}\n  create_stage_id: {$stage}\n");
    }

    /**
     * Run the command with a seeded STDIN and capture the real streams via a fake
     * bound into the container (method injection resolves it).
     *
     * @param  array<string, string>  $server
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCommand(?string $agent, string $stdin, array $server = []): array
    {
        foreach ($server as $k => $v) {
            putenv("{$k}={$v}");
        }
        $fake = new FakeToolsCallStdio($stdin);
        $this->app->instance(ToolsCallStdio::class, $fake);

        $params = $agent === null ? [] : ['--agent' => $agent];
        $exit = $this->artisan('bridge:tools-call', $params)->run();

        foreach (array_keys($server) as $k) {
            putenv($k);
        }

        return ['exit' => $exit, 'stdout' => $fake->capturedOut(), 'stderr' => $fake->capturedErr()];
    }

    // ─── happy path ──────────────────────────────────────────────────────────

    public function test_happy_path_returns_clean_json_and_exit_0(): void
    {
        $this->writeSshAgent();
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1]]],
            ]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']));

        $this->assertSame(0, $r['exit']);
        $decoded = json_decode($r['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('board_my_cards', $decoded['tool']);
        $this->assertSame(10, $decoded['result']['board_id']);
        $this->assertSame(4, $decoded['result']['swimlane_id']);
    }

    // ─── stdout purity (load-bearing) ─────────────────────────────────────────

    public function test_internal_diagnostic_does_not_pollute_stdout(): void
    {
        // Force the config-error path (malformed agent YAML) — it writes a diagnostic
        // to STDERR — and assert stdout is EXACTLY one JSON object, no leading/trailing
        // non-JSON bytes.
        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: [ : broken");
        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']));

        $this->assertSame(2, $r['exit']);
        // Byte-clean: the whole of stdout is valid JSON, nothing else.
        $this->assertSame($r['stdout'], trim($r['stdout']), 'stdout has leading/trailing whitespace');
        $decoded = json_decode($r['stdout'], true);
        $this->assertIsArray($decoded, 'stdout is not pure JSON: '.$r['stdout']);
        $this->assertFalse($decoded['ok']);
        // The diagnostic (its distinct stderr marker + the YAML parse detail) went to
        // STDERR, and NONE of it leaked onto fd 1 — stdout is the envelope alone.
        $this->assertStringContainsString('[bridge:tools-call]', $r['stderr']);
        $this->assertStringNotContainsString('[bridge:tools-call]', $r['stdout']);
    }

    // ─── identity / SSH_ORIGINAL_COMMAND ──────────────────────────────────────

    public function test_ssh_original_command_junk_is_ignored(): void
    {
        $this->writeSshAgent();
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']), [
            'SSH_ORIGINAL_COMMAND' => 'rm -rf / ; board_delete_everything',
        ]);

        // Behavior is identical to the no-env happy path — the client command is inert.
        $this->assertSame(0, $r['exit']);
        $this->assertTrue(json_decode($r['stdout'], true)['ok']);
    }

    // ─── exit-code class mapping ──────────────────────────────────────────────

    public function test_missing_agent_option_is_exit_1(): void
    {
        $r = $this->runCommand(null, (string) json_encode(['tool' => 'board_my_cards']));
        $this->assertSame(1, $r['exit']);
        $this->assertFalse(json_decode($r['stdout'], true)['ok']);
    }

    public function test_unknown_agent_is_exit_2(): void
    {
        $r = $this->runCommand('ghost', (string) json_encode(['tool' => 'board_my_cards']));
        $this->assertSame(2, $r['exit']);
    }

    public function test_http_agent_over_ssh_door_is_exit_2(): void
    {
        // An http-transport agent is NOT ssh-invocable (bridge-side config fault).
        $channelTokenFile = $this->dir.'/me-channel-token';
        $this->writeSecret($channelTokenFile, 'chan-value');   // gitleaks:allow — test fixture
        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."channel:\n  url: http://127.0.0.1:8788\n  auth:\n    token_path: {$channelTokenFile}\n"
            ."board_tools:\n  transport: http\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");

        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']));
        $this->assertSame(2, $r['exit']);
    }

    public function test_disabled_agent_over_ssh_door_is_exit_2(): void
    {
        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."board_tools:\n  enabled: false\n");
        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']));
        $this->assertSame(2, $r['exit']);
    }

    public function test_malformed_stdin_is_exit_1(): void
    {
        $this->writeSshAgent();
        $r = $this->runCommand('me', 'this is not json{');
        $this->assertSame(1, $r['exit']);
        $this->assertFalse(json_decode($r['stdout'], true)['ok']);
    }

    public function test_missing_tool_in_stdin_is_exit_1(): void
    {
        $this->writeSshAgent();
        $r = $this->runCommand('me', (string) json_encode(['args' => []]));
        $this->assertSame(1, $r['exit']);
    }

    public function test_oversize_stdin_is_exit_1(): void
    {
        $this->writeSshAgent();
        $big = str_repeat('a', 70000);   // > 64 KiB cap
        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards', 'args' => ['x' => $big]]));
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('cap', json_decode($r['stdout'], true)['error']);
    }

    public function test_upstream_error_maps_to_exit_2(): void
    {
        // A kanban 5xx surfaces as a 502-class DispatchOutcome → exit 2 (service fault).
        $this->writeSshAgent();
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*/tasks/search.json*' => Http::response('upstream boom', 500),
        ]);

        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']));
        $this->assertSame(2, $r['exit']);
        $this->assertFalse(json_decode($r['stdout'], true)['ok']);
    }

    public function test_tool_refusal_maps_to_exit_1(): void
    {
        // A reserved caller tag is a 422-class refusal → exit 1 (caller-fixable).
        $this->writeSshAgent();
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_create_card', 'args' => ['title' => 't', 'tags' => ['triaged']]]));
        $this->assertSame(1, $r['exit']);
        $this->assertFalse(json_decode($r['stdout'], true)['ok']);
        Http::assertNothingSent();
    }
}

/**
 * Captures the three streams for the in-process command test — the seam that lets a
 * test read the REAL fd-1 bytes the command wrote, which is what the ssh channel
 * returns to the caller.
 */
class FakeToolsCallStdio extends ToolsCallStdio
{
    /** @var resource */
    private $inStream;

    /** @var resource */
    private $outStream;

    /** @var resource */
    private $errStream;

    public function __construct(string $stdin)
    {
        $this->inStream = fopen('php://memory', 'r+');
        fwrite($this->inStream, $stdin);
        rewind($this->inStream);
        $this->outStream = fopen('php://memory', 'r+');
        $this->errStream = fopen('php://memory', 'r+');
    }

    public function in()
    {
        return $this->inStream;
    }

    public function out()
    {
        return $this->outStream;
    }

    public function err()
    {
        return $this->errStream;
    }

    public function capturedOut(): string
    {
        rewind($this->outStream);

        return (string) stream_get_contents($this->outStream);
    }

    public function capturedErr(): string
    {
        rewind($this->errStream);

        return (string) stream_get_contents($this->errStream);
    }
}
