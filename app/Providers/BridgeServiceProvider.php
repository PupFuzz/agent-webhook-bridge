<?php

namespace App\Providers;

use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Support\Facades\Log;
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
 * per-agent YAMLs (+ optional shared-identities.json) are read fresh each
 * request (FPM-worker caching is a future optimisation).
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

        // Board-tools registry (DL-217), a container singleton like HandlerRegistry
        // so an operator can register custom tools against the exact instance BOTH
        // front doors resolve. Carries no per-request state (the shipped tools are
        // stateless). "Singleton" here means ONE instance PER PROCESS — the FPM
        // worker serving the HTTP door and the CLI process running bridge:tools-call
        // are different processes with their own container, so operator-registered
        // tools must be wired in a ServiceProvider (loaded by both), not per-request.
        $this->app->singleton(BoardToolsRegistry::class, fn (): BoardToolsRegistry => new BoardToolsRegistry);

        // Board-tools dispatcher (Finding A, card 4952) — the shared post-agent-
        // resolution machinery the HTTP controller and the ssh-forced-command
        // command both dispatch through, over the ONE registry singleton above.
        $this->app->singleton(BoardToolDispatcher::class, fn (): BoardToolDispatcher => new BoardToolDispatcher($this->app->make(BoardToolsRegistry::class)));

        $this->app->bind(DispatchService::class, function (): DispatchService {
            $configDir = (string) config('bridge.config_dir');
            // The identity registry is built from the SAME scanned YAMLs the
            // subscription registry reads (each agent declares its own identity
            // ids) — one source of truth, fail-closed: a malformed YAML throws
            // here too (→ 5xx → upstream redelivers once fixed), not a silent
            // degrade. shared-identities.json is the only separate file.
            $subscriptions = new SubscriptionRegistry($configDir);

            // Seed the writeback identity into the global echo set (DL-018/019) so
            // the card_updated webhook the bridge's own card-move produces is never
            // a signal for any agent. This is best-effort wiring, NOT the
            // fail-closed gate: DispatchService is constructor-injected (e.g. into
            // ReplayCommand), so it's built at console boot too — a malformed
            // writeback.json must surface as a bridge:check error / a treatment-B
            // 5xx in the move handler, not crash every CLI invocation. On a bad
            // file the seeding is skipped (the writeback won't run, so there's no
            // identity to echo-suppress anyway).
            try {
                $writeback = WritebackConfig::load($configDir);
                if ($writeback !== null && $writeback->identityId !== null) {
                    config(['bridge.global_echo_ids' => array_values(array_unique([
                        ...(array) config('bridge.global_echo_ids', []),
                        (string) $writeback->identityId,
                    ]))]);
                }
            } catch (\Throwable $e) {
                Log::warning('bridge: writeback.json could not be loaded for echo-seeding; bridge:check will report it', ['error' => $e->getMessage()]);
            }

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
