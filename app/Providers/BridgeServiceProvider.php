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
 * state (the shipped handlers are stateless), so a per-process instance is
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
        // spawn_detached is opt-in (DL-011): register it only when the install
        // explicitly enables it. Singleton closure so config is read once per
        // process, matching the registry's per-process-singleton lifetime (DL-004).
        $this->app->singleton(
            HandlerRegistry::class,
            fn (): HandlerRegistry => new HandlerRegistry((bool) config('bridge.spawn.enabled')),
        );

        $this->app->bind(DispatchService::class, function (): DispatchService {
            $configDir = (string) config('bridge.config_dir');
            // The identity registry is built from the SAME scanned YAMLs the
            // subscription registry reads (each agent declares its own identity
            // ids) — one source of truth, fail-closed: a malformed YAML throws
            // here too (→ 5xx → upstream redelivers once fixed), not a silent
            // degrade. shared-identities.json is the only separate file.
            $subscriptions = new SubscriptionRegistry($configDir);

            return new DispatchService(
                $subscriptions,
                AgentRegistry::fromAgentConfigs(
                    $subscriptions->agentConfigs(),
                    AgentRegistry::loadSharedIdentities($configDir),
                ),
                $this->app->make(HandlerRegistry::class),
                new IntentLog,
            );
        });
    }
}
