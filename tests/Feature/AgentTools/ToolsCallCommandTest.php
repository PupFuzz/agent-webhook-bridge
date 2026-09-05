<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\ServingProcessEnvironment;
use App\Bridge\Tools\ToolsCallStdio;
use App\Models\BoardToolsClientCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeServingProcessEnvironment;
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
    // card#7756: a SUCCESSFUL dispatch now writes one durable row (ClientHalfLedger), so
    // this class touches the database transitively where it did not before. Without the
    // trait those rows COMMIT on the MariaDB legs and outlive the test — invisible on
    // SQLite `:memory:`, where the insert finds no table and the ledger swallows it. The
    // isolation guard in Tests\TestCase names both halves.
    use RefreshDatabase;

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
     * ⚑ THE SERVING PROCESS IS A BOUND FIXTURE, NEVER THE AMBIENT ONE (card#7836). The
     * command MEASURES its process since DL-316, and one of the three facts it reads — the
     * controlling terminal — cannot be manufactured by a suite at all: an arm that let the
     * real one through would be green or red by accident of how the developer launched
     * phpunit. `$process` binds {@see ServingProcessEnvironment} for the
     * run; passing none leaves the container default, which no arm below relies on for a
     * provenance assertion.
     *
     * ⚑ THE ENVIRONMENT IS STILL SAVED AND RESTORED for the arms that seed
     * `SSH_ORIGINAL_COMMAND`: the prior value is put back so one test cannot decide what the
     * next one measures.
     *
     * @param  array<string, string|null>  $server  null ⇒ unset for the duration of the run
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCommand(?string $agent, string $stdin, array $server = [], ?ServingProcessEnvironment $process = null): array
    {
        $saved = [];
        foreach ($server as $k => $v) {
            $saved[$k] = getenv($k);
            $v === null ? putenv($k) : putenv("{$k}={$v}");
        }
        if ($process !== null) {
            $this->app->instance(ServingProcessEnvironment::class, $process);
        }
        $fake = new FakeToolsCallStdio($stdin);
        $this->app->instance(ToolsCallStdio::class, $fake);

        $params = $agent === null ? [] : ['--agent' => $agent];
        $exit = $this->artisan('bridge:tools-call', $params)->run();

        foreach ($saved as $k => $v) {
            is_string($v) ? putenv("{$k}={$v}") : putenv($k);
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
        // The window is EMPTY, so no row was read and the board is UNOBSERVED — the
        // envelope says so rather than restating the configured board as a reading
        // (DL-302). The configured scope is still carried, under its own name.
        $this->assertNull($decoded['result']['board_id']);
        $this->assertFalse($decoded['result']['board_observed']);
        $this->assertSame(10, $decoded['result']['configured_board_id']);
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

    // ─── client-half provenance (card#7836 / DL-316) ──────────────────────────

    /**
     * ⭐ THIS DOOR IS THE ONLY ONE THAT CAN TELL A PINNED FORCED COMMAND FROM A HAND-RUN, and
     * the arms here are the whole discrimination: the SAME command and the SAME request over
     * different serving processes, producing different stored provenances. Any arm alone
     * passes against a writer that stamped a constant.
     *
     * This is the pinned forced command's shape — sshd's session environment, no controlling
     * terminal, no pty marker. ⚑ Why that triple IS the forced command's shape, and which
     * half of it is measured rather than inferred, is owned by
     * {@see CallProvenance} and is not restated here.
     */
    public function test_a_pty_less_forced_command_process_stamps_sshd_provenance(): void
    {
        $this->fakeHealthyBoard();

        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']), [], new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: false, ptyMarker: false));

        $this->assertSame(0, $r['exit']);
        $this->assertSame(CallProvenance::Sshd, BoardToolsClientCall::query()->where('agent', 'me')->sole()->call_provenance);
    }

    /**
     * ⭐ THE REGRESSION ARM, END TO END THROUGH THE REAL DOOR. A hand-run in a TMUX PANE
     * attached over ssh carries `SSH_CONNECTION` and NOT `SSH_TTY` — tmux's
     * `update-environment` default carries the first and not the second — so the predicate
     * this replaced stamped it `sshd` and `bridge:check` printed its most confident line over
     * an operator's own hand-run. The pane has a CONTROLLING TERMINAL, and a pipe on stdin
     * does not take it away; that is what rejects it here.
     */
    public function test_a_hand_run_in_a_tmux_pane_over_ssh_stamps_not_sshd(): void
    {
        $this->fakeHealthyBoard();

        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']), [], new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: true, ptyMarker: false));

        $this->assertSame(0, $r['exit']);
        $this->assertSame(CallProvenance::NotSshd, BoardToolsClientCall::query()->where('agent', 'me')->sole()->call_provenance);
    }

    /**
     * An operator hand-running the command in their own interactive ssh shell — the case the
     * FIRST predicate was written for, still refused, now on two independent terms.
     */
    public function test_a_hand_run_inside_an_operator_ssh_shell_stamps_not_sshd(): void
    {
        $this->fakeHealthyBoard();

        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']), [], new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: true, ptyMarker: true));

        $this->assertSame(0, $r['exit']);
        $this->assertSame(CallProvenance::NotSshd, BoardToolsClientCall::query()->where('agent', 'me')->sole()->call_provenance);
    }

    /**
     * ⛔ THE VALUE IS NOT DISCLOSED ANYWHERE THE CALL CAN REACH. The row is printed verbatim
     * by `bridge:check` and this door's stdout is relayed to the caller, so all three
     * surfaces are checked rather than just the column. The real environment is seeded too,
     * so the string this asserts about is genuinely present in the process while it runs.
     */
    public function test_no_ssh_connection_value_reaches_the_row_or_the_envelope(): void
    {
        $this->fakeHealthyBoard();

        $r = $this->runCommand('me', (string) json_encode(['tool' => 'board_my_cards']), [
            'SSH_CONNECTION' => '203.0.113.9 53210 198.51.100.4 22',
        ], new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: false, ptyMarker: false));

        $row = BoardToolsClientCall::query()->where('agent', 'me')->sole();
        // Non-vacuous: the provenance really was measured on this run.
        $this->assertSame(CallProvenance::Sshd, $row->call_provenance);
        foreach ([(string) json_encode($row->getAttributes()), $r['stdout'], $r['stderr']] as $surface) {
            $this->assertStringNotContainsString('203.0.113.9', $surface);
            $this->assertStringNotContainsString('53210', $surface);
        }
    }

    private function fakeHealthyBoard(): void
    {
        $this->writeSshAgent();
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1]]],
            ]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);
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

    // ─── the cross-door contract (DL-326 Decision 7) ─────────────────────────

    /**
     * ⭐ THIS DOOR IS THE ONLY PLACE THE CLEAR-A-DESCRIPTION RULE CAN BE MEASURED. Laravel's
     * global `TrimStrings` + `ConvertEmptyStringsToNull` run on the HTTP door and NOT here —
     * `bridge:tools-call` json_decodes the body itself — so `"   "` arrives at the tool as
     * three spaces here and as `null` there. An HTTP-door test of this case is VACUOUS: it
     * passes against a tool that trims and against one that does not, because the middleware
     * has already collapsed the value before the tool sees it.
     *
     * The claim being pinned is that a call MEANS the same thing on both transports: a
     * whitespace-only description CLEARS the field, rather than writing whitespace on one
     * door and clearing on the other. `""` is the same rule from the other side and is
     * asserted with it (this door preserves an empty string where the HTTP door nulls it).
     *
     * ⚑ RED-WHEN-REVERTED: drop the `trim()` in `textCorrections()` and the first leg
     * writes `"   "`.
     */
    public function test_a_whitespace_only_description_clears_on_the_ssh_door_as_it_does_on_http(): void
    {
        foreach (['   ', ''] as $sent) {
            $this->writeSshAgent();
            Http::fake(function ($request) {
                if (str_contains($request->url(), '/tasks/search.json')) {
                    return Http::response(['data' => [[
                        'id' => 42, 'board_id' => 10, 'swimlane_id' => 4, 'tags' => ['created-by:me'],
                    ]]]);
                }

                return Http::response(['data' => ['id' => 42]]);
            });

            $r = $this->runCommand('me', (string) json_encode([
                'tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'description' => $sent],
            ]));

            $this->assertSame(0, $r['exit'], 'stdout: '.$r['stdout']);
            $body = null;
            Http::recorded(function ($request) use (&$body) {
                if ($request->method() === 'PATCH') {
                    $body = json_decode((string) $request->body(), true);
                }

                return false;
            });
            $this->assertSame(['description' => ''], $body, "a description of '{$sent}' must CLEAR the field on this door too");
        }
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
