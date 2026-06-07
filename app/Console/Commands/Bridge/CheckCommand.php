<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\ChannelToken;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\InstallGuard;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SecretPath;
use App\Bridge\Support\SignalAllowlist;
use App\Bridge\Support\TokenPath;
use App\Bridge\Support\UrlValidator;
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
            }
        }

        // Build the registry from the scanned configs (surfaces id-collision
        // warnings at preflight) and validate each agent's treat_as_signal — an
        // unknown name is fail-closed at dispatch (5xx), so catch it here.
        if ($configs !== [] && is_string($configDir)) {
            $registry = AgentRegistry::fromAgentConfigs($configs, AgentRegistry::loadSharedIdentities($configDir));
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
                    try {
                        $client = WritebackClientFactory::make();
                        foreach ($writeback->mappings as $repo => $mapping) {
                            try {
                                // Cheap visibility probe (DL-029): one limit=1 read,
                                // preferring meta.total — independent of correlation mode.
                                $vis = $client->visibility($mapping->boardId);
                                if ($vis['total'] === 0) {
                                    $this->warn("writeback: token sees 0 cards on board {$mapping->boardId} ({$repo}) — its user is likely not a member of that board, or board_id is wrong; the writeback will SILENTLY no-op every move until fixed");
                                } elseif (! $vis['exact']) {
                                    // Pre-DL-146 kanban: confirmed non-blind, exact size unknown.
                                    $this->info("writeback: token can see board {$mapping->boardId} ({$repo}) (exact card count unavailable — kanban predates pagination meta)");
                                } else {
                                    $this->info("writeback: token sees {$vis['total']} card(s) on board {$mapping->boardId} ({$repo})");
                                    if (config('bridge.writeback.correlation') !== 'ref' && $vis['total'] > KanbanClient::SEARCH_LIMIT * KanbanClient::MAX_PAGES) {
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
                            } catch (Throwable $e) {
                                $this->warn("writeback: could not read board {$mapping->boardId} ({$repo}) with the writeback token — ".$e->getMessage());
                            }
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
