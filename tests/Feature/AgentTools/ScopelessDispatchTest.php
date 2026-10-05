<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\SelfScopedTool;
use App\Bridge\Tools\ToolsCallStdio;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Models\CiAwait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\CallingSeatSeal;
use Tests\Support\FakeToolsCallStdio;
use Tests\TestCase;

/**
 * card#11283 / DL-461 — a SCOPE-LESS agent (an explicit `board_tools` block with no board scope)
 * through both real front doors and the client-update door.
 *
 * ⛔ NO WRITEBACK TOKEN IS PLACED, on purpose. A board tool reaching the writeback factory would
 * answer 503; one reaching the board would be a stray request, which `Tests\TestCase`'s
 * `Http::preventStrayRequests()` turns into a failure. So a `not_served` answer here is proof
 * that the refusal came BEFORE either.
 */
class ScopelessDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private string $dir;

    private string $token = 'ci-only-bearer';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/scopeless-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/ci-only-token', $this->token);
        chmod($this->dir.'/ci-only-token', 0o600);
        // An http scope-less agent (bearer), and an ssh scope-less agent (forced command).
        File::put($this->dir.'/ci-only.yml', "subscriptions:\n  - provider: github\n    scopes: [octo/widgets]\n"
            ."board_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$this->dir}/ci-only-token\n");
        File::put($this->dir.'/ci-ssh.yml', "subscriptions: []\nboard_tools:\n  enabled: true\n  transport: ssh\n");
        File::put($this->dir.'/ci-off.yml', "subscriptions: []\nboard_tools:\n  enabled: true\n  transport: ssh\n  ci_tools: false\n");
        File::put($this->dir.'/disabled.yml', "subscriptions: []\nboard_tools:\n  enabled: false\n");
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

    /** @param array<string, mixed> $body */
    private function http(string $path, array $body): TestResponse
    {
        CallingSeatSeal::forANewServingProcess();

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode($body));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{exit: int, body: array<string, mixed>}
     */
    private function ssh(string $agent, array $body): array
    {
        CallingSeatSeal::forANewServingProcess();
        $fake = new FakeToolsCallStdio((string) json_encode($body));
        $this->app->instance(ToolsCallStdio::class, $fake);
        $exit = $this->artisan('bridge:tools-call', ['--agent' => $agent])->run();

        return ['exit' => $exit, 'body' => (array) json_decode($fake->capturedOut(), true)];
    }

    /** @return list<string> */
    private static function boardToolNames(): array
    {
        $registry = new BoardToolsRegistry;

        return array_values(array_filter($registry->known(), static fn (string $n): bool => ! $registry->resolve($n) instanceof SelfScopedTool));
    }

    public function test_the_writeback_factory_really_is_unavailable_here(): void
    {
        $this->expectException(ConfigException::class);

        WritebackClientFactory::make();
    }

    public function test_every_board_tool_is_refused_not_served_over_http_before_any_client_or_board_request(): void
    {
        Http::fake();
        $names = self::boardToolNames();
        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $this->http('/agent-tools/call', ['tool' => $name, 'args' => []])
                ->assertStatus(422)
                ->assertJsonPath('reason', 'not_served');
        }
        Http::assertNothingSent();
    }

    public function test_every_board_tool_is_refused_not_served_over_ssh_with_exit_1(): void
    {
        Http::fake();
        foreach (self::boardToolNames() as $name) {
            $r = $this->ssh('ci-ssh', ['tool' => $name, 'args' => []]);

            $this->assertSame(1, $r['exit'], $name);
            $this->assertSame('not_served', $r['body']['reason'] ?? null, $name);
            $this->assertStringContainsString('no board scope', (string) ($r['body']['error'] ?? ''), $name);
        }
        Http::assertNothingSent();
    }

    public function test_an_unknown_tool_is_still_unknown_not_unserved(): void
    {
        $this->http('/agent-tools/call', ['tool' => 'no_such_tool', 'args' => []])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unknown_tool');
    }

    /** SF-2 / T2: a self-scoped tool runs with no writeback token on the install. */
    public function test_a_ci_tool_runs_for_a_scope_less_agent_with_no_writeback_token(): void
    {
        CiAwait::query()->create(['agent' => 'other', 'repo' => 'octo/widgets', 'repo_name' => 'octo/widgets', 'head_sha' => self::SHA, 'expires_at' => now()->addHour()]);

        $this->http('/agent-tools/call', ['tool' => 'ci_await_cancel', 'args' => ['repo' => 'octo/widgets', 'head_sha' => self::SHA]])
            ->assertStatus(200)
            ->assertJsonPath('result.cancelled', false);
        $this->assertSame(1, CiAwait::query()->count(), 'another seat\'s await is untouched');
    }

    public function test_an_agent_that_opted_out_is_refused_the_ci_tools(): void
    {
        $r = $this->ssh('ci-off', ['tool' => 'ci_await_cancel', 'args' => ['repo' => 'octo/widgets', 'head_sha' => self::SHA]]);

        $this->assertSame(1, $r['exit']);
        $this->assertSame('not_served', $r['body']['reason'] ?? null);
        $this->assertStringContainsString('opts out of the CI tools', (string) ($r['body']['error'] ?? ''));
    }

    public function test_served_tools_answers_what_the_dispatcher_serves_with_the_identity_echo(): void
    {
        $this->http('/agent-tools/client', ['op' => 'served_tools'])
            ->assertStatus(200)
            ->assertExactJson(['ok' => true, 'op' => 'served_tools', 'agent' => 'ci-only', 'served' => ['ci_await', 'ci_await_cancel']]);

        $r = $this->ssh('ci-off', ['op' => 'served_tools']);
        $this->assertSame(0, $r['exit']);
        $this->assertSame(['ok' => true, 'op' => 'served_tools', 'agent' => 'ci-off', 'served' => []], $r['body']);
    }

    /** SF-6: a closed ssh door names itself, so a seat can tell it from a fault. */
    public function test_a_closed_ssh_door_answers_door_closed(): void
    {
        foreach (['disabled', 'ci-only'] as $agent) {   // disabled; and an http agent over ssh
            $r = $this->ssh($agent, ['op' => 'served_tools']);

            $this->assertSame(2, $r['exit'], $agent);
            $this->assertSame('door_closed', $r['body']['reason'] ?? null, $agent);
        }
    }
}
