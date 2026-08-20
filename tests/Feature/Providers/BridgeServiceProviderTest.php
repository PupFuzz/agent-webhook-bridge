<?php

namespace Tests\Feature\Providers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use Illuminate\Support\Facades\File;
use ReflectionProperty;
use Tests\TestCase;

class BridgeServiceProviderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // DispatchService's bind closure builds config-derived registries from
        // this dir and parses every *.yml it finds, so the dir must be one this
        // test owns: the shared temp root is world-writable and a co-tenant's
        // stray YAML would be parsed as an agent config.
        $this->dir = sys_get_temp_dir().'/bridgesp-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_handler_registry_is_a_container_singleton(): void
    {
        $this->assertSame(
            $this->app->make(HandlerRegistry::class),
            $this->app->make(HandlerRegistry::class),
        );
    }

    public function test_after_resolving_registration_reaches_the_dispatchers_registry(): void
    {
        $spy = new class implements Handler
        {
            public function handle(ReactionTarget $target, AgentConfig $agent): void {}
        };

        // The documented extension path (docs/customization.md): register a
        // custom handler against the singleton via afterResolving in a provider.
        $this->app->afterResolving(
            HandlerRegistry::class,
            fn (HandlerRegistry $registry) => $registry->register('sync_board', $spy),
        );

        // Visible on the instance the container hands out...
        $registry = $this->app->make(HandlerRegistry::class);
        $this->assertSame($spy, $registry->resolve('sync_board'));

        // ...and it is the SAME instance the dispatch loop receives. The bug
        // this guards against: DispatchService was built with a private `new
        // HandlerRegistry` the container could never reach, so afterResolving
        // never saw the dispatcher's registry.
        $dispatcher = $this->app->make(DispatchService::class);
        $injected = (new ReflectionProperty($dispatcher, 'handlers'))->getValue($dispatcher);
        $this->assertSame($registry, $injected);
        $this->assertSame($spy, $injected->resolve('sync_board'));
    }
}
