<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Bridge\InboxCommand;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BridgeCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cli-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeAgent(): void
    {
        File::put($this->dir.'/agents.json', (string) json_encode(['agents' => [['name' => 'prod-agent', 'kanban_user_id' => 137]]]));
        File::put($this->dir.'/prod-agent.yml', "identity:\n  self: prod-agent\n"
            ."api:\n  kanban:\n    base_url: https://k.example.com\n    token_path: /t\n"
            ."receiver:\n  base_url: https://b.example.com/webhooks\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
    }

    private function event(): WebhookEvent
    {
        return WebhookEvent::create([
            'delivery_id' => 'evt-1', 'provider' => 'kanban', 'scope_id' => '5',
            'event_type' => 'task.created', 'actor_id' => '999',
            'payload' => ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'Ship it']],
        ]);
    }

    public function test_check_passes_with_valid_config(): void
    {
        $this->writeAgent();
        $this->artisan('bridge:check')->assertExitCode(0);
    }

    public function test_check_fails_without_secret_dir(): void
    {
        config(['bridge.secret_dir' => null]);
        $this->artisan('bridge:check')->assertExitCode(1);
    }

    public function test_stats_reports_counts(): void
    {
        $event = $this->event();
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'a', 'processed_at' => now()]);
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'b', 'error_message' => 'boom']);

        $this->artisan('bridge:stats')->assertExitCode(0);
    }

    public function test_inspect_shows_event_or_fails(): void
    {
        $event = $this->event();
        $this->artisan('bridge:inspect', ['id' => $event->id])
            ->expectsOutputToContain('evt-1')
            ->assertExitCode(0);

        $this->artisan('bridge:inspect', ['id' => 99999])->assertExitCode(1);
    }

    public function test_replay_reprocesses_an_errored_dispatch(): void
    {
        $this->writeAgent();
        $event = $this->event();
        AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'prod-agent', 'error_message' => 'old failure',
        ]);

        $this->artisan('bridge:replay', ['id' => $event->id])->assertExitCode(0);

        $dispatch = AgentDispatch::where('agent_name', 'prod-agent')->firstOrFail();
        $this->assertNotNull($dispatch->processed_at);   // re-ran and succeeded
        $this->assertNull($dispatch->error_message);
    }

    public function test_replay_force_reruns_succeeded_dispatch(): void
    {
        $this->writeAgent();
        $event = $this->event();
        $done = AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'prod-agent', 'processed_at' => now()->subDay(),
        ]);
        $originalProcessedAt = $done->processed_at;

        $this->artisan('bridge:replay', ['id' => $event->id, '--force' => true])->assertExitCode(0);

        // --force cleared processed_at, so it re-ran and got a fresh timestamp.
        $this->assertTrue($done->fresh()->processed_at->greaterThan($originalProcessedAt));
    }

    public function test_inbox_surfaces_unseen_then_is_silent(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'evt-1:prod-agent:0', 'ts' => 1.0, 'kind' => 'new_card', 'summary' => 'card 42'])."\n");

        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->expectsOutputToContain('new_card')
            ->assertExitCode(0);

        // Seen advanced → second run surfaces nothing (no new output).
        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->doesntExpectOutput()
            ->assertExitCode(0);
    }

    public function test_inbox_build_output_envelope_logic(): void
    {
        $cmd = new InboxCommand;
        $lines = [['id' => 'x', 'kind' => 'new_card', 'summary' => 'hi']];

        $this->assertStringNotContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'plain', 'SessionStart'));

        $wrapped = $cmd->buildOutput($lines, 'claude-code', 'PreToolUse');
        $this->assertStringContainsString('"hookSpecificOutput"', $wrapped);
        $this->assertStringContainsString('"hookEventName":"PreToolUse"', $wrapped);

        // auto: wrap only for additionalContext-supporting events.
        $this->assertStringContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'auto', 'SessionStart'));
        $this->assertStringNotContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'auto', 'Stop'));
        $this->assertStringNotContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'auto', null));
    }
}
