<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckReport;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\Checks\AgentClassifierResolvableCheck;
use App\Bridge\Check\Checks\ChannelSnapshotCheck;
use App\Bridge\Check\Checks\CiFailureFilterCheck;
use App\Bridge\Check\Checks\DatabaseConnectivityCheck;
use App\Bridge\Check\Checks\InstallSuffixDsnCheck;
use App\Bridge\Check\Checks\ReconcileRepoTokensCheck;
use App\Bridge\Check\Checks\RetentionPostureCheck;
use App\Bridge\Check\Checks\SshLiveProbeCheck;
use App\Bridge\Check\Checks\SshPinnedLineCheck;
use App\Bridge\Check\Checks\WakeMembershipCheck;
use App\Bridge\Check\Checks\WritebackAlertChannelCheck;
use App\Bridge\Check\Checks\WritebackBoardStateCheck;
use App\Bridge\Check\Checks\WritebackByRefCheck;
use App\Bridge\Check\Checks\WritebackConfigCheck;
use App\Bridge\Check\Checks\WritebackIdentityCheck;
use App\Bridge\Check\Checks\WritebackMappingConfigCheck;
use App\Bridge\Check\Checks\WritebackSourceCoverageCheck;
use App\Bridge\Check\Checks\WritebackTokenCheck;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Contracts\DeclaresConsumedEvents;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\ChannelToken;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\Finding;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SecretPath;
use App\Bridge\Support\Severity;
use App\Bridge\Support\SignalAllowlist;
use App\Bridge\Support\UrlValidator;
use App\Bridge\Tools\BoardToolAgentResolver;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Validate the install: config/secret dirs, DB connectivity, and that every
 * per-agent YAML parses. Run before going live (and in the cutover runbook).
 */
class CheckCommand extends BridgeCommand
{
    protected $signature = 'bridge:check {--probe-tools= : POST a live board_my_cards to this /agent-tools/call endpoint per enabled agent (opt-in — verifies the same-box loopback recipe end to end; the endpoint is the value the channel server uses, e.g. https://<bridge-hostname>/agent-tools/call)}
                            {--probe-tools-ssh= : round-trip a live board_my_cards over ssh to this <user@host> (opt-in — certifies the SSH-forced-command board-tools transport end to end; card 4952)}';

    protected $description = 'Validate the bridge install config (dirs, DB connectivity, agent YAMLs)';

    /**
     * How many findings this run reported as `unvalidated` — checks that did NOT
     * run, so a zero exit says nothing about them (card 5170). Reset per run in
     * {@see self::handle()}: the container can hand back the same instance to a
     * second `Artisan::call`, and a leaked count would over-report.
     */
    private int $unvalidatedCount = 0;

