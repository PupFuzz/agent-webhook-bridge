<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Contracts\DeclaresConsumedEvents;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Retention\RetentionConfig;
use App\Bridge\Retention\RetentionGate;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\ChannelToken;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Support\InstallGuard;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SecretPath;
use App\Bridge\Support\SignalAllowlist;
use App\Bridge\Support\TokenPath;
use App\Bridge\Support\UrlValidator;
use App\Bridge\Validation\EndpointValidationException;
use App\Bridge\Validation\LocalhostUrl;
use App\Bridge\Writeback\AlertChannel;
use App\Bridge\Writeback\CoordConfigTerminals;
use App\Bridge\Writeback\GitHubRepoProbe;
use App\Bridge\Writeback\GitHubRepoProbeKind;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Validate the install: config/secret dirs, DB connectivity, and that every
 * per-agent YAML parses. Run before going live (and in the cutover runbook).
 */
class CheckCommand extends BridgeCommand
{
    protected $signature = 'bridge:check';

    protected $description = 'Validate the bridge install config (dirs, DB connectivity, agent YAMLs)';

    /**
     * Whether the RECEIVER's PHP can end a request before running terminating
     * callbacks. `bridge:check` is CLI, where fastcgi_finish_request is never
     * defined, so asking about THIS process would warn on every healthy FPM install.
     * The receiver's SAPI is what matters, and php-fpm ships the function iff the
     * FPM SAPI is built — so probe the fpm binary's own module list.
     */
    private function receiverSapiFinishesEarly(): bool
    {
        if (function_exists('fastcgi_finish_request')) {
            return true;   // running under FPM already
        }
        foreach (['php-fpm'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, 'php-fpm'] as $bin) {
            $path = (new ExecutableFinder)->find($bin);
            if ($path !== null) {
                return true;
            }
        }

        return false;
    }

