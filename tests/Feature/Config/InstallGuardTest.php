<?php

namespace Tests\Feature\Config;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\InstallGuard;
use App\Bridge\Support\SubscriptionRegistry;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/installguard-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function setDb(string $name): void
    {
        config(['database.default' => 'guardtest', 'database.connections.guardtest.database' => $name]);
    }

    public function test_no_suffix_skips_the_check(): void
    {
        config(['bridge.install_suffix' => '']);
        $this->setDb('anything');
        $this->assertNull(InstallGuard::dsnCrosstalk());
    }

    public function test_matching_prod_db_passes(): void
    {
        config(['bridge.install_suffix' => '-prod']);
        $this->setDb('agent_webhook_bridge_prod');
        $this->assertNull(InstallGuard::dsnCrosstalk());
    }

    public function test_matching_dev_db_passes(): void
    {
        config(['bridge.install_suffix' => '-dev']);
        $this->setDb('agent_webhook_bridge_dev');
        $this->assertNull(InstallGuard::dsnCrosstalk());
    }

    public function test_prod_suffix_with_non_prod_db_is_flagged(): void
    {
        config(['bridge.install_suffix' => '-prod']);
        $this->setDb('agent_webhook_bridge_dev');   // crosstalk!
        $msg = InstallGuard::dsnCrosstalk();
        $this->assertNotNull($msg);
        $this->assertStringContainsString('crosstalk', (string) $msg);
    }

    public function test_bridge_check_fails_on_crosstalk(): void
    {
        config(['bridge.install_suffix' => '-prod']);   // real sqlite db is :memory:, lacks _prod
        $this->artisan('bridge:check')->assertExitCode(1);
    }

    public function test_dispatch_fails_closed_on_crosstalk(): void
    {
        config(['bridge.install_suffix' => '-prod']);   // :memory: lacks _prod → crosstalk

        $dispatcher = new DispatchService(
            // SubscriptionRegistry parses every *.yml under this dir, so it must
            // be one this test owns: a stray YAML in the shared temp root throws
            // the same ConfigException the crosstalk guard does, which would
            // satisfy the assertion below without the guard having fired.
            new SubscriptionRegistry($this->dir),
            new AgentRegistry([]),
            new HandlerRegistry,
            new IntentLog,
        );

        // The guard throws BEFORE any DB write → propagates → 5xx (fail-closed).
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('crosstalk');
        $dispatcher->dispatch('kanban', '5', new EventDto('d1', '5', 'task.created', null), []);
    }
}
