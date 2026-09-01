<?php

namespace App\Providers;

use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ChannelProbeEnvironment;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Support\SystemChannelProbeEnvironment;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\ServingProcessEnvironment;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Tools\SystemServingProcessEnvironment;
use App\Bridge\Tools\SystemSshProbeEnvironment;
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

        // The periodic-job HANDLER registry (card#8425 / DL-325) — a container singleton
        // for the same reason HandlerRegistry is one: an operator registers custom job
        // handlers against the exact instance the scheduler resolves, from a ServiceProvider
        // (see docs/customization.md). "Singleton" is PER PROCESS, and that matters more
        // here than anywhere else in this file: the FPM worker running the event-gated pass
        // and the CLI process running `bridge:tick` are different processes with their own
        // container, so a handler wired anywhere but a provider both load exists on ONE
        // ingress and is a loud refusal on the other.
        //
        // ⭐ The armed-mutators list is a CONSTRUCTOR ARGUMENT, not something the registry
        // reads for itself: what a build can do must not depend on config state read at an
        // unpredictable moment, and a test binding its own list must not have to fight the
        // container for it.
        $this->app->singleton(JobHandlerRegistry::class, fn (): JobHandlerRegistry => new JobHandlerRegistry(
            JobHandlerRegistry::armedFromConfig(),
            $this->app->make(StandupGate::class),
        ));

        $this->app->singleton(JobScheduler::class, fn (): JobScheduler => new JobScheduler($this->app->make(JobHandlerRegistry::class)));
        $this->app->bind(JobRegistry::class, fn (): JobRegistry => new JobRegistry($this->app->make(JobHandlerRegistry::class)));

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

        // The host-facts seam for the bridge:check SSH-transport probe (card 4952) —
        // the default reads the real host; a test binds an in-memory fake to drive the
        // root-gated / FIPS / sshd legs.
        $this->app->bind(SshProbeEnvironment::class, SystemSshProbeEnvironment::class);

        // The serving-process seam behind the board-tools client-half provenance
        // (card#7836 / DL-316) — the default reads the real process; a test binds an
        // in-memory fake, because a suite cannot manufacture a controlling terminal for
        // itself and one that measured its own ambient process would be green or red by
        // accident of how the developer launched it.
        $this->app->bind(ServingProcessEnvironment::class, SystemServingProcessEnvironment::class);

        // The endpoint-liveness seam for the bridge:check channel-transport legs
        // (DL-242 stage 5b) — the default connects for real; a test binds a fake so a
        // live-vs-dead endpoint (and the platform's own error text) is deterministic.
        $this->app->bind(ChannelProbeEnvironment::class, SystemChannelProbeEnvironment::class);

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
