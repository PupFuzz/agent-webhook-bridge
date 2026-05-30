<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\HandlerException;
use App\Bridge\Handlers\SpawnDetachedHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpawnDetachedHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/spawn-'.uniqid();
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

    private function spawn(array $payload): void
    {
        (new SpawnDetachedHandler)->handle(
            ReactionTarget::make('spawn_detached', 'job1', payload: $payload),
            $this->agent(),
        );
    }

    public function test_non_list_cmd_throws(): void
    {
        $this->expectException(HandlerException::class);
        $this->spawn(['cmd' => 'echo hi']);   // string, not list
    }

    public function test_empty_cmd_throws(): void
    {
        $this->expectException(HandlerException::class);
        $this->spawn(['cmd' => []]);
    }

    public function test_non_string_cmd_entry_throws(): void
    {
        $this->expectException(HandlerException::class);
        $this->spawn(['cmd' => ['echo', 123]]);
    }

    public function test_valid_cmd_executes_detached(): void
    {
        $marker = $this->dir.'/ran.marker';
        $this->spawn(['cmd' => ['touch', $marker]]);

        // Fire-and-forget; poll briefly for the detached child to land.
        $deadline = microtime(true) + 5.0;
        while (! file_exists($marker) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($marker);
        $this->assertFileExists($this->dir.'/state/spawn-job1.log');   // default log path
    }

    public function test_argv_is_escaped_no_shell_injection(): void
    {
        // The metacharacters are a single argv element to `touch`, NOT a shell
        // command — escapeshellarg must prevent the `; touch EVIL` from running.
        $evil = $this->dir.'/EVIL.marker';
        $weirdName = $this->dir.'/legit; touch '.$evil;
        $this->spawn(['cmd' => ['touch', $weirdName]]);

        usleep(500_000);
        $this->assertFileDoesNotExist($evil);   // injection did NOT execute
    }
}
