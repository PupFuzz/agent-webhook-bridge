<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\LogIntentHandler;
use App\Bridge\Handlers\RegistryAppendHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FileHandlersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/handlers-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function agent(): AgentConfig
    {
        return AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 137],
            'subscriptions' => [],
        ]);
    }

    public function test_log_intent_appends_to_handler_log(): void
    {
        (new LogIntentHandler)->handle(
            ReactionTarget::make('log_intent', '42', payload: ['k' => 'v']),
            $this->agent(),
        );

        $line = trim(File::get($this->dir.'/state/handler-log.jsonl'));
        $row = json_decode($line, true);
        $this->assertSame('prod-agent', $row['agent']);
        $this->assertSame('42', $row['target_id']);
        $this->assertSame(['k' => 'v'], $row['payload']);
    }

    public function test_registry_append_sanitizes_target_id_in_filename(): void
    {
        (new RegistryAppendHandler)->handle(
            ReactionTarget::make('registry_append', 'org/repo#1', payload: []),
            $this->agent(),
        );

        // '/' and '#' become '_' so the target id can't escape the state dir.
        $this->assertFileExists($this->dir.'/state/registry-org_repo_1.jsonl');
        $this->assertFileDoesNotExist($this->dir.'/state/registry-org/repo#1.jsonl');
    }

    public function test_registry_append_strips_dots_from_target_id(): void
    {
        (new RegistryAppendHandler)->handle(
            ReactionTarget::make('registry_append', 'v1.2.3', payload: []),
            $this->agent(),
        );

        // Post-consolidation the shared sanitizer strips dots (no '.'/'..'
        // component can ever form), so the ledger key is 'v1_2_3', not 'v1.2.3'.
        $this->assertFileExists($this->dir.'/state/registry-v1_2_3.jsonl');
        $this->assertFileDoesNotExist($this->dir.'/state/registry-v1.2.3.jsonl');
    }
}
