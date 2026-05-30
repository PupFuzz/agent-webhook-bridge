<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\InstallGuard;
use App\Bridge\Support\SecretPath;
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
        }

        $secretDir = config('bridge.secret_dir');
        if (! is_string($secretDir) || ! str_starts_with($secretDir, '/')) {
            $this->error('bridge.secret_dir (BRIDGE_SECRET_DIR) is not set or not absolute');
            $ok = false;
        } else {
            $this->info("secret dir: {$secretDir}");
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

        $agentNames = [];
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
                        }
                    }
                }
            }
        }

        // BRIDGE_DEFAULT_AGENT must name a real config, else a bare bridge:inbox
        // silently surfaces nothing.
        $defaultAgent = config('bridge.default_agent');
        if (is_string($defaultAgent) && $defaultAgent !== '' && ! in_array($defaultAgent, $agentNames, true)) {
            $this->warn("BRIDGE_DEFAULT_AGENT '{$defaultAgent}' has no matching config {$configDir}/{$defaultAgent}.yml");
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
