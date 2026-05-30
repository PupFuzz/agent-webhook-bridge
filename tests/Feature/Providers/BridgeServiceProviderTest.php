<?php

namespace Tests\Feature\Providers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use ReflectionProperty;
use Tests\TestCase;

class BridgeServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // DispatchService's bind closure builds config-derived registries from
        // this dir; point it at an existing one so resolution never depends on
        // a real install being present.
        config(['bridge.config_dir' => sys_get_temp_dir()]);
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
