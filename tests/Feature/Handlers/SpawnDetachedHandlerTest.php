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

    private string $touch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/spawn-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->touch = $this->resolveProgram('touch');
        config([
            'bridge.config_dir' => $this->dir,
            // DL-011: cmd[0] must be an allowlisted absolute path.
            'bridge.spawn.allowlist' => [$this->touch],
        ]);
    }

    private function resolveProgram(string $name): string
    {
        foreach (['/usr/bin/', '/bin/'] as $prefix) {
            if (is_file($prefix.$name)) {
                return $prefix.$name;
            }
        }
        $this->markTestSkipped("{$name} not found in /usr/bin or /bin");
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

    public function test_program_not_in_allowlist_throws(): void
    {
        // A relative name, or any path not in the allowlist, is rejected even
        // though `touch` (absolute) IS allowed — the program is trusted by the
        // allowlist, never by source (DL-011).
        $this->expectException(HandlerException::class);
        $this->spawn(['cmd' => ['touch', $this->dir.'/x']]);   // 'touch' != '/usr/bin/touch'
    }

    public function test_unresolvable_setsid_fails_closed(): void
    {
        // setsid is resolved to an ABSOLUTE path (so a payload env PATH can't
        // redirect which setsid runs — the launcher-execs-cmd allowlist bypass).
        // A configured path that doesn't exist must fail closed, not fall back to
        // a PATH-resolved bare `setsid`.
        config(['bridge.spawn.setsid_path' => '/nonexistent/setsid']);

        $this->expectException(HandlerException::class);
        $this->expectExceptionMessage('setsid not found');
        $this->spawn(['cmd' => [$this->touch, $this->dir.'/x']]);
    }

    public function test_valid_cmd_executes_detached(): void
    {
        $marker = $this->dir.'/ran.marker';
        $this->spawn(['cmd' => [$this->touch, $marker]]);

        // Fire-and-forget; poll briefly for the detached child to land.
        $deadline = microtime(true) + 5.0;
        while (! file_exists($marker) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($marker);
        $this->assertFileExists($this->dir.'/state/spawn-job1.log');   // default log path
    }

    public function test_argv_has_no_shell_injection_surface(): void
    {
        // The metacharacters are a single argv element to `touch`, NOT a shell
        // command. proc_open execs the argv array directly (no /bin/sh), so the
        // `; touch EVIL` is a literal filename, never a second command.
        $evil = $this->dir.'/EVIL.marker';
        $weirdName = $this->dir.'/legit; touch '.$evil;

        // The operand is a PATH, and every `/` in it — including the ones inside
        // the appended $evil — is a directory separator to `touch`. Without its
        // parent it dies ENOENT and creates nothing, leaving the absence below
        // with nothing to distinguish "did not execute" from "did not finish".
        File::ensureDirectoryExists(dirname($weirdName));

        $this->spawn(['cmd' => [$this->touch, $weirdName]]);

        // Ordering, not a clock, is what makes the negative below sound: a fixed
        // sleep scores a slow-but-successful injection as "did not execute". The
        // witness is the operand landing VERBATIM — `touch` with one operand does
        // exactly one thing, so that file existing IS the child having finished,
        // and its name IS the proof the argv element never met a shell (a shell
        // would have made ".../legit" and ".../EVIL.marker" instead, and this
        // poll would time out rather than pass).
        $deadline = microtime(true) + 5.0;
        while (! file_exists($weirdName) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($weirdName);    // argv passed whole; child finished
        $this->assertFileDoesNotExist($evil);   // injection did NOT execute
    }
}