    public function handle(): int
    {
        $ok = true;

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

        try {
            DB::connection()->getPdo();
            $this->info('database: connected');
        } catch (Throwable $e) {
            $this->error('database: '.$e->getMessage());
            $ok = false;
        }

        if (($crosstalk = InstallGuard::dsnCrosstalk()) !== null) {
            $this->error($crosstalk);
            $ok = false;
        } else {
            $this->info('install-suffix DSN check: ok');
        }

        try {
            BridgePaths::validateInboxConfig();
            $this->info('inbox surfacing config: ok (layout='.BridgePaths::inboxLayout().')');
        } catch (Throwable $e) {
            $this->error('inbox surfacing config: '.$e->getMessage());
            $ok = false;
        }

        // Retention (DL-199) is on by default and runs off the receiver, so a bad
        // window is silent: the stores just grow, which is the exact DL-012 failure
        // this replaced. Report the posture rather than let it go unnoticed.
        $retention = RetentionConfig::fromConfig();
        if (! $retention->enabled) {
            $this->warn('retention: DISABLED (BRIDGE_RETENTION_ENABLED=false) — nothing prunes webhook_events/agent_dispatches/inbox lines unless you schedule bridge:prune yourself; the append-only stores grow without bound (DL-012/DL-199).');
        } elseif (! $retention->isUsable()) {
            $this->warn('retention: enabled but MISCONFIGURED — '.$retention->problem.'. Nothing is pruned (a bad window never falls back to a default cutoff). The stores grow until fixed.');
        } else {
            $this->info('retention: on ('.$retention->summary().')');
            // The whole no-latency claim rests on the response being FINISHED before
            // the terminating callback runs. Under PHP-FPM that is
            // fastcgi_finish_request(); without it (mod_php) Symfony only flushes, so
            // a keep-alive client can sit through the prune. The prune stays correct
            // either way — this degrades latency, silently, which is why it is worth
            // one preflight line. `bridge:check` runs on CLI, where the function is
            // absent by definition, so probe the configured SAPI, not this process.
            if (! $this->receiverSapiFinishesEarly()) {
                $this->warn('retention: this PHP install has no fastcgi_finish_request() — retention runs AFTER the response is flushed but BEFORE the request ends, so a keep-alive client may wait for it. Serve the receiver under PHP-FPM (see CLAUDE_DEPLOYMENT.md), or set BRIDGE_RETENTION_ENABLED=false and run bridge:prune on a schedule.');
            }
            // Config being valid does NOT mean retention is RUNNING. A pass that throws
            // (unwritable inbox, ENOSPC, a DB fault) backs off a full interval and drains
            // nothing, leaving the posture line above reading healthy — the DL-012 blind
            // spot. The gate records its last throw here; surface it (cleared automatically
            // on the next successful pass). Wrapped like every other advisory: a broken
            // cache backend must degrade to a note, never throw out of the preflight.
            try {
                $lastError = Cache::get(RetentionGate::ERROR_KEY);
                if (is_array($lastError)) {
                    // Deliberately does NOT assert the stores are growing: on a since-quieted
                    // install nothing arrives, so nothing grows — the marker can outlive the
                    // condition (webhook-driven clear, ≤30d TTL). State the fact (last pass
                    // failed, nothing pruned since) and let the timestamp speak.
                    $this->warn('retention: the LAST PASS FAILED and nothing has pruned since ('
                        .($lastError['exception'] ?? 'error').': '.($lastError['error'] ?? '')
                        .' at '.($lastError['at'] ?? '?').'). Check DB/file permissions and disk space; if traffic has since resumed, watch the log for a clean `retention pass` (the marker clears itself on the next success).');
                }
            } catch (Throwable $e) {
                $this->warn('retention: could not read the last-failure marker ('.$e->getMessage().') — the cache backend the retention gate depends on may be unreachable.');
            }
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
        // github scopes (repo full_names) covered by SOME agent running a
        // writeback-emitting classifier — used to flag orphaned writeback
        // mappings below (#2162). Keyed by scope for O(1) lookup.
        $writebackEmittingScopes = [];
        // DL-204 (#4357): scopes where an agent enables the coord-card-move family (gate 1
        // of the MOVE leg; gate 2 is the writeback mapping's move_coord_cards). Keyed by
        // scope, used to scope the fleet-default nudges so bridge:check only speaks about the
        // move leg where it can actually fire (family-on) rather than where the writeback
        // default alone resolved move_coord_cards true.
        $coordCardMoveScopes = [];
        // github scope (repo full_name) => list of the agents subscribed to it and
        // the top-level event types each CONSUMES, for the event-follows-consumer
        // check below (card#4183 / DL-196). Multiple agents can subscribe one scope
        // (the bridge dispatches each event to all of them), so consumed is the
        // union over all of them — hence a list per scope, not one entry.
        // Shape: scope => list<array{agent:string, class:string, consumed:list<string>, declared:bool}>.
        $githubScopeConsumers = [];
        $hasSecretDir = is_string($secretDir) && str_starts_with($secretDir, '/');
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

                // The classifier FQCN is only resolved at dispatch time, where a
                // bad value is an uncaught 5xx (→ upstream retry storm). Validate
                // it here so a typo / stale signature surfaces as a preflight
                // failure instead. Probe OUT OF PROCESS first — an out-of-date
                // classify() signature is an uncatchable E_COMPILE_ERROR that would
                // otherwise kill bridge:check ITSELF (#2053); the subprocess
                // isolates the load. Only once it passes is for() safe to call here.
                if (($err = ClassifierResolver::probeLoadable($cfg->classifierClass)) !== null) {
                    $this->error("agent {$name}: {$err}");
                    $ok = false;

                    continue;
                }
                try {
                    ClassifierResolver::for($cfg);
                } catch (Throwable $e) {
                    $this->error("agent {$name}: ".$e->getMessage());
                    $ok = false;

                    continue;
                }

                $this->info("agent config ok: {$name}");

                // Record which github scopes this agent DRIVES the writeback for:
                // its classifier must emit writeback reactions (#2162). Detected
                // out-of-process (DL-025) — probeLoadable already passed above, so
                // this child loads cleanly. Used after the loop to flag orphaned
                // writeback.json mappings (a mapping no classifier drives).
                if (ClassifierResolver::probeImplements($cfg->classifierClass, EmitsWritebackReactions::class)) {
                    foreach ($cfg->subscriptions as $sub) {
                        if ($sub->provider === 'github') {
                            $writebackEmittingScopes[$sub->scopeId] = true;
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
                            $coordCardMoveScopes[$sub->scopeId] = true;
                        }
                    }
                }

                // card#4183 (DL-196): record the top-level github event types this
                // agent's classifier CONSUMES per subscribed github scope, for the
                // event-follows-consumer check after the loop. DL-025-safe, mirroring
                // the orphan check above: probeImplements is OUT OF PROCESS; the
                // consumedEventTypes() call is on the instance for() already resolved
                // in-process (line ~172, after probeLoadable passed), wrapped in
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

                // DL-197: the impl-ci-wake CI-FAILURE name filter INVERTS the family's
                // fail-loud posture — a set-but-non-matching filter (a typo, or a
                // workflow later renamed) silently blackholes EVERY CI-failure wake for
                // the scope, with no inbox trace under the default `drop`. Config
                // validation can't catch a well-formed-but-stale pattern, so surface the
                // configured patterns at preflight for eyeball verification against real
                // workflow names. Warn-never-fail (the filter is a deliberate opt-in).
                // `ci_failure_workflow_patterns` is a LAZY config key (not eagerly
                // parsed in AgentConfig::load, unlike families/scope_author_map), so a
                // malformed value (non-list / blank entry) first throws HERE. The
                // classify path would 5xx on it at runtime — catch it per-agent (like
                // the DL-196 block above) so one bad value surfaces cleanly instead of
                // aborting the whole check + skipping every remaining agent.
                try {
                    $failureFilter = $cfg->classifierConfig->strings('ci_failure_workflow_patterns');
                    if ($failureFilter !== [] && in_array('impl-ci-wake', $cfg->classifierConfig->strings('families'), true)) {
                        $this->warn("agent {$name}: classifier.config.ci_failure_workflow_patterns = [".implode(', ', $failureFilter).'] — the impl-ci-wake CI-FAILURE wake fires ONLY for workflow_run names containing one of these (case-insensitive substring); a failure of any OTHER workflow on a subscribed scope will NOT wake. Verify these match your intended workflow names — a typo or a renamed workflow silences every failure wake.');
                    }
                } catch (Throwable $e) {
                    $this->error("agent {$name}: classifier.config.ci_failure_workflow_patterns — ".$e->getMessage());
                    $ok = false;
                }

                // DL-213 (#4632): comment_to is now in the wake_membership fleet DEFAULT.
                // The flip only reaches installs with NO explicit wake_membership — an
                // install that set it explicitly before the flip OVERRIDES the default, so
                // its directed-reply wakes stay dark, and the flip must not silently rewrite
                // a deliberate operator config. Surface exactly that population (coord-message
                // on + explicit wake_membership + comment_to omitted); an absent-key install
                // needs no warn (the default now covers it). wake_membership is lazily parsed
                // (like ci_failure_workflow_patterns above), so a malformed value first throws
                // here — catch it per-agent rather than aborting the whole check.
                $families = $cfg->classifierConfig->strings('families');
                $coordMessageOn = $families === [] || in_array('coord-message', $families, true);
                if ($coordMessageOn && $cfg->classifierConfig->has('wake_membership')) {
                    try {
                        $membership = $cfg->classifierConfig->strings('wake_membership');
                        if (! in_array('comment_to', $membership, true)) {
                            $this->warn("agent {$name}: classifier.config.wake_membership = [".implode(', ', $membership)."] is set explicitly and omits comment_to — a counterparty's comment addressed TO you on a thread you neither opened nor were labelled on will NOT live-wake you (the common post-a-reply-and-wait flow). comment_to is now in the fleet default; add it to your explicit list to catch directed replies, or leave it off to keep them dark deliberately.");
                        }
                    } catch (Throwable $e) {
                        $this->error("agent {$name}: classifier.config.wake_membership — ".$e->getMessage());
                        $ok = false;
                    }
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
            }
        }

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
        // is fail-closed (load throws) — catch it as a preflight failure. When
        // present, warn if the dedicated writeback token is missing/insecure or
        // the writeback identity is unset (the resulting card_updated would loop).
        if (is_string($configDir) && is_file(rtrim($configDir, '/').'/writeback.json')) {
            try {
                $writeback = WritebackConfig::load($configDir);
                $count = $writeback !== null ? count($writeback->mappings) : 0;
                $this->info("writeback.json: {$count} repo mapping(s)");
                if ($writeback !== null && $writeback->identityId === null) {
                    $this->warn('writeback.json: no identity_id — set it so the writeback card_updated webhook is auto echo-suppressed (else it loops back)');
                }
                if ($writeback !== null && $writeback->alertChannel !== null) {
                    $this->checkAlertChannel($writeback->alertChannel);
                }
                if ($hasSecretDir && $writeback !== null && $writeback->mappings !== []) {
                    $tokenPath = TokenPath::forWriteback((string) $secretDir, 'kanban');
                    if (! is_file($tokenPath)) {
                        $this->warn("writeback: no kanban writeback token at {$tokenPath} — the move will fail until you place a least-privilege token (chmod 600)");
                    } elseif (SecretFile::isInsecure($tokenPath)) {
                        $this->warn('writeback: '.SecretFile::permsMessage($tokenPath).' — the move will fail until fixed');
                    }

                    // reconcile (bridge:reconcile) resolves + probes a GitHub read
                    // token PER REPO. Run the SAME shared GitHubRepoProbe (DL-185/186)
                    // so bridge:check can't drift from what reconcile resolves OR from
                    // how it classifies a failure — one resolve+probe+hint table, two
                    // error postures (reconcile errors + skips; check warns). Warn
                    // (never fail, DL-026) for any mapped repo whose token doesn't
                    // resolve, or whose probe fails auth/scope. A resolved-but-invalid
                    // token (DL-186) — classically a stale <secret_dir>/github/token
                    // that SHADOWS the store map — resolves but 401s every repo at
                    // reconcile time, so the probe surfaces the shadow at preflight,
                    // naming the resolved leg, not on the first run. A network blip is
                    // NOT a token problem → stay silent on it; the event-driven
                    // writeback is unaffected regardless.
                    $probe = new GitHubRepoProbe;
                    foreach ($writeback->mappings as $repo => $mapping) {
                        $result = $probe->probe((string) $repo);
                        switch ($result->kind) {
                            case GitHubRepoProbeKind::Unresolvable:
                                $this->warn("reconcile: {$repo}: {$result->problem} — bridge:reconcile will FAIL for this repo until you place a read-only token (chmod 600), map it in the coordination store's [git-credential-map], or export GH_TOKEN; the event-driven writeback is unaffected");
                                break;
                            case GitHubRepoProbeKind::Http:
                                $this->warn("reconcile: {$repo}: token from {$result->source} → HTTP {$result->status}{$result->hint} — bridge:reconcile will SKIP this repo. If the source is a <secret_dir>/github/token or BRIDGE_GITHUB_TOKEN_PATH file, it SHADOWS the [git-credential-map] store (a stale single-token-era file is the common upgrade cause) — remove it so each repo resolves its own store token.");
                                break;
                            case GitHubRepoProbeKind::Ok:
                            case GitHubRepoProbeKind::Network:
                                // Valid, or a network blip (not a token-validity signal) — nothing to warn.
                                break;
                        }
                    }
                }

                // Probe that the writeback token can actually SEE each mapped
                // board. A token whose user lost board membership (or a drifted
                // board_id) gets a 200 with 0 cards — NOT an HTTP error — so the
                // writeback silently no-ops every move (or duplicates a dependabot
                // card). Catch that degraded-but-not-erroring state HERE, at config
                // time. All warn-level: a temporarily-unreachable kanban or a
                // genuinely-empty new board must not FAIL the install check (DL-026).
                if ($writeback !== null && $writeback->mappings !== []) {
                    // #2162: a writeback.json mapping is INERT unless some agent runs
                    // a writeback-emitting classifier subscribed to its github scope.
                    // INDEPENDENT of the network probe below — it must fire even when
                    // the writeback client can't be constructed (no token / base URL),
                    // which is exactly the half-configured install where an orphan is
                    // most likely. Reads only the in-memory emitting-scope set.
                    foreach ($writeback->mappings as $repo => $mapping) {
                        if (! isset($writebackEmittingScopes[$repo])) {
                            $this->warn("writeback: mapping for {$repo} is ORPHANED — no agent runs a writeback-emitting classifier (App\\Bridge\\Contracts\\EmitsWritebackReactions) subscribed to github:{$repo}; the mapping is inert (no card will ever move) until an agent subscribes to it with that classifier");
                        }
                        // #2652: the DL-160 branch-create `started` trigger is fail-closed —
                        // it needs BOTH `stages.started` AND `started_from_stages`. With
                        // exactly one set the move is silently INERT (the `stages.started`-only
                        // half is refused for lack of a promote-from set; the
                        // `started_from_stages`-only half has no `started` outcome to fire).
                        // Config-only, no board read — fires even on a half-configured install.
                        $hasStartedStage = $mapping->stageFor('started') !== null;
                        $hasStartedFrom = $mapping->startedFromStages !== null && $mapping->startedFromStages !== [];
                        if ($hasStartedStage !== $hasStartedFrom) {
                            $present = $hasStartedStage ? 'stages.started' : 'started_from_stages';
                            $missing = $hasStartedStage ? 'started_from_stages' : 'stages.started';
                            $this->warn("writeback: mapping for {$repo} sets {$present} but not {$missing} — the branch-create `started` trigger (DL-160) needs BOTH and is silently INERT (never fires) until {$missing} is set");
                        }
                        // DL-195: Won't-Do-revival needs BOTH stages.opened (the revive-to target)
                        // AND stages.closed_unmerged (the abandon stage the revival is scoped from).
                        // With revive_on_reopen on but either missing, a reopened PR's revival is
                        // silently INERT (no target to revive to, or no abandon stage to scope the
                        // carve-out). Config-only, no board read — fires on a half-configured install.
                        if ($mapping->reviveOnReopen) {
                            $missingRevive = [];
                            if ($mapping->stageFor('opened') === null) {
                                $missingRevive[] = 'stages.opened';
                            }
                            if ($mapping->stageFor('closed_unmerged') === null) {
                                $missingRevive[] = 'stages.closed_unmerged';
                            }
                            if ($missingRevive !== []) {
                                $this->warn("writeback: mapping for {$repo} sets revive_on_reopen but not ".implode(' / ', $missingRevive).' — Won\'t-Do-revival (DL-195) needs BOTH stages.opened (revive-to) and stages.closed_unmerged (abandon stage) and is silently INERT until set');
                            }
                        }
                        // DL-198: a created coord card's task.created webhook would echo
                        // back to the bridge; only the global-echo gate (identity_id)
                        // stops it from self-waking a kanban-triage session. With no
                        // identity_id, that guard is absent — surface the concrete hazard
                        // (the generic no-identity_id warn above doesn't name it). Config
                        // -only; the missing-stage half is already fail-closed at load.
                        if ($mapping->createCoordCards && $writeback->identityId === null) {
                            $this->warn("writeback: mapping for {$repo} sets create_coord_cards but writeback.json has no identity_id — a created coord card's task.created webhook echoes back and could self-wake a kanban-triage session; set identity_id (the global-echo gate is the sole guard).");
                        }
                        // #4553: under population=all the bridge is the SOLE real-time mover for the
                        // NON-PREFIXED coord-issue set — the prefix/tag-keyed reconcile ignores those
                        // issues, so unless the consumer extends its reconcile to correlate them by
                        // github_issue by-ref, a bridge-missed non-prefixed event self-heals NOWHERE.
                        // Surface it so the DL-200 terminal-"agree" line is never misread as backstop
                        // coverage for this population (prefixed issues stay backstopped via the id: tag).
                        // Gated on create OR move — the move leg (create off) also correlates non-prefixed
                        // cards by-ref, so it carries the same backstop + config-agreement stake.
                        if (($mapping->createCoordCards || $mapping->moveCoordCards) && $mapping->issuePopulation === WritebackMapping::POPULATION_ALL) {
                            $this->warn("writeback: issue_population=all for {$repo} — the bridge is the SOLE real-time mover for NON-PREFIXED coord issues (the prefix/tag-keyed reconcile does not card them). Ensure the consumer's reconcile is extended to correlate non-prefixed issues by github_issue by-ref, else a bridge-missed non-prefixed event has NO backstop. Prefixed issues remain backstopped via the shared id: tag.");
                            // by-ref correlation is only correct in `ref` mode — scan mode does a bare
                            // issue-number match with NO repo/source disambiguation, so on a multi-repo
                            // board it correlates the wrong repo's issue #N (skips a create / moves the
                            // wrong card). ref is the default; warn if an install pairs all with scan.
                            if (config('bridge.writeback.correlation', 'ref') !== 'ref') {
                                $this->warn("writeback: issue_population=all for {$repo} but BRIDGE_WRITEBACK_CORRELATION is not `ref` — the github_issue by-ref correlation degrades to a bare issue-number scan with NO repo disambiguation, so on a multi-repo board it can correlate the wrong repo's issue #N. Set correlation=ref (the default) for the `all` population.");
                            }
                            // The cross-config three-state compare (converged w/ sola): bind the bridge's
                            // runtime issue_population (writeback.json) to the reconcile's ($COORD_CONFIG),
                            // so bridge-on-all + reconcile-on-prefixed = a checkable DISAGREE, not silence.
                            $this->checkIssuePopulationAgreement($repo, $mapping);
                        }
                        // DL-204 (#4357): the move leg fires only where BOTH gates are on — the
                        // coord-card-move family (gate 1) AND the writeback mapping's move_coord_cards
                        // (gate 2, now a guarded fleet default: on where coord_card_terminal_stage_id
                        // is present, inert where absent). An install that enabled the family but never
                        // set the terminal gets issues.closed/reopened classified with NO card move —
                        // silent-inert, the exact death the fleet default's no-silent-inert clause
                        // targets. Nudge it (config-only, no board read), scoped to family-enabled
                        // scopes so a pure PR-writeback mapping stays quiet (DL-196 posture).
                        if (isset($coordCardMoveScopes[$repo]) && $mapping->coordCardTerminalStageId === null) {
                            $this->warn("writeback: github:{$repo} enables the coord-card-move family but its writeback mapping has no coord_card_terminal_stage_id — the real-time coord-issue close/reopen → card move (DL-200) is INERT (issues.closed/reopened are classified but no card moves). Set coord_card_terminal_stage_id (the fleet default activates the leg where it is present), or remove coord-card-move from classifier.config.families if the move leg is not wanted.");
                        }
                        // DL-204 MIRROR: the other silent-inert direction. Gate 2 on (move_coord_cards
                        // resolved true — explicitly, or by the terminal-present fleet default) but gate 1
                        // off (no agent runs the coord-card-move family on this scope): the handler-side
                        // gate is on, but the classifier never emits a move to hand it, so the leg is dead.
                        // This is exactly the adoption path DL-204 advertises ("set the terminal, no flag
                        // needed") dying when the operator sets the terminal but never enables the family —
                        // and it is the case the family-gate on the terminal-agreement compare above no
                        // longer surfaces. Config-only, no board read; terminal-absent installs can't reach
                        // it (moveCoordCards is false there), so a pure PR-writeback mapping stays quiet.
                        if ($mapping->moveCoordCards && ! isset($coordCardMoveScopes[$repo])) {
                            $this->warn("writeback: github:{$repo} has coord_card_terminal_stage_id set (the move leg is on — explicitly or by the DL-204 default) but no agent enables the coord-card-move family on that scope — the leg cannot fire (nothing classifies issues.closed/reopened into a move). Add coord-card-move to the serving agent's classifier.config.families, or remove coord_card_terminal_stage_id to disable the move leg.");
                        }
                        // DL-207: promote-on-release health. WritebackConfig::load already fails
                        // closed on a missing shipped/released stage, so here we catch the two
                        // silent-inert shapes load can't: (a) both stages mapped to ONE column
                        // (the promote is a no-op), and (b) no FPM-viable GitHub token. The
                        // promote leg runs in the webhook RUNTIME — unlike bridge:reconcile (CLI),
                        // under FPM GH_TOKEN is absent and the git-credential-coord store helper is
                        // CLI-only (DL-184), so ONLY a placed token FILE resolves there. There is no
                        // reconcile backstop for Shipped→Released, so an inert leg strands cards.
                        if ($mapping->promoteOnRelease) {
                            if ($mapping->stageFor('merged') !== null && $mapping->stageFor('merged') === $mapping->stageFor('merged_to_main')) {
                                $this->warn("writeback: mapping for {$repo} sets promote_on_release but stages.merged and stages.merged_to_main are the same stage — the Shipped→Released promote is a no-op (nothing to move); map them to distinct columns or remove promote_on_release.");
                            }
                            // Reuse the authoritative resolver; a file leg's `source` starts with
                            // "token file" / "token_path override" (mirrors GitHubTokenResolver).
                            $promoteToken = (new GitHubTokenResolver)->resolveFor((string) $repo);
                            $fromFile = $promoteToken->ok() && $promoteToken->source !== null
                                && (str_starts_with($promoteToken->source, 'token file') || str_starts_with($promoteToken->source, 'token_path override'));
                            if (! $fromFile) {
                                $this->warn("writeback: mapping for {$repo} sets promote_on_release but no GitHub read token resolves from a FILE (<secret_dir>/github/token, or providers.github.token_path) — the promote leg runs in the FPM webhook runtime where GH_TOKEN is absent and the credential-store helper is CLI-only, so a store/GH_TOKEN-only token (usable by bridge:reconcile) leaves the promote leg INERT at runtime with no reconcile backstop. Place a read-only token file (chmod 600).");
                            }
                        }
                    }

                    try {
                        $client = WritebackClientFactory::make();

                        // DL-031: `ref` is the default correlation mode — but a kanban
                        // that predates by-ref (< v0.17.2) would 404 EVERY correlation
                        // silently. Probe reachability once (instance-wide) against the
                        // first mapped board and warn loudly to set scan / upgrade kanban.
                        // Same defaulted read as WritebackClientFactory so the gate
                        // and the client's actual mode can never diverge (DL-031).
                        if (config('bridge.writeback.correlation', 'ref') === 'ref') {
                            $firstBoard = (int) array_values($writeback->mappings)[0]->boardId;
                            try {
                                if (! $client->byRefAvailable($firstBoard)) {
                                    $this->warn("writeback: correlation=ref but by-ref returned 404 on board {$firstBoard} — either this kanban predates by-ref (< v0.17.2) or board {$firstBoard} isn't accessible to the token; EVERY correlation will 404 and no card will move. Upgrade kanban / fix board_id+membership, or set BRIDGE_WRITEBACK_CORRELATION=scan");
                                } else {
                                    $this->info('writeback: by-ref reachable (correlation=ref)');
                                }
                            } catch (Throwable $e) {
                                $this->warn('writeback: could not probe by-ref reachability — '.$e->getMessage());
                            }
                        }

                        foreach ($writeback->mappings as $repo => $mapping) {
                            try {
                                // Cheap visibility probe (DL-029): one limit=1 read,
                                // preferring meta.total — independent of correlation mode.
                                $vis = $client->visibility($mapping->boardId);
                                if ($vis['total'] === 0) {
                                    // 0 cards is AMBIGUOUS on a 200 read: an empty board (no
                                    // cards created yet — fine) vs a non-member token (every
                                    // move silently no-ops). Don't assert membership on this
                                    // evidence alone — true inaccessibility surfaces separately
                                    // (the by-ref reachability probe above 404s for a
                                    // non-member board in `ref` mode). So present both.
                                    $this->warn("writeback: token sees 0 cards on board {$mapping->boardId} ({$repo}) — EITHER the board is empty (no cards yet → fine, the writeback works once cards exist) OR the token's user isn't a member / `board_id` is wrong (then every move silently no-ops). If you expect cards on that board, verify membership + `board_id`; a genuinely-empty board is not a problem.");
                                } elseif (! $vis['exact']) {
                                    // Pre-DL-146 kanban: confirmed non-blind, exact size unknown.
                                    $this->info("writeback: token can see board {$mapping->boardId} ({$repo}) (exact card count unavailable — kanban predates pagination meta)");
                                } else {
                                    $this->info("writeback: token sees {$vis['total']} card(s) on board {$mapping->boardId} ({$repo})");
                                    if (config('bridge.writeback.correlation', 'ref') !== 'ref' && $vis['total'] > KanbanClient::SEARCH_LIMIT * KanbanClient::MAX_PAGES) {
                                        $this->warn("writeback: board {$mapping->boardId} ({$repo}) has {$vis['total']} cards, beyond the scan ceiling — correlations beyond it will be missed; switch BRIDGE_WRITEBACK_CORRELATION=ref");
                                    }
                                }
                                // DL-027: a mapping's swimlane_id (created-card lane) must exist on
                                // its board, else card creation 422s and the handler SILENTLY no-ops
                                // (permanent-4xx). A static typo never self-resolves, so name it here.
                                if ($mapping->swimlaneId !== null) {
                                    if (! in_array($mapping->swimlaneId, $client->boardSwimlaneIds($mapping->boardId), true)) {
                                        $this->warn("writeback: swimlane_id {$mapping->swimlaneId} not found on board {$mapping->boardId} ({$repo}) — created cards will 422 and SILENTLY no-op until fixed (a deleted lane, or a lane on a different board)");
                                    } else {
                                        $this->info("writeback: swimlane_id {$mapping->swimlaneId} ok on board {$mapping->boardId} ({$repo})");
                                    }
                                }
                                // #2949: a create_dependabot_cards mapping's board MUST define every
                                // custom field the create payload sets (pr_number, pr_url, origin),
                                // else POST /tasks.json 422s on the unregistered key and the handler
                                // SILENTLY no-ops (permanent-4xx, DL-020) — the create path's twin of
                                // the DL-027 swimlane gap above. A static config/board mismatch never
                                // self-resolves, so surface it here (DL-026 "degraded must be loud").
                                if ($mapping->createDependabotCards) {
                                    $required = KanbanDependabotCardHandler::CREATE_PAYLOAD_KEYS;
                                    $present = $client->boardCustomFieldKeys($mapping->boardId);
                                    $missing = array_values(array_diff($required, $present));
                                    if ($missing !== []) {
                                        $this->warn("writeback: create_dependabot_cards is on for {$repo} but board {$mapping->boardId} is MISSING the custom field(s) ".implode(', ', $missing).' the create payload sets ('.implode(', ', $required).') — every dependabot-card create will 422 and SILENTLY no-op until they are registered (add them on the board, or set create_dependabot_cards=false)');
                                    } else {
                                        $this->info("writeback: create_dependabot_cards custom fields ok on board {$mapping->boardId} ({$repo})");
                                    }
                                }
                                // #4553: population=all correlates + creates by github_issue by-ref, which
                                // derives from the `issue_number` payload custom field. If the board does
                                // NOT register issue_number, kanban 422s every non-prefixed create as a
                                // PERMANENT no-op (silent), AND an empty by-ref pre-check is indistinguishable
                                // from a real no-match — so the bridge (the sole real-time mover for this
                                // population) would silently DOUBLE-CARD. FAIL-CLOSED (exit non-zero), not a
                                // warn: refuse to certify an install that would silently lose/duplicate cards.
                                // Gated on create OR move: the move leg (create off) also correlates
                                // non-prefixed cards by-ref, so it too 422s / silently no-ops without
                                // issue_number registered.
                                if (($mapping->createCoordCards || $mapping->moveCoordCards) && $mapping->issuePopulation === WritebackMapping::POPULATION_ALL) {
                                    // Read in its OWN try so a read failure fails CLOSED. This is the one
                                    // fail-closed check in this block (its siblings warn), so it must NOT be
                                    // swallowed by the per-mapping warn-catch below: a fail-closed invariant
                                    // we could not verify is a FAILURE, not a warn (DL-026 / canon #9 — an
                                    // unrun measurement is not a pass). A blind token / wrong board / transient
                                    // 5xx here therefore exits non-zero rather than certifying blind.
                                    try {
                                        $present = $client->boardCustomFieldKeys($mapping->boardId);
                                        if (! in_array('issue_number', $present, true)) {
                                            $this->error("writeback: issue_population=all for {$repo} but board {$mapping->boardId} does not register the 'issue_number' custom field — every non-prefixed coord-card create 422s as a permanent no-op AND by-ref correlation cannot tell 'not indexed' from 'no match', so the bridge would silently double-card. Register issue_number (+ issue_url for source) on the board, or set issue_population=prefixed.");
                                            $ok = false;
                                        } else {
                                            $this->info("writeback: issue_number custom field registered on board {$mapping->boardId} ({$repo}) — github_issue by-ref ready (issue_population=all)");
                                        }
                                    } catch (Throwable $e) {
                                        $this->error("writeback: issue_population=all for {$repo} but could NOT read board {$mapping->boardId}'s custom fields to verify issue_number registration — ".$e->getMessage().'. This fail-closed check must not be skipped (an unverifiable board could silently double-card); fix board access / board_id and re-run.');
                                        $ok = false;
                                    }
                                }
                                // #2652: every workflow stage id the mapping targets — each
                                // `stages.*` value plus the `started_from_stages` ids — must be a
                                // real stage on the board. A typo'd id makes the move 422 (the
                                // forward outcomes) or the `started`/no-regression guard silently
                                // never match. Same silent-misconfig class as the swimlane (DL-027)
                                // and dependabot-CF (DL-162) checks; cheap via boardStageOrder (DL-163).
                                $boardStageIds = array_keys($client->boardStageOrder($mapping->boardId));
                                if ($boardStageIds !== []) {   // empty ⇒ no stages read; don't false-warn
                                    $targets = array_values($mapping->stages);
                                    foreach ($mapping->startedFromStages ?? [] as $fromId) {
                                        $targets[] = $fromId;
                                    }
                                    // DL-194: the unpark_from_stages ids are read on the
                                    // `started` path too — a typo'd id makes the auto-unpark
                                    // guard silently never match (same class as above).
                                    foreach ($mapping->unparkFromStages ?? [] as $fromId) {
                                        $targets[] = $fromId;
                                    }
                                    // DL-198: the coord-card create stage — a typo'd id makes
                                    // every coord-card create 422 and silently no-op (same class).
                                    if ($mapping->coordCardStageId !== null) {
                                        $targets[] = $mapping->coordCardStageId;
                                    }
                                    // DL-200: the coord-card terminal — same class again (a typo'd
                                    // id 422s every close→terminal move and silently no-ops).
                                    if ($mapping->coordCardTerminalStageId !== null) {
                                        $targets[] = $mapping->coordCardTerminalStageId;
                                    }
                                    $unknownStages = array_values(array_unique(array_diff($targets, $boardStageIds)));
                                    if ($unknownStages !== []) {
                                        $this->warn("writeback: mapping for {$repo} references workflow stage id(s) ".implode(', ', $unknownStages)." not on board {$mapping->boardId} — those moves will 422 (or the started/no-regression guard will silently never match) until fixed");
                                    } else {
                                        $this->info("writeback: all mapped stage ids exist on board {$mapping->boardId} ({$repo})");
                                    }
                                }
                                // DL-200: the cross-config compare — the MANDATORY preflight that
                                // makes the move leg's bridge-owned terminal config legitimate. Gated
                                // on the coord-card-move family (gate 1): after the DL-204 default flip,
                                // move_coord_cards can resolve true from terminal-presence alone, so
                                // without this gate the compare would verify a terminal for a leg that
                                // cannot fire (family off) and read as though the leg were live.
                                if (isset($coordCardMoveScopes[$repo])) {
                                    $this->checkCoordTerminalAgreement($repo, $mapping, $client);
                                }
                            } catch (Throwable $e) {
                                $this->warn("writeback: could not read board {$mapping->boardId} ({$repo}) with the writeback token — ".$e->getMessage());
                            }
                        }
                        // #3399: in ref mode the correlation on a SHARED board is repo-qualified
                        // (passes the event's `source`), so there a dl_number card whose derived
                        // source is null (no pr_url) or matches no repo mapped to its board is
                        // EXCLUDED by the by-ref lookup and silently never self-moves — the one
                        // writeback failure that stays invisible. On a 1:1 board the qualifier is
                        // omitted (DL-174), so null-source cards correlate fine there.
                        if (config('bridge.writeback.correlation', 'ref') === 'ref') {
                            $this->checkWritebackSourceCoverage($writeback, $client);
                        }
                    } catch (Throwable $e) {
                        $this->warn('writeback: skipped board-visibility probe — '.$e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $this->error('writeback.json: '.$e->getMessage());
                $ok = false;
            }
        }

        // card#4183 (DL-196): event-follows-consumer. WARN (never fail) when a
        // github event type has ARRIVED for a scope but no enabled classifier
        // consumes it. Independent of writeback (a coord agent has no writeback).
        $this->checkEventFollowsConsumer($githubScopeConsumers);

        return $ok ? self::SUCCESS : self::FAILURE;
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
     * emits only `warn`/`info`, never `error`. An empty `webhook_events` for a
     * scope ⇒ no warns (nothing has been dropped yet — correct).
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
            // Fail-soft: this advisory must never break the install check.
            $this->info('event-consumer: check skipped — '.$e->getMessage());
        }
    }

    /**
     * #3399: on a ref-mode writeback the by-ref lookup on a SHARED board filters by the
     * event's repo `source`, which the kanban derives from a card's `pr_url`. There a dl_number
     * card with no pr_url (source=null), or a pr_url whose owner/repo matches no repo mapped to
     * that board, is EXCLUDED by the lookup and silently never self-moves — indistinguishable
     * from a legitimate no-match in the dispatch ledger. Warn (never fail) so it is named +
     * actionable (root cause closed by `kbcard --pr-url` + the on-ramp docs). On a NON-shared
     * board the qualifier is omitted (DL-174) so source=null is fine and not warned; a derived
     * source naming a repo NOT mapped to the board still warns everywhere (operator error).
     * Per board (deduped across mappings).
     */
    private function checkWritebackSourceCoverage(WritebackConfig $writeback, KanbanClient $client): void
    {
        // repos mapped to each board, canonicalized to match the kanban's derived source.
        $refs = new ExternalReferenceNormalizer;
        $reposByBoard = [];
        foreach ($writeback->mappings as $repo => $mapping) {
            $reposByBoard[$mapping->boardId][] = $refs->canonicalizeSource((string) $repo);
        }
        foreach ($reposByBoard as $boardId => $repos) {
            try {
                $read = $client->readBoardCards($boardId);
            } catch (Throwable $e) {
                $this->warn("writeback: could not read board {$boardId} to check dl source coverage — ".$e->getMessage());

                continue;
            }
            $flagged = 0;
            foreach ($read['cards'] as $card) {
                $payload = is_array($card['payload'] ?? null) ? $card['payload'] : [];
                $dl = $payload['dl_number'] ?? null;
                if (! is_scalar($dl) || (string) $dl === '') {
                    continue;   // not a DL card
                }
                $id = is_scalar($card['id'] ?? null) ? (string) $card['id'] : '?';
                $externalLink = is_string($card['external_link'] ?? null) ? $card['external_link'] : null;
                $source = (new ExternalReferenceNormalizer)->sourceFor($payload, $externalLink);
                if ($source === null) {
                    if ($writeback->boardIsShared((int) $boardId)) {
                        $this->warn("writeback: card {$id} (DL {$dl}) on SHARED board {$boardId} has dl_number but source=null (no repo / pr_url / issue_url / html_url / external_link to derive it from) — the repo-qualified by-ref lookup EXCLUDES it, so it will NEVER self-move. Stamp a repo-qualified pr_url (kbcard patch --pr-url …/<owner>/<repo>/pull/0).");
                        $flagged++;
                    }
                    // non-shared board: the qualifier is omitted (DL-174) — null source correlates fine.
                } elseif (! in_array($source, $repos, true)) {
                    $this->warn("writeback: card {$id} (DL {$dl}) on board {$boardId} has source={$source}, which matches no repo mapped to that board (".implode(', ', $repos).') — no mapped event will move it.');
                    $flagged++;
                }
            }
            if ($read['truncated']) {
                $this->warn("writeback: dl source-coverage check on board {$boardId} is INCOMPLETE — the board read hit the page ceiling; cards beyond it were not checked.");
            } elseif ($flagged === 0) {
                $this->info("writeback: dl_number cards on board {$boardId} all have a mapped source (self-move-eligible)");
            }
        }
    }

    /**
     * DL-200 — the MANDATORY cross-config preflight for the coord-card move leg
     * (roundtable #18, ruled 3-way): compare THIS bridge's `coord_card_terminal_stage_id`
     * against what the coordination config considers terminal for the same board.
     *
     * WHY IT IS MANDATORY, not a nicety. Q1's real failure is NOT "a stage id that isn't
     * on the board" — the stage-existence check above already catches that. It is the two
     * movers DISAGREEING about which column concludes a card: the bridge moves a closed
     * card to stage X while the reconcile treats stage Y as terminal, so they fight every
     * cycle, forever, with each side individually "working". Only comparing the two
     * CONFIGS can see that. This read is what makes it legitimate for the bridge to own a
     * terminal stage id in its own config at all.
     *
     * TWO BINDING CONDITIONS (non-negotiable, both peer-affirmed):
     *  (a) FAIL SOFT, and report CANNOT-VERIFY **distinctly from agreement**. An absent /
     *      unreadable / malformed / silent-on-this-board coord config means the comparison
     *      COULD NOT RUN. Never print agreement on a read failure — a missing input is not
     *      evidence of agreement, it is evidence we could not ask.
     *  (b) NEVER FAIL THE BRIDGE. Diagnostics only, warn-never-fail (the DL-196 posture) —
     *      `bridge:check` must not go non-zero because a coord file moved.
     */
    private function checkCoordTerminalAgreement(string $repo, WritebackMapping $mapping, KanbanClient $client): void
    {
        if (! $mapping->moveCoordCards || $mapping->coordCardTerminalStageId === null) {
            return;   // leg off ⇒ nothing to verify (and no CANNOT-VERIFY noise on installs that never enable it)
        }
        $mine = $mapping->coordCardTerminalStageId;
        $prefix = "writeback: move_coord_cards ({$repo}, board {$mapping->boardId})";
        $tail = 'Until this is verified the two movers may disagree about which column is terminal and fight every cycle.';

        // The per-install override (BRIDGE_COORD_CONFIG_PATH via .env) first, then the
        // ambient $COORD_CONFIG read LIVE through getenv(). getenv() rather than env()
        // is load-bearing, not a style choice: `php artisan optimize` caches config/ and
        // freezes every env() at deploy time (and the frozen value wins over the live
        // one), so an ambient path resolved in config/bridge.php would be whatever the
        // DEPLOYING shell had — usually nothing — forever. That would make this
        // "mandatory" compare permanently report CANNOT-VERIFY: present, running, and
        // never once doing its job. getenv() is cache-immune, and reading it here is
        // legitimate ONLY because this command is CLI-only (the receiver's FPM env has
        // no $COORD_CONFIG — which is the whole reason the compare lives here).
        $path = config('bridge.writeback.coord_config_path');
        if (! is_string($path) || $path === '') {
            $ambient = getenv('COORD_CONFIG');
            $path = is_string($ambient) && $ambient !== '' ? $ambient : null;
        }
        $config = CoordConfigTerminals::load($path);
        if ($config === null) {
            $where = $path === null ? '$COORD_CONFIG is not set' : "the coordination config at {$path} is absent, unreadable, or malformed";
            $this->warn("{$prefix}: CANNOT VERIFY the terminal against the coordination config — {$where}. {$tail} Point bridge.writeback.coord_config_path (or \$COORD_CONFIG) at coordination.config.json.");

            return;
        }

        // Resolved through the framework's OWN rule (explicit terminal_columns, else the
        // user_lanes → "Done" lane-model fallback), joined by board_id and unioned across
        // every entry sharing it — see CoordConfigTerminals. A bare terminal_columns read
        // would resolve NOTHING on the canonical lane-model `issues` board.
        $names = CoordConfigTerminals::terminalNamesForBoardId($config, $mapping->boardId);
        if ($names === []) {
            $this->warn("{$prefix}: CANNOT VERIFY the terminal against the coordination config — it declares no terminal for board {$mapping->boardId} (no kanban.boards[] entry carries that board_id, or the entry has neither terminal_columns nor user_lanes). {$tail}");

            return;
        }
        if (count($names) > 1) {
            // >1 is legal framework-wide (e.g. ["Released to main", "Won't Do"]), but the
            // bridge concludes into exactly ONE stage, so which of them it ought to match
            // is genuinely unknowable. Ambiguous ⇒ cannot verify; never pick one and call
            // that agreement.
            $this->warn("{$prefix}: CANNOT VERIFY the terminal against the coordination config — it resolves ".count($names)." terminals for board {$mapping->boardId} (".implode(', ', $names).'), but the bridge concludes cards into exactly one stage, so which it should match is ambiguous. '.$tail);

            return;
        }
        $name = $names[0];

        try {
            $byName = $client->boardStageIdsByName($mapping->boardId);
        } catch (Throwable $e) {
            $this->warn("{$prefix}: CANNOT VERIFY the terminal against the coordination config — could not read board {$mapping->boardId} to resolve its terminal column \"{$name}\" to a stage id: ".$e->getMessage().' '.$tail);

            return;
        }
        if (! array_key_exists($name, $byName)) {
            $this->warn("{$prefix}: CANNOT VERIFY the terminal against the coordination config — its terminal column \"{$name}\" for board {$mapping->boardId} is not a stage on that board, so it cannot be compared against stage {$mine}. {$tail}");

            return;
        }

        $theirs = $byName[$name];
        if ($theirs === $mine) {
            $this->info("{$prefix}: coord config agrees — its terminal \"{$name}\" is stage {$theirs}, matching coord_card_terminal_stage_id");

            return;
        }
        $this->warn("{$prefix}: the two movers DISAGREE on the terminal — this bridge concludes coord cards into stage {$mine}, but the coordination config's terminal for board {$mapping->boardId} is \"{$name}\" (stage {$theirs}). They will fight every cycle: the bridge moves a closed card to {$mine} and the reconcile drags it back to {$theirs}. Set coord_card_terminal_stage_id={$theirs}, or change that board's terminal_columns.");
    }

    /**
     * #4553 — bind the bridge's runtime `issue_population` (writeback.json, FPM-reachable)
     * to the reconcile's (`$COORD_CONFIG`, its source of truth) so `bridge-on-all +
     * reconcile-on-prefixed` — the exact non-prefixed no-backstop gap — is a CHECKABLE
     * DISAGREE, not silence. Three-state (agree / DISAGREE / CANNOT-VERIFY), warn-never-fail,
     * reusing the DL-200 cross-config machinery. CANNOT-VERIFY is kept DISTINCT from agreement:
     * an unset/unreadable $COORD_CONFIG is "could not ask," not "they agree." Called only when
     * the bridge side is already `all` (the direction that can strand cards); the mirror
     * (reconcile=all, bridge=prefixed) is a lesser not-real-time gap and is not force-checked.
     *
     * CLI-only for the same reason as {@see checkCoordTerminalAgreement}: the FPM webhook env
     * has no $COORD_CONFIG (the whole reason the compare lives here), and getenv() is read live
     * (cache-immune) so `php artisan optimize` cannot freeze a deploy-time value.
     */
    private function checkIssuePopulationAgreement(string $repo, WritebackMapping $mapping): void
    {
        $mine = $mapping->issuePopulation;   // 'all' (this method is only called under `all`)
        $prefix = "writeback: issue_population ({$repo}, board {$mapping->boardId})";
        $tail = 'A bridge on `all` with a reconcile on `prefixed` is the no-backstop gap — the non-prefixed set self-heals nowhere.';

        $path = config('bridge.writeback.coord_config_path');
        if (! is_string($path) || $path === '') {
            $ambient = getenv('COORD_CONFIG');
            $path = is_string($ambient) && $ambient !== '' ? $ambient : null;
        }
        $config = CoordConfigTerminals::load($path);
        if ($config === null) {
            $where = $path === null ? '$COORD_CONFIG is not set' : "the coordination config at {$path} is absent, unreadable, or malformed";
            $this->warn("{$prefix}: CANNOT VERIFY against the reconcile's issue_population — {$where}. {$tail} Point bridge.writeback.coord_config_path (or \$COORD_CONFIG) at coordination.config.json.");

            return;
        }
        $theirs = CoordConfigTerminals::issuePopulationsForBoardId($config, $mapping->boardId);
        if ($theirs === []) {
            $this->warn("{$prefix}: CANNOT VERIFY against the reconcile's issue_population — the coordination config has no kanban.boards[] entry for board {$mapping->boardId}. {$tail}");

            return;
        }
        if (count($theirs) > 1) {
            $this->warn("{$prefix}: CANNOT VERIFY — the coordination config resolves multiple issue_population values for board {$mapping->boardId} (".implode(', ', $theirs)."). {$tail}");

            return;
        }
        if ($theirs[0] === $mine) {
            $this->info("{$prefix}: coord config agrees — reconcile issue_population is '{$mine}', so the non-prefixed set is backstopped by the reconcile's by-ref correlation.");

            return;
        }
        $this->warn("{$prefix}: the two movers DISAGREE on issue_population — this bridge is 'all' (it real-times NON-PREFIXED issues), but the coordination config's issue_population for board {$mapping->boardId} is '{$theirs[0]}'. A bridge-missed non-prefixed event then has NO reconcile backstop. Set kanban.boards[].issue_population=all in \$COORD_CONFIG (and extend the reconcile to correlate by github_issue by-ref), or set the bridge's issue_population=prefixed.");
    }

    /**
     * Validate the optional `writeback.alert_channel` (FR-4). Warn (never fail) on
     * a malformed channel — it is an opt-in diagnostic, so a bad value must not
     * fail the install check; at runtime a bad channel just makes the alert push
     * fail (caught) without breaking the writeback move.
     */
    private function checkAlertChannel(AlertChannel $ac): void
    {
        $socket = $ac->socket;
        $url = $ac->url;
        if (($socket !== null) === ($url !== null)) {
            $this->warn('writeback.json alert_channel: specify exactly one of socket or url — the alert push will fail (caught) until fixed; the writeback move is unaffected');

            return;
        }
        if ($socket !== null) {
            $dir = dirname($socket);
            if (! is_dir($dir)) {
                $this->warn("writeback.json alert_channel: socket parent dir {$dir} does not exist — the alert push will fail (caught) until the channel server creates the socket");
            } else {
                $this->info("writeback.json alert_channel: socket {$socket} (parent dir present)");
            }

            return;
        }
        // Defer to the runtime sender's authority (LocalhostUrl::assertValid, the
        // same gate WritebackAlertNotifier enforces) so the check and the sender
        // can never disagree on what a valid alert url is — a hand-rolled copy here
        // once dropped the userinfo rejection and green-lit a url the sender refused.
        try {
            LocalhostUrl::assertValid((string) $url, 'writeback.json alert_channel: url');
            $this->info("writeback.json alert_channel: url {$url} (localhost)");
        } catch (EndpointValidationException $e) {
            $this->warn($e->getMessage().' — the alert push will fail (caught) until fixed');
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