    public function handle(): int
    {
        $ok = true;
        $this->unvalidatedCount = 0;

        // DL-242 stage 1: the check registry's first wiring. Registration is
        // UNCONDITIONAL (plan constraint (a)) — the opt-in probe's flag is a
        // constructor argument, never an `if` around register(). Built per run: the
        // container can hand this command back for a second Artisan::call, and a
        // retained runner would re-register into the same id namespace.
        $sshEnv = $this->laravel->make(SshProbeEnvironment::class);
        $runner = (new CheckRunner)
            ->register(CheckSlot::Database, new DatabaseConnectivityCheck, new InstallSuffixDsnCheck)
            ->register(CheckSlot::Retention, new RetentionPostureCheck)
            ->registerPerAgent(CheckSlot::AgentClassifier, new AgentClassifierResolvableCheck)
            ->registerPerAgent(CheckSlot::AgentPolicy, new CiFailureFilterCheck, new WakeMembershipCheck)
            ->registerPerAgent(CheckSlot::AgentConfig, new ChannelSnapshotCheck(base_path('examples/channel-servers')))
            ->register(
                CheckSlot::Writeback,
                new WritebackConfigCheck,
                new WritebackIdentityCheck,
                new WritebackAlertChannelCheck,
                new WritebackTokenCheck,
                new ReconcileRepoTokensCheck,
                new WritebackMappingConfigCheck,
            )
            ->register(
                CheckSlot::WritebackProbe,
                new WritebackByRefCheck,
                new WritebackBoardStateCheck,
                new WritebackSourceCoverageCheck,
            )
            ->registerPerAgent(CheckSlot::BoardToolsSsh, new SshPinnedLineCheck($sshEnv))
            ->register(CheckSlot::ProbeToolsSsh, new SshLiveProbeCheck($sshEnv, $this->strOption('probe-tools-ssh')));
        // Plan constraint (c): the surviving inline derivation in this method populates
        // the context; migrated checks read it, unmigrated code keeps its locals.
        $ctx = new CheckContext;

        $configDir = config('bridge.config_dir');
        if (! is_string($configDir) || $configDir === '') {
            $this->error('bridge.config_dir (BRIDGE_CONFIG_DIR) is not set');
            $ok = false;
        } elseif (! is_dir($configDir)) {
            $this->error("config dir does not exist: {$configDir}");
            $ok = false;
        } else {
            $this->info("config dir: {$configDir}");
            $this->warnIfDirInsecure('config dir', $configDir);
        }

        $secretDir = config('bridge.secret_dir');
        if (! is_string($secretDir) || ! str_starts_with($secretDir, '/')) {
            $this->error('bridge.secret_dir (BRIDGE_SECRET_DIR) is not set or not absolute');
            $ok = false;
        } else {
            $this->info("secret dir: {$secretDir}");
            // Cover a split layout: when secret_dir is a different path, IT is the
            // dir holding the secrets — warn on its perms too (DL-014).
            if ($secretDir !== $configDir) {
                $this->warnIfDirInsecure('secret dir', $secretDir);
            }
        }

        if (! $this->emitReport($runner->run(CheckSlot::Database, $ctx))) {
            $ok = false;
        }

        try {
            BridgePaths::validateInboxConfig();
            $this->info('inbox surfacing config: ok (layout='.BridgePaths::inboxLayout().')');
        } catch (Throwable $e) {
            $this->error('inbox surfacing config: '.$e->getMessage());
            $ok = false;
        }

        if (! $this->emitReport($runner->run(CheckSlot::Retention, $ctx))) {
            $ok = false;
        }

        // Per-install endpoint URLs (when set — unset is fine until provisioning).
        foreach ([
            'receiver_base_url' => ['url' => (string) config('bridge.receiver_base_url'), 'secure' => false],
            // secret-bearing (token + provision-time HMAC secret) — https floor (#3574)
            'providers.kanban.api_base_url' => ['url' => (string) config('bridge.providers.kanban.api_base_url'), 'secure' => true],
        ] as $field => $spec) {
            if ($spec['url'] === '') {
                continue;
            }
            try {
                $spec['secure']
                    ? UrlValidator::secureHttpUrl($spec['url'], "bridge.{$field}")
                    : UrlValidator::httpUrl($spec['url'], "bridge.{$field}");
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                $ok = false;
            }
        }

        // Every configured provider must have a registered adapter (B-15): the
        // two provider lists (config('bridge.providers') and
        // WebhookAdapterFactory::SUPPORTED) are otherwise independent and drift —
        // an api_base_url for a provider with no adapter is a dead config the
        // receiver would 400 (unknown_provider) on.
        $providers = config('bridge.providers');
        if (is_array($providers)) {
            foreach (array_keys($providers) as $provider) {
                if (is_string($provider) && ! WebhookAdapterFactory::supports($provider)) {
                    $this->error("bridge.providers.{$provider} is configured but has no adapter (WebhookAdapterFactory::SUPPORTED = ".implode(', ', WebhookAdapterFactory::SUPPORTED).')');
                    $ok = false;
                }
            }
        }

        $agentNames = [];
        $configs = [];
        // The two writeback scope maps this loop accumulates now live on the context —
        // their consumers migrated in stage 3a, and a local kept alongside would be a
        // second copy to keep in step. See CheckContext for what each means.
        // github scope (repo full_name) => list of the agents subscribed to it and
        // the top-level event types each CONSUMES, for the event-follows-consumer
        // check below (card#4183 / DL-196). Multiple agents can subscribe one scope
        // (the bridge dispatches each event to all of them), so consumed is the
        // union over all of them — hence a list per scope, not one entry.
        // Shape: scope => list<array{agent:string, class:string, consumed:list<string>, declared:bool}>.
        $githubScopeConsumers = [];
        $hasSecretDir = is_string($secretDir) && str_starts_with($secretDir, '/');
        $ctx->secretDir = $hasSecretDir ? (string) $secretDir : null;
        if (is_string($configDir) && is_dir($configDir)) {
            foreach (glob(rtrim($configDir, '/').'/*.yml') ?: [] as $file) {
                $name = basename($file, '.yml');
                $agentNames[] = $name;
                try {
                    $cfg = AgentConfig::load($name, $configDir);
                } catch (Throwable $e) {
                    $this->error("agent config {$name}: ".$e->getMessage());
                    $ok = false;

                    continue;
                }
                $configs[] = $cfg;

                // The classifier-resolution gate — migrated to
                // AgentClassifierResolvableCheck (DL-242 stage 5a), run HERE so its line
                // stays at this agent's position (plan constraint (b)). THE `continue`
                // IS THE MIGRATION'S ONE COUPLING: an unresolvable classifier means every
                // remaining leg for this agent is skipped, exactly as the inline code did,
                // and CheckSlot::AgentClassifier is the abort slot that says so.
                if (! $this->emitReport($runner->runForAgent(CheckSlot::AgentClassifier, $cfg, $ctx))) {
                    $ok = false;

                    continue;
                }

                // Record which github scopes this agent DRIVES the writeback for:
                // its classifier must emit writeback reactions (#2162). Detected
                // out-of-process (DL-025) — AgentClassifierResolvableCheck's
                // probeLoadable already passed above, so this child loads cleanly. Used
                // after the loop to flag orphaned writeback.json mappings (a mapping no
                // classifier drives).
                if (ClassifierResolver::probeImplements($cfg->classifierClass, EmitsWritebackReactions::class)) {
                    foreach ($cfg->subscriptions as $sub) {
                        if ($sub->provider === 'github') {
                            $ctx->writebackEmittingScopes[$sub->scopeId] = true;
                        }
                    }
                }

                // DL-204 (#4357): record scopes whose agent enables the coord-card-move family.
                // coord-card-move is never in DEFAULT_FAMILIES, so a raw-config membership test IS
                // the resolved answer — an unset families list defaults to [coord-message] and can
                // never contain it.
                if (in_array('coord-card-move', $cfg->classifierConfig->strings('families'), true)) {
                    foreach ($cfg->subscriptions as $sub) {
                        if ($sub->provider === 'github') {
                            $ctx->coordCardMoveScopes[$sub->scopeId] = true;
                        }
                    }
                }

                // card#4183 (DL-196): record the top-level github event types this
                // agent's classifier CONSUMES per subscribed github scope, for the
                // event-follows-consumer check after the loop. DL-025-safe, mirroring
                // the orphan check above: probeImplements is OUT OF PROCESS; the
                // consumedEventTypes() call is on the instance for() already resolved
                // in-process (in AgentClassifierResolvableCheck, after its probeLoadable
                // passed — a cache hit here, never a fresh load), wrapped in
                // catch(Throwable) → an undeclared/failing classifier contributes
                // nothing to `consumed` (conservative — at worst a false WARN, never a
                // false clean). A classifier NOT implementing the interface is recorded
                // as `declared:false` so the check can disambiguate a possible false
                // positive (sola's #22 note).
                $declares = ClassifierResolver::probeImplements($cfg->classifierClass, DeclaresConsumedEvents::class);
                $consumed = [];
                if ($declares) {
                    try {
                        $instance = ClassifierResolver::for($cfg);
                        $consumed = $instance instanceof DeclaresConsumedEvents
                            ? $instance->consumedEventTypes($cfg->classifierConfig)
                            : [];
                        $declares = $instance instanceof DeclaresConsumedEvents;
                    } catch (Throwable) {
                        $declares = false;   // treat as undeclared (conservative)
                        $consumed = [];
                    }
                }

                // The lazy-classifier-config advisories (DL-197's CI-failure name filter,
                // DL-213's wake_membership/comment_to population) — migrated to
                // CiFailureFilterCheck + WakeMembershipCheck (DL-242 stage 5a), run HERE
                // so their lines stay at this agent's position (plan constraint (b)) and
                // AFTER the derivation above, which prints nothing.
                if (! $this->emitReport($runner->runForAgent(CheckSlot::AgentPolicy, $cfg, $ctx))) {
                    $ok = false;
                }

                foreach ($cfg->subscriptions as $sub) {
                    if ($sub->provider === 'github') {
                        $githubScopeConsumers[$sub->scopeId][] = [
                            'agent' => $name,
                            'class' => $cfg->classifierClass,
                            'consumed' => $consumed,
                            'declared' => $declares,
                        ];
                    }
                }

                // Secret presence per subscription — a missing secret means the
                // receiver 401s the delivery (unknown_scope), invisible until
                // activity goes missing. Warn (provisioning may be pending).
                if ($hasSecretDir) {
                    foreach ($cfg->subscriptions as $sub) {
                        $secretPath = SecretPath::for((string) $secretDir, $sub->provider, $sub->scopeId);
                        if (! is_file($secretPath)) {
                            $this->warn("agent {$name}: {$sub->provider}:{$sub->scopeId} has no secret at {$secretPath} — run bridge:provision");
                        } elseif (SecretFile::isInsecure($secretPath)) {
                            $this->warn("agent {$name}: ".SecretFile::permsMessage($secretPath).' — the receiver will 500 (secret_perms_insecure) until fixed');
                        }
                    }
                    // API token presence per provider (the token bridge:provision
                    // uses). Convention <secret_dir>/<provider>/token, or the
                    // per-agent override. Warn — a provider may not be provisioned yet.
                    foreach (array_unique(array_map(fn ($s) => $s->provider, $cfg->subscriptions)) as $provider) {
                        $tokenPath = $cfg->tokenPath((string) $secretDir, $provider);
                        if (! is_file($tokenPath) || ! is_readable($tokenPath)) {
                            $this->warn("agent {$name}: {$provider} API token not readable at {$tokenPath} — bridge:provision will SKIP {$provider} scopes");
                        } elseif (SecretFile::isInsecure($tokenPath)) {
                            $this->warn("agent {$name}: ".SecretFile::permsMessage($tokenPath).' — bridge:provision will FAIL until fixed');
                        }
                    }
                }

                // channel.auth.token_path readability + perms (DL-008). Path is
                // explicit (not under secret_dir), so checked independent of it.
                // Warn at preflight; the handler is fail-closed at push time.
                if ($cfg->channel->tokenPath !== null) {
                    try {
                        ChannelToken::read($cfg->channel->tokenPath);
                    } catch (Throwable $e) {
                        $this->warn("agent {$name}: ".$e->getMessage().' — channel_push will FAIL until fixed');
                    }
                }

                // channel.socket parent-dir reachability (DL-039). The socket
                // itself may be absent at preflight (channel server not started
                // yet — fine), but a MISSING or non-writable PARENT dir is a real
                // misconfig that makes live-wake silently no-op — classically a
                // uid mismatch after a host restore (the path pins /run/user/<uid>).
                // Surface it loudly; warn, don't fail (the socket is the channel
                // server's to create).
                if ($cfg->channel->socket !== null) {
                    $dir = dirname($cfg->channel->socket);
                    if (! is_dir($dir)) {
                        $this->warn("agent {$name}: channel.socket parent dir {$dir} does not exist — live-wake will silently no-op. On systemd Linux this is /run/user/<uid>; a uid change (host restore) breaks it. Repoint channel.socket, or write it uid-agnostically as \${XDG_RUNTIME_DIR}/…");
                    } elseif (! is_writable($dir)) {
                        $uid = function_exists('posix_getuid') ? (string) posix_getuid() : '?';
                        $this->warn("agent {$name}: channel.socket parent dir {$dir} is not writable by this user (uid {$uid}) — live-wake will fail. Likely a uid mismatch after a host restore.");
                    }

                    // Visible bind-FAILURE marker (FR #2444). A session whose
                    // connector loses the socket-bind race exits with a stderr
                    // message Claude Code swallows, leaving that session deaf to
                    // live-wake invisibly. The connector now drops a marker file;
                    // surface it here so the silent failure is loud on demand.
                    $marker = $cfg->channel->socket.'.FAILED';
                    clearstatcache(true, $marker);
                    if (is_file($marker)) {
                        $detail = trim((string) @file_get_contents($marker));
                        $this->warn("agent {$name}: channel bind-FAILURE marker at {$marker}".($detail !== '' ? " ({$detail})" : '').' — a Claude Code session came up DEAF: its connector could not bind, so another session holds the channel and this one receives nothing. Close the duplicate session, restart the intended one, then rm the marker.');
                    }

                    // Liveness ping (FR #2444). A present socket file does NOT mean
                    // a live session is consuming it — a crash can leave a stale
                    // socket, and the bridge would still deliver HTTP 202 to a
                    // dead/duplicate endpoint and log `delivered`. Probe whether
                    // anything is actually listening: distinguishes "a session is
                    // attached" from "stale socket / no live session". Warn, never
                    // fail — at preflight the server legitimately may not be up yet.
                    clearstatcache(true, $cfg->channel->socket);
                    if (is_dir($dir) && file_exists($cfg->channel->socket)
                        && ! is_link($cfg->channel->socket)
                        && filetype($cfg->channel->socket) === 'socket'
                    ) {
                        $conn = @stream_socket_client('unix://'.$cfg->channel->socket, $errno, $errstr, 0.5);
                        if ($conn !== false) {
                            fclose($conn);
                            $this->info("agent {$name}: channel socket live — a session is listening on {$cfg->channel->socket}");
                        } else {
                            $this->warn("agent {$name}: channel socket {$cfg->channel->socket} exists but nothing is listening (stale socket / no live session) — live-wake no-ops until a session starts. If a session IS running, its connector may have come up deaf (look for a .FAILED marker).");
                        }
                    }
                } elseif ($cfg->channel->url !== null) {
                    // HTTP transport (channel.url set, no socket — the multi-host /
                    // SSH-tunnel topology). The deaf-session failure mode here is a
                    // TCP-port bind race, not a socket-file collision, so DL-154/155's
                    // surfacing must be rendered for HTTP too (it was UDS-only before).
                    //
                    // Topology caveat: bridge:check runs on the RECEIVER host. For a
                    // remote/tunneled agent the connector AND its `…http-<port>.FAILED`
                    // marker live on the AGENT host — unreachable from here — so the
                    // launcher surfaces that marker on the agent host (FR-1). What IS
                    // meaningful cross-host is the liveness probe: a TCP connect to the
                    // loopback endpoint (the local end of the reverse tunnel) reaches the
                    // remote listener. We also surface the marker best-effort for the
                    // co-located same-host-HTTP case.
                    $parts = parse_url($cfg->channel->url);
                    $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '127.0.0.1';
                    $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : null;

                    if ($port === null) {
                        $this->warn("agent {$name}: channel.url {$cfg->channel->url} has no explicit port — cannot liveness-probe the HTTP channel.");
                    } else {
                        // Best-effort local marker (same-host HTTP only). The server's
                        // HTTP markerPath() keys on BRIDGE_CHANNEL_NAME + port; the agent
                        // name is the best proxy we have here. A miss is harmless — the
                        // launcher surfaces it authoritatively on the agent host.
                        $xdg = getenv('XDG_RUNTIME_DIR');
                        $xdgDir = is_string($xdg) && $xdg !== '' ? $xdg : '/tmp';
                        $httpMarker = $xdgDir.'/agent-webhook-bridge-channel-'.$name.'.http-'.$port.'.FAILED';
                        clearstatcache(true, $httpMarker);
                        if (is_file($httpMarker)) {
                            $detail = trim((string) @file_get_contents($httpMarker));
                            $this->warn("agent {$name}: channel bind-FAILURE marker at {$httpMarker}".($detail !== '' ? " ({$detail})" : '').' — a Claude Code session came up DEAF on the HTTP transport (a TCP-port bind race). Close the duplicate session, restart the intended one, then rm the marker.');
                        }

                        // Liveness ping: distinguishes a live, listening connector (or a
                        // healthy reverse tunnel) from a dead/absent one. Warn, never
                        // fail — at preflight the session legitimately may not be up.
                        $conn = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0.5);
                        if ($conn !== false) {
                            fclose($conn);
                            $this->info("agent {$name}: channel HTTP endpoint live — something is listening on {$host}:{$port} (the connector, or the reverse-tunnel local end).");
                        } else {
                            $this->warn("agent {$name}: channel HTTP endpoint {$host}:{$port} not answering".($errstr !== '' ? " ({$errstr})" : '').' — no live session, or the reverse tunnel is down. live-wake no-ops until it is up.');
                        }
                    }
                }

                // The DEPLOYED channel-server snapshot (DL-229) — migrated to
                // ChannelSnapshotCheck, run HERE so its lines stay interleaved at this
                // agent's position (plan constraint (b)).
                if (! $this->emitReport($runner->runForAgent(CheckSlot::AgentConfig, $cfg, $ctx))) {
                    $ok = false;
                }
            }
        }

