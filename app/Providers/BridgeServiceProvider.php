<?php

namespace App\Providers;

use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the synchronous dispatch pipeline. DispatchService is bound (not a
 * singleton) so its registries are built per request from the current
 * config('bridge.config_dir') — the per-agent YAMLs + agents.json are read
 * fresh each request (FPM-worker caching is a future optimisation).
 */
class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DispatchService::class, function (): DispatchService {
            $configDir = (string) config('bridge.config_dir');

            return new DispatchService(
                new SubscriptionRegistry($configDir),
                AgentRegistry::load(rtrim($configDir, '/').'/agents.json'),
                new HandlerRegistry,
                new IntentLog,
            );
        });
    }
}
