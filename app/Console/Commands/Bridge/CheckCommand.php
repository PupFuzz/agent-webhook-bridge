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
use App\Bridge\Support\UrlValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Validate the install: config/secret dirs, DB connectivity, and that every
 * per-agent YAML parses. Run before going live (and in the cutover runbook).
 */
class CheckCommand extends Command
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
                // bad value is an uncaught 5xx (→ upstream retry storm). Resolve
                // it here so a typo surfaces as a preflight failure instead.
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