        // Constraint (c): the accumulation above is this method's; publishing it to the
        // context is what lets a check migrated in a LATER stage read it without also
        // migrating its producer.
        $ctx->configs = $configs;

        // Build the registry from the scanned configs (surfaces id-collision
        // warnings at preflight) and validate each agent's treat_as_signal — an
        // unknown name is fail-closed at dispatch (5xx), so catch it here.
        if ($configs !== [] && is_string($configDir)) {
            $registry = AgentRegistry::fromAgentConfigs($configs, AgentRegistry::loadSharedIdentities($configDir));
            foreach ($registry->collisions() as $warning) {
                $this->warn($warning);
            }
            foreach ($configs as $cfg) {
                try {
                    SignalAllowlist::default($cfg->echoSuppression->treatAsSignal, $registry);
                } catch (Throwable $e) {
                    $this->error("agent {$cfg->agentName}: ".$e->getMessage());
                    $ok = false;
                }
            }
        }

        // BRIDGE_DEFAULT_AGENT must name a real config, else a bare bridge:inbox
        // silently surfaces nothing.
        $defaultAgent = config('bridge.default_agent');
        if (is_string($defaultAgent) && $defaultAgent !== '' && ! in_array($defaultAgent, $agentNames, true)) {
            $this->warn("BRIDGE_DEFAULT_AGENT '{$defaultAgent}' has no matching config {$configDir}/{$defaultAgent}.yml");
        }

