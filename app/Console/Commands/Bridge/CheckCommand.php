<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
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
use App\Bridge\Writeback\AlertChannel;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Validate the install: config/secret dirs, DB connectivity, and that every
 * per-agent YAML parses. Run before going live (and in the cutover runbook).
 */
class CheckCommand extends BridgeCommand
{
    protected $signature = 'bridge:check';

    protected $description = 'Validate the bridge install config (dirs, DB connectivity, agent YAMLs)';

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

        // Per-install endpoint URLs (when set — unset is fine until provisioning).
        foreach ([
            'receiver_base_url' => (string) config('bridge.receiver_base_url'),
            'providers.kanban.api_base_url' => (string) config('bridge.providers.kanban.api_base_url'),
        ] as $field => $value) {
            if ($value === '') {
                continue;
            }
            try {
                UrlValidator::httpUrl($value, "bridge.{$field}");
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
                                    $unknownStages = array_values(array_unique(array_diff($targets, $boardStageIds)));
                                    if ($unknownStages !== []) {
                                        $this->warn("writeback: mapping for {$repo} references workflow stage id(s) ".implode(', ', $unknownStages)." not on board {$mapping->boardId} — those moves will 422 (or the started/no-regression guard will silently never match) until fixed");
                                    } else {
                                        $this->info("writeback: all mapped stage ids exist on board {$mapping->boardId} ({$repo})");
                                    }
                                }
                            } catch (Throwable $e) {
                                $this->warn("writeback: could not read board {$mapping->boardId} ({$repo}) with the writeback token — ".$e->getMessage());
                            }
                        }
                        // #3399: in ref mode the correlation is repo-qualified (passes the event's
                        // `source`), so a dl_number card whose derived source is null (no pr_url) or
                        // matches no repo mapped to its board is EXCLUDED by the by-ref lookup and
                        // silently never self-moves — the one writeback failure that stays invisible.
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

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * #3399: on a ref-mode (source-qualified) writeback, the by-ref lookup filters by the
     * event's repo `source`, which the kanban derives from a card's `pr_url`. A dl_number card
     * with no pr_url (source=null), or a pr_url whose owner/repo matches no repo mapped to that
     * board, is EXCLUDED by the lookup and silently never self-moves — indistinguishable from a
     * legitimate no-match in the dispatch ledger. Warn (never fail) so it is named + actionable
     * (root cause closed by `kbcard --pr-url` + the on-ramp docs). Per board (deduped across mappings).
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
                    $this->warn("writeback: card {$id} (DL {$dl}) on board {$boardId} has dl_number but source=null (no repo / pr_url / issue_url / html_url / external_link to derive it from) — the repo-qualified by-ref lookup EXCLUDES it, so it will NEVER self-move. Stamp a repo-qualified pr_url (kbcard patch --pr-url …/<owner>/<repo>/pull/0).");
                    $flagged++;
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
        $parts = parse_url((string) $url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'http') {
            $this->warn("writeback.json alert_channel: url must be http:// loopback (got '{$url}') — the alert push will fail (caught) until fixed");

            return;
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->warn("writeback.json alert_channel: url must point at 127.0.0.1, localhost, or [::1] (got '{$host}') — the alert push will fail (caught) until fixed");

            return;
        }
        $this->info("writeback.json alert_channel: url {$url} (localhost)");
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
