<?php

namespace App\Providers;

use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the synchronous dispatch pipeline.
 *
 * HandlerRegistry is a container singleton so an operator can register custom
 * handlers against the exact instance the dispatcher uses —
 * afterResolving(HandlerRegistry::class, fn ($r) => $r->register('x', new XHandler))
 * in a ServiceProvider (see docs/customization.md). It carries no per-request
 * state (the four shipped handlers are stateless), so a per-process instance is
 * correct and saves rebuilding them each request.
 *
 * DispatchService is bound (not a singleton) because its other registries are
 * built per request from the current config('bridge.config_dir') — the
 * per-agent YAMLs + agents.json are read fresh each request (FPM-worker caching
 * is a future optimisation).
 */
class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HandlerRegistry::class);

        $this->app->bind(DispatchService::class, function (): DispatchService {
            $configDir = (string) config('bridge.config_dir');

            return new DispatchService(
                new SubscriptionRegistry($configDir),
                AgentRegistry::load(rtrim($configDir, '/').'/agents.json'),
                $this->app->make(HandlerRegistry::class),
                new IntentLog,
            );
        });
    }
}