        // shared-identities.json is optional; report it when present so a v0.13
        // schema-v1 migration / a malformed file surfaces at preflight.
        if (is_string($configDir) && is_file(rtrim($configDir, '/').'/shared-identities.json')) {
            $shared = AgentRegistry::loadSharedIdentities($configDir);
            $this->info('shared-identities.json: '.count($shared).' shared account(s)');
        }

        // writeback.json is optional (absent ⇒ writeback off). A malformed file
        // is fail-closed (load throws) — catch it as a preflight failure. The config
        // -plane assertions on a file that DID parse are registered checks
        // (CheckSlot::Writeback); what stays here is the load and its envelope.
        if (is_string($configDir) && is_file(rtrim($configDir, '/').'/writeback.json')) {
            try {
                // DERIVATION, NOT ASSERTION (plan constraint (c)): the load is what
                // populates the context; every assertion on the result is a registered
                // check.
                $ctx->writeback = WritebackConfig::load($configDir);
                if (! $this->emitReport($runner->run(CheckSlot::Writeback, $ctx))) {
                    $ok = false;
                }

                // Probe that the writeback token can actually SEE each mapped
                // board. A token whose user lost board membership (or a drifted
                // board_id) gets a 200 with 0 cards — NOT an HTTP error — so the
                // writeback silently no-ops every move (or duplicates a dependabot
                // card). Catch that degraded-but-not-erroring state HERE, at config
                // time. The assertions are registered checks (CheckSlot::WritebackProbe);
                // what stays here is the client construction — DERIVATION, not assertion
                // (plan constraint (c)) — and its fail-soft envelope.
                if ($ctx->writeback !== null && $ctx->writeback->mappings !== []) {
                    try {
                        $ctx->client = WritebackClientFactory::make();
                        if (! $this->emitReport($runner->run(CheckSlot::WritebackProbe, $ctx))) {
                            $ok = false;
                        }
                    } catch (Throwable $e) {
                        // THE SECOND FAIL-SOFT ENVELOPE, INLINE for the same reason the
                        // outer one is (DL-242 stage 3a): CheckRunner deliberately does not
                        // catch, so wrapping emitReport() keeps "a probe failure degrades to
                        // one warn" a property of this method rather than an assumption
                        // about three checks' callees. Its realistic thrower is
                        // WritebackClientFactory::make() above — derivation, inline anyway.
                        $this->warn('writeback: skipped board-visibility probe — '.$e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                // THE FAIL-SOFT ENVELOPE STAYS INLINE, WRAPPING the emitReport() calls
                // rather than moving into a check (DL-242 stage 3a). CheckRunner
                // deliberately does not catch, so a check that threw would ABORT
                // bridge:check where today this arm turns the throw into one error line
                // plus a non-zero exit. Keeping it here preserves that by construction.
                // The realistic thrower is WritebackConfig::load above — every leg the
                // registered checks own is total (see ReconcileRepoTokensCheck) or carries
                // its own catch (see WritebackBoardStateCheck) — but the envelope is what
                // makes that a property of this method rather than an assumption about
                // nine checks' callees.
                $this->error('writeback.json: '.$e->getMessage());
                $ok = false;
            }
        }

        // card#4183 (DL-196): event-follows-consumer. WARN (never fail) when a
        // github event type has ARRIVED for a scope but no enabled classifier
        // consumes it. Independent of writeback (a coord agent has no writeback).
        $this->checkEventFollowsConsumer($githubScopeConsumers);

        // DL-217 (default-ON per v7): board-tools health. A default-on block that
        // could not be satisfied (suppressedReason) and a dead/ambiguous bearer FAIL
        // (a broken enablement, not opt-in); the board-STATE checks (swimlane/stage on
        // board, service-user membership) stay WARN (DL-220 split — a transient/empty
        // kanban read must not FAIL the install check).
        if (! $this->checkBoardTools($configs, $runner, $ctx)) {
            $ok = false;
        }

        // DL-217: opt-in live board-tools probe. Offline by default (like the rest of
        // this command's local checks); when --probe-tools names the endpoint the
        // channel server will use, exercise the real loopback path end to end. A
        // non-2xx or an isolation mismatch is a HARD failure (it certifies a broken
        // enablement), unlike the offline warns above.
        $probeEndpoint = $this->strOption('probe-tools');
        if ($probeEndpoint !== null && ! $this->probeBoardToolsEndpoint($configs, $probeEndpoint)) {
            $ok = false;
        }

        // card 4952: opt-in live ssh round-trip. Certifies the SSH-forced-command
        // transport end to end (reachable, JSON-clean stdout, ok:true, scope header
        // matches a configured ssh agent's lane). A failure is a HARD failure.
        // Migrated to SshLiveProbeCheck; run unconditionally — the check holds the flag
        // and is silent when it was not passed (plan constraint (a)).
        if (! $this->emitReport($runner->run(CheckSlot::ProbeToolsSsh, $ctx))) {
            $ok = false;
        }

        // card 5170: a green exit means nothing FAILED — it says nothing about the
        // checks that never ran. Silent at zero: an install where everything was
        // measured has nothing to disclaim, and an install that deliberately leaves
        // `channel.server_path` unset is correct (docs/multi-host.md instructs it),
        // so this discloses, it does not scold.
        //
        // The wording is bounded to what the counter can support, and the bound is
        // NOT "reports a severity" — that is true but inoperative, and its converse
        // reads as a guarantee this number cannot give. The did-not-run population
        // that ISN'T here is large and mostly DOES carry a severity: a dozen-odd
        // couldn't-probe sites in this command report `warn` (many bypassing
        // emitFinding entirely), and ChannelSnapshotProbe's three could-not-measure
        // findings come through emitFinding as `warn` and are still not tallied (it
        // was four until DL-237 deleted the completeness leg's unenumerable-reference
        // warn along with the leg). So the operative bound is the severity itself, and
        // the tally is a floor.
        if ($this->unvalidatedCount > 0) {
            $this->line("{$this->unvalidatedCount} check(s) reported `unvalidated` — not a failure, and not a pass either: those checks did not run, so this run says nothing about what they would have found (see the lines above). This is a floor, not an inventory: only checks reporting `unvalidated` are counted, and a check that could not run usually reports `warn` instead — so no tally line does NOT mean every leg ran.");
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * DL-217 board-tools preflight (default-ON per v7). Returns false (→ non-zero
     * exit) on a HARD failure; WARN-level findings never flip it. Three severities:
     *  - A DEFAULT-on block that could not satisfy itself (suppressedReason) → FAIL.
     *    Scanned across ALL configs FIRST, independent of the enabled-subset below —
     *    a fleet whose only board_tools agent is suppressed must FAIL, not silently
     *    return at the `enabled === []` guard.
     *  - A dead (unreadable) or ambiguous (colliding) bearer → FAIL (broken enablement).
     *  - Board STATE (create stage + swimlane(s) on the board; the service user's
     *    MEMBERSHIP of board_id) → WARN (DL-026/DL-220): a temporarily-unreachable
     *    kanban or a genuinely-empty board must not FAIL the check.
     *
     * @param  list<AgentConfig>  $configs
     */
    private function checkBoardTools(array $configs, CheckRunner $runner, CheckContext $ctx): bool
    {
        $ok = true;

        // FIRST — all-configs suppressedReason scan (before the enabled-subset early
        // return AND before the writeback-client try/catch). A default-on block that
        // could not be satisfied is enabled=false, so the checks below skip it silently;
        // this is the only place its failure surfaces (P2-closed-by-construction).
        foreach ($configs as $cfg) {
            $bt = $cfg->boardTools;
            if ($bt !== null && $bt->suppressedReason !== null) {
                $this->error("board_tools: agent {$cfg->agentName}: {$bt->suppressedReason} — board tools are OFF for this agent (a default-on block could not be satisfied). Fix the config, or set enabled: false to stage it silently.");
                $ok = false;
            }
        }

        $enabled = array_values(array_filter($configs, fn (AgentConfig $c) => $c->boardTools !== null && $c->boardTools->enabled));
        if ($enabled === []) {
            return $ok;
        }

        // Token readability + collision scan — typed problems, both FAIL under
        // default-ON (a dead/ambiguous bearer is a broken enablement).
        $resolver = new BoardToolAgentResolver($configs);
        foreach ($resolver->problems() as $problem) {
            $this->error($problem['message']);
            $ok = false;
        }

        try {
            $client = WritebackClientFactory::make();
        } catch (Throwable $e) {
            $this->warn('board_tools: enabled for '.count($enabled).' agent(s) but the kanban writeback client is unavailable ('.$e->getMessage().') — the tools read/write via the least-privilege writeback token; place it (chmod 600) or the tools will fail at call time.');

            return $ok;
        }

        foreach ($enabled as $cfg) {
            $bt = $cfg->boardTools;
            if ($bt === null || $bt->boardId === null) {
                continue;   // defensive: enabled ⇒ boardId non-null by construction
            }
            $name = $cfg->agentName;
            try {
                $vis = $client->visibility($bt->boardId);
                if ($vis['total'] === 0) {
                    $this->warn("board_tools: agent {$name}: the writeback token sees 0 cards on board {$bt->boardId} — EITHER the board is empty (fine) OR the service user is not a member / board_id is wrong (then board_my_cards returns an empty window and board_create_card's correlation reads blind). Verify membership + board_id if you expect cards.");
                } else {
                    $this->info("board_tools: agent {$name}: writeback token can see board {$bt->boardId}");
                }

                $swimlaneIds = $client->boardSwimlaneIds($bt->boardId);
                foreach (array_filter([$bt->swimlaneId, $bt->sharedSwimlaneId], fn ($id) => $id !== null) as $swimlaneId) {
                    if (! in_array($swimlaneId, $swimlaneIds, true)) {
                        $this->warn("board_tools: agent {$name}: swimlane_id {$swimlaneId} is not on board {$bt->boardId} — board_create_card will 422 (create) or board_my_cards will read empty until fixed.");
                    }
                }

                $stageIds = array_keys($client->boardStageOrder($bt->boardId));
                if ($bt->createStageId !== null && $stageIds !== [] && ! in_array($bt->createStageId, $stageIds, true)) {
                    $this->warn("board_tools: agent {$name}: create_stage_id {$bt->createStageId} is not a stage on board {$bt->boardId} — every board_create_card will 422 until fixed.");
                }

                if ($bt->coordBoardId !== null) {
                    $coordVis = $client->visibility($bt->coordBoardId);
                    if ($coordVis['total'] === 0) {
                        $this->warn("board_tools: agent {$name}: coord_board_id {$bt->coordBoardId} reads 0 cards — the coordination leg returns empty if the service user is not a member or the id is wrong.");
                    }
                }
            } catch (Throwable $e) {
                $this->warn("board_tools: agent {$name}: could not read board {$bt->boardId} with the writeback token — ".$e->getMessage());
            }
        }

        // SSH-transport pinned-line + sshd-posture probe (card 4952) — offline, runs
        // in the default bridge:check. A present-but-bad forced-command line (grants
        // pty/forwarding), an ambiguous/absent-authoritative line, or a FIPS-rejected
        // key FAILs; an UNVERIFIABLE (non-root / relocated keyfile) leg WARNs and
        // names the `sudo bridge:check` cert step — never a false OK, never a hard red.
        $sshAgents = array_values(array_filter($enabled, fn (AgentConfig $c) => $c->boardTools?->transport === 'ssh'));
        if ($sshAgents !== [] && ! $this->checkSshTransport($sshAgents, $runner, $ctx)) {
            $ok = false;
        }

        return $ok;
    }

    /**
     * The offline SSH-transport probe (card 4952): per enabled ssh agent, verify the
     * pinned authorized_keys line forces bridge:tools-call --agent=X and denies
     * pty+forwarding (OUTCOME-based, never a `restrict` keyword match) + a FIPS key
     * algo on a FIPS seat; once, verify the sshd password-auth posture (root-gated).
     * Only a `fail` finding flips the exit.
     *
     * @param  list<AgentConfig>  $sshAgents
     */
    private function checkSshTransport(array $sshAgents, CheckRunner $runner, CheckContext $ctx): bool
    {
        $ok = true;
        // The forced-command account (board_tools.ssh_account, default the invoking
        // run-user) is per-agent, so the pinned-line + keys resolution is per-agent.
        // The board-tools security boundary is the forced-command authorized_keys entry
        // (checked per-agent via probePinnedLine), not the account's sshd posture: card 5091
        // retired the account-level `Match User` hardening (PasswordAuthentication no +
        // ClientAlive/MaxSessions) because the ssh-account routinely doubles as the operator's
        // interactive login, so hardening it locked the operator out. bridge:check certifies
        // the transport by the pinned key + the live round-trip (--probe-tools-ssh), never by
        // the account's password/idle posture.
        $agentIncomplete = [];    // agentName => any non-ok pinned-line finding
        foreach ($sshAgents as $cfg) {
            // The pinned-line probe is SshPinnedLineCheck; the DL-225 advisory below
            // stays inline and reads the severities back off its report, because it
            // emits after the whole loop and folding it in would reorder output.
            $report = $runner->runForAgent(CheckSlot::BoardToolsSsh, $cfg, $ctx);
            // Selected BY ID, not by walking the whole report: a second check migrated
            // into this slot later must not silently start feeding this advisory.
            foreach ($report->results as $result) {
                if ($result->id !== SshPinnedLineCheck::ID) {
                    continue;
                }
                foreach ($result->findings as $finding) {
                    if (self::severityMeansSetupIncomplete($finding->severity)) {
                        $agentIncomplete[$cfg->agentName] = true;
                    }
                }
            }
            if (! $this->emitReport($report)) {
                $ok = false;
            }
        }

        // v0.68.0 pre-upgrade advisory (DL-225): an agent that landed on the ssh
        // transport via the flipped default (no explicit `transport:` key) whose ssh
        // setup is not yet complete will lose its loopback HTTP path on upgrade — the
        // implicit-http block now reads as ssh, so the bearer stops resolving and the
        // call fails closed (401). Advisory only (never flips $ok): the runtime break
        // is already loud, this is the pre-deploy heads-up. An EXPLICIT `transport:`
        // choice is intentional and never advised.
        foreach ($sshAgents as $cfg) {
            $bt = $cfg->boardTools;
            if ($bt === null || $bt->transportExplicit) {
                continue;
            }
            $incomplete = $agentIncomplete[$cfg->agentName] ?? false;
            if ($incomplete) {
                $this->warn("board_tools ssh: agent {$cfg->agentName} is on ssh by the v0.68.0 default (no explicit transport:); its ssh setup is incomplete or could not be verified from here — pin `transport: http` to keep the loopback path, or complete ssh setup and run `sudo bridge:check` to certify.");
            }
        }

        return $ok;
    }

    /**
     * Whether a pinned-line finding's severity means the agent's ssh setup is
     * INCOMPLETE (feeds the DL-225 advisory only). POSITIVE membership,
     * deliberately: the `!== 'ok'` proxy it replaces silently absorbs any severity
     * added to the vocabulary later — card 5170's `unvalidated` would have flagged
     * an agent's setup incomplete on the strength of a check nobody ran. Exhaustive
     * `match` (card 5178): a fifth {@see Severity} case is a phpstan error here, not
     * a value that silently picks a side.
     */
    private static function severityMeansSetupIncomplete(Severity $severity): bool
    {
        return match ($severity) {
            Severity::Warn, Severity::Fail => true,
            Severity::Ok, Severity::Unvalidated => false,
        };
    }

    /**
     * Render one check's {@see CheckReport} and report whether it was fail-free.
     *
     * This is the text renderer stage 9 replaces with a {@see Check}-
     * aware pair (text + json). It walks findings rather than results because today's
     * output has no per-check framing to render — the id is carried for the inventory,
     * not for the operator.
     */
    private function emitReport(CheckReport $report): bool
    {
        $ok = true;
        foreach ($report->findings() as $finding) {
            if (! $this->emitFinding($finding)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Render one probe {@see Finding} through the existing info/warn/error
     * convention. Returns false (→ flip the caller's $ok) ONLY on a `fail` — the exit
     * contract is that one arm, so a new severity can never change what `bridge:check`
     * exits.
     *
     * IT TAKES NO PREFIX. Stage 1 migrated every prefixing call site into a
     * {@see Check}, and a check yields display-ready messages: a
     * Finding has no scope field, and one check's two message shapes
     * (`board_tools ssh: …` and `board_tools ssh probe: …`) cannot share a render-time
     * prefix anyway.
     *
     * `unvalidated` (card 5170) renders PLAIN: green would read as certified by a
     * check that never ran, and yellow would nag a documented-correct population
     * (a multi-host install is TOLD to leave `channel.server_path` unset) with no
     * action available to silence it.
     *
     * BOTH arms are exhaustive `match`es over the enum, the RETURN included
     * (card 5178). The render arm alone would leave the exit decision as a
     * fall-through, which is the shape that let an unknown severity print green in
     * the first place — one level over. A fifth case reds phpstan at both.
     */
    private function emitFinding(Finding $finding): bool
    {
        $message = $finding->message;

        match ($finding->severity) {
            Severity::Fail => $this->error($message),
            Severity::Warn => $this->warn($message),
            Severity::Unvalidated => $this->emitUnvalidated($message),
            Severity::Ok => $this->info($message),
        };

        return match ($finding->severity) {
            Severity::Fail => false,
            Severity::Warn, Severity::Unvalidated, Severity::Ok => true,
        };
    }

    /**
     * Plain line + the run's tally. Counted HERE — the single chokepoint every probe
     * finding flows through, so any future probe emitting the severity is tallied
     * without touching its call site.
     */
    private function emitUnvalidated(string $message): void
    {
        $this->unvalidatedCount++;
        $this->line($message);
    }

    /**
     * DL-217 live board-tools probe (opt-in, --probe-tools=<endpoint>). For each
     * agent with an enabled board_tools block and a readable bearer, POST a
     * board_my_cards over the REAL network path (TLS verify on) to the endpoint the
     * channel server will use, and certify the round trip:
     *  - the loopback gate admits the call (a 403 names the on-box public-IP peer trap);
     *  - the bearer authenticates (a 401 names the token path / collision);
     *  - the returned window is scoped to THIS agent's configured lane. board_my_cards
     *    does NOT expose a per-row swimlane_id (projectCard drops it), so the observable
     *    that the fail-closed row filter ran is the result HEADER: result.board_id /
     *    result.swimlane_id must equal the configured scope. A mismatch is an isolation
     *    violation.
     * A connection failure, non-2xx, or isolation mismatch returns false (→ non-zero
     * exit) — this probe certifies the enablement, so unlike the offline warns it FAILS.
     *
     * @param  list<AgentConfig>  $configs
     */
    private function probeBoardToolsEndpoint(array $configs, string $endpoint): bool
    {
        $enabled = array_values(array_filter($configs, fn (AgentConfig $c) => $c->boardTools !== null && $c->boardTools->enabled));
        if ($enabled === []) {
            $this->warn('board_tools probe: --probe-tools was given but no agent has an enabled board_tools block — nothing to probe.');

            return true;   // nothing to certify is not a failure
        }

        $ok = true;
        foreach ($enabled as $cfg) {
            $bt = $cfg->boardTools;
            $name = $cfg->agentName;
            // F6 (card 4952): --probe-tools is the HTTP loopback probe. An ssh-transport
            // agent has no bearer/endpoint to exercise here — silently skipping it would
            // certify NOTHING (canon #9). Name the right probe instead of a false OK.
            if ($bt !== null && $bt->transport === 'ssh') {
                $this->warn("board_tools probe: agent {$name}: uses the ssh transport — --probe-tools (HTTP) cannot certify it; run --probe-tools-ssh=<user@host> instead.");

                continue;
            }
            if ($bt === null || $bt->tokenPath === null) {
                continue;   // defensive: an enabled HTTP agent ⇒ tokenPath non-null by construction (ssh agents handled above)
            }
            // An enabled agent whose bearer can't be presented IS a broken enablement —
            // the probe certifies before the operator flips traffic on, so these fail
            // (unlike the offline checks, which only warn).
            try {
                $token = SecretFile::read($bt->tokenPath);
            } catch (Throwable $e) {
                $this->error("board_tools probe: agent {$name}: bearer not readable — {$e->getMessage()} (chmod 600); cannot certify this agent.");
                $ok = false;

                continue;
            }
            if ($token === null) {
                $this->error("board_tools probe: agent {$name}: no bearer at {$bt->tokenPath} — run bridge:provision-tools; cannot certify this agent.");
                $ok = false;

                continue;
            }

            try {
                $resp = Http::withToken($token)->acceptJson()->timeout(10)
                    ->post($endpoint, ['tool' => 'board_my_cards', 'args' => (object) []]);
            } catch (ConnectionException $e) {
                $this->error("board_tools probe: agent {$name}: could NOT connect to {$endpoint} ({$e->getMessage()}) — the bridge vhost/endpoint is wrong or not answering. Verify the channel server's BRIDGE_TOOLS_ENDPOINT and that the bridge vhost serves /agent-tools/call.");
                $ok = false;

                continue;
            }

            $status = $resp->status();
            if ($status === 403) {
                $this->error("board_tools probe: agent {$name}: {$endpoint} → 403 (loopback gate refused). The request's TCP peer is not loopback — an https://<public-host>/… endpoint makes the kernel pick the box's PUBLIC IP as the source. Use the /etc/hosts recipe (127.0.0.1 <bridge-hostname> + BRIDGE_TOOLS_ENDPOINT=https://<bridge-hostname>/agent-tools/call) — see docs/board-tools.md § Same-box enablement.");
                $ok = false;

                continue;
            }
            if ($status === 401) {
                $this->error("board_tools probe: agent {$name}: {$endpoint} → 401 (bearer rejected). The presented token resolves to no agent — verify the bearer at {$bt->tokenPath} matches what the channel server presents (BRIDGE_TOOLS_TOKEN / _FILE), and that it does not collide with another agent's.");
                $ok = false;

                continue;
            }
            if (! $resp->successful()) {
                $this->error("board_tools probe: agent {$name}: {$endpoint} → HTTP {$status} — the tool call did not succeed ({$this->probeErrorDetail($resp)}).");
                $ok = false;

                continue;
            }

            $result = $resp->json('result');
            if (! is_array($result)) {
                $this->error("board_tools probe: agent {$name}: 200 but the response carries no `result` object — cannot confirm board_my_cards ran ({$this->probeErrorDetail($resp)}).");
                $ok = false;

                continue;
            }
            $gotBoard = is_numeric($result['board_id'] ?? null) ? (int) $result['board_id'] : null;
            $gotSwimlane = is_numeric($result['swimlane_id'] ?? null) ? (int) $result['swimlane_id'] : null;
            if ($gotBoard !== $bt->boardId || $gotSwimlane !== $bt->swimlaneId) {
                $this->error("board_tools probe: agent {$name}: ISOLATION MISMATCH — board_my_cards returned board_id=".($gotBoard ?? 'null').' swimlane_id='.($gotSwimlane ?? 'null').", but this agent is configured for board {$bt->boardId} / swimlane {$bt->swimlaneId}. The window is not scoped to the configured lane.");
                $ok = false;

                continue;
            }
            $stageGroups = is_array($result['cards_by_stage'] ?? null) ? count($result['cards_by_stage']) : 0;
            $this->info("board_tools probe: agent {$name}: {$endpoint} → 200; window scoped to board {$bt->boardId} / swimlane {$bt->swimlaneId} ({$stageGroups} stage group(s)). board_my_cards does not expose a per-row swimlane, so the returned scope header matching config is the observable that the bridge-side isolation filter ran.");
        }

        return $ok;
    }

    private function probeErrorDetail(Response $resp): string
    {
        $error = $resp->json('error');

        return is_string($error) && $error !== '' ? "error: {$error}" : 'body: '.substr($resp->body(), 0, 200);
    }

    /**
     * card#4183 (DL-196): "event follows consumer". Per `github:<scope>`, WARN when
     * a top-level event type has been RECEIVED (in `webhook_events`, provider
     * github) but no enabled classifier of any agent subscribed to that scope
     * consumes it — the event arrives and is silently dropped. WARN, never
     * error/fail (a hygiene smell, not a broken install), consistent with every
     * advisory in this command.
     *
     * Structurally the sibling of the orphaned-writeback-mapping check: both ask
     * "is there classifier code that activates this config artifact?", here of a
     * subscribed/arriving event. The observed set is the bridge's OWN inbound
     * history (no GitHub hooks-API call — the least-privilege reconcile token can't
     * read `/repos/{repo}/hooks`; §3 of the design). That history is unbounded
     * until pruned — retention is event-gated (DL-199) or manual, so a single
     * long-remediated stray can WARN indefinitely. The WARN therefore carries the
     * occurrence count + last-seen timestamp (card #4321): an old last-seen is
     * remediated history, a fresh one is live drift — WITHOUT bounding the set by
     * a recency window, which would let rare-but-real drift older than the window
     * read CLEAN and invert the check's false-clean-impossible invariant.
     *
     * Fail-soft: wrapped so a DB hiccup can never throw out of `bridge:check`;
     * emits only `warn`/`info`/`unvalidated`, never `error`. An empty
     * `webhook_events` for a scope ⇒ no warns (nothing has been dropped yet —
     * correct). A SKIPPED run (the catch) is `unvalidated`, not green: the
     * advisory did not run, and card 5170 is the ruling that a check which did
     * not run is never reported as one that passed.
     *
     * @param  array<string, list<array{agent:string, class:string, consumed:list<string>, declared:bool}>>  $githubScopeConsumers
     */
    private function checkEventFollowsConsumer(array $githubScopeConsumers): void
    {
        if ($githubScopeConsumers === []) {
            return;
        }

        try {
            foreach ($githubScopeConsumers as $scope => $consumers) {
                // observed: top-level event types actually received for this scope
                // (normalized off the `.action` suffix — webhook_events stores
                // `pull_request.opened`), each with its occurrence count + last-seen
                // (the datum separating remediated history from live drift, #4321).
                /** @var array<string, array{count: int, last: string}> $observed */
                $observed = [];
                // Per-full-type rows are RETAINED (card #4354): the action inventory
                // below needs the pre-collapse `issues.closed`-granularity counts the
                // top-level projection destroys.
                /** @var array<string, array<string, array{count: int, last: string}>> $observedActions */
                $observedActions = [];   // top-level => action => {count,last}
                $rows = WebhookEvent::query()
                    ->where('provider', 'github')
                    ->where('scope_id', (string) $scope)
                    ->groupBy('event_type')
                    ->selectRaw('event_type, COUNT(*) as occurrences, MAX(received_at) as last_seen')
                    ->toBase()
                    ->get();
                foreach ($rows as $row) {
                    $eventType = is_string($row->event_type ?? null) ? $row->event_type : '';
                    if ($eventType === '') {
                        continue;
                    }
                    $parts = explode('.', $eventType, 2);
                    $top = $parts[0];
                    // Seconds precision: received_at is timestamp(3) and MariaDB's
                    // MAX() returns the fractional part while SQLite returns the
                    // stored string — trim to the driver-independent 19 chars.
                    $last = is_scalar($row->last_seen ?? null) ? substr((string) $row->last_seen, 0, 19) : '';
                    $count = (int) ($row->occurrences ?? 0);
                    $prev = $observed[$top] ?? ['count' => 0, 'last' => ''];
                    $observed[$top] = [
                        'count' => $prev['count'] + $count,
                        'last' => max($prev['last'], $last),
                    ];
                    // Actionless types (`push`) never enter the action inventory —
                    // there is no action to compare (card #4354 design, edge 7a).
                    if (isset($parts[1]) && $parts[1] !== '') {
                        $observedActions[$top][$parts[1]] = ['count' => $count, 'last' => $last];
                    }
                }
                if ($observed === []) {
                    continue;   // nothing arrived → nothing dropped (not a false clean)
                }

                // consumed: union across EVERY enabled classifier subscribed to the
                // scope (not one-per-scope — the AIMLA case, two agents on one scope).
                // A declaration may be BARE (`issues` — the type is owned, all actions
                // covered) or QUALIFIED (`issues.opened`, card #4354). The WARN compare
                // PROJECTS qualified entries to their top level, so WARN semantics are
                // unchanged for every conforming install (bare-only declarations are
                // the identity under projection); qualified-only coverage additionally
                // feeds the action inventory below.
                $consumed = [];        // top-level projection (WARN compare)
                $bareConsumed = [];    // top-level types declared BARE by some consumer
                $qualifiedActions = []; // top-level => action => true (union)
                $undeclared = [];   // classifiers with no DeclaresConsumedEvents (disambiguation)
                foreach ($consumers as $c) {
                    foreach ($c['consumed'] as $eventType) {
                        $parts = explode('.', $eventType, 2);
                        $consumed[$parts[0]] = true;
                        if (isset($parts[1]) && $parts[1] !== '') {
                            $qualifiedActions[$parts[0]][$parts[1]] = true;
                        } else {
                            $bareConsumed[$parts[0]] = true;
                        }
                    }
                    if (! $c['declared']) {
                        $undeclared[$c['class'].' (agent '.$c['agent'].')'] = true;
                    }
                }

                // Action inventory (card #4354, INFO — deliberately NEVER a warn):
                // GitHub has no per-action unsubscribe, and deliberately-unhandled
                // actions are the majority class, so an action-level ALARM would train
                // operators to ignore the check. One aggregated line per scope+type,
                // only where the type is consumed ONLY via qualified declarations
                // (a bare declaration means the type is owned — all actions covered).
                foreach ($observedActions as $top => $actions) {
                    if (! isset($consumed[$top]) || isset($bareConsumed[$top])) {
                        continue;   // unconsumed types WARN below; bare-owned types are covered
                    }
                    $unlisted = array_diff_key($actions, $qualifiedActions[$top] ?? []);
                    if ($unlisted === []) {
                        continue;
                    }
                    uasort($unlisted, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
                    $detail = implode(', ', array_map(
                        static fn (string $action, array $d): string => "{$action} ({$d['count']}x, last ".($d['last'] !== '' ? $d['last'].' UTC' : 'unknown').')',
                        array_keys($unlisted),
                        array_values($unlisted),
                    ));
                    $caveat = $undeclared !== [] ? ' An undeclared classifier on this scope may consume some of these (possible false inventory).' : '';
                    $this->info("event-consumer: github:{$scope} '{$top}' actions observed but not action-declared by any family: {$detail} — arrived-and-dropped at the action level (informational; the type itself is consumed).{$caveat}");
                }

                $unconsumed = array_values(array_diff(array_keys($observed), array_keys($consumed)));
                if ($unconsumed === []) {
                    continue;
                }

                // Disambiguation (sola's #22): an undeclared classifier on the scope
                // MIGHT consume the event without declaring it, so a warn below may be
                // a false positive — say so, keeping it actionable. Moot for the
                // reference classifiers (all declare); matters only for custom impls.
                foreach (array_keys($undeclared) as $desc) {
                    $this->warn("event-consumer: scope github:{$scope} has an enabled classifier {$desc} that does not declare its consumed events (App\\Bridge\\Contracts\\DeclaresConsumedEvents) — the following unconsumed-event WARNING(s) MAY be a false positive if that classifier actually consumes them");
                }

                $subscribed = implode(', ', array_values(array_unique(array_map(
                    static fn (array $c): string => $c['agent'],
                    $consumers,
                ))));
                foreach ($unconsumed as $eventType) {
                    $count = $observed[$eventType]['count'];
                    $last = $observed[$eventType]['last'] !== '' ? $observed[$eventType]['last'].' UTC' : 'unknown';
                    $this->warn("event-consumer: github:{$scope} has received '{$eventType}' ({$count}x, last {$last}) but no enabled classifier consumes it — the event is silently dropped on arrival (agent(s) subscribed: {$subscribed}). A last-seen predating your subscription fix is remediated history, not live drift. Add a consuming family, or drop '{$eventType}' from the subscription via coord:setup-bridge.");
                }
            }
        } catch (Throwable $e) {
            // Fail-soft: this advisory must never break the install check. But a
            // skipped advisory is a check that did NOT run, so it goes through the
            // `unvalidated` vocabulary (card 5170) rather than rendering green via
            // info() and vanishing from the tally. The bool is discarded on purpose:
            // `unvalidated` never returns false, so the RETURN VALUE cannot flip $ok.
            // That is the whole of the by-construction claim — rendering is not part
            // of it: line() throws on a message carrying an invalid formatter tag,
            // exactly as the info() here before it did, and that escape is unchanged.
            $this->emitFinding(Finding::unvalidated('event-consumer: check skipped — '.$e->getMessage()));
        }
    }

    /**
     * Warn (not fail) when a secret-holding dir is group/world-accessible (DL-014).
     * On a multi-tenant host these dirs must be owner-only (0700); a co-tenant who
     * can traverse one can read the HMAC secrets / tokens in it. Warn, not fail —
     * perms are operator-owned and the per-secret 0600 gate (DL-010) is the hard
     * backstop enforced fail-closed at point-of-use regardless of dir perms.
     */
    private function warnIfDirInsecure(string $label, string $dir): void
    {
        clearstatcache(true, $dir);
        $perms = fileperms($dir);
        if ($perms !== false && ($perms & 0o077) !== 0) {
            $this->warn(sprintf('%s %s is group/world-accessible (mode %04o) — chmod 700 (it holds secrets)', $label, $dir, $perms & 0o777));
        }
    }
}
