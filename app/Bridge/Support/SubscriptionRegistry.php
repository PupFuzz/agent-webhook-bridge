<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * Answers "which agents subscribe to this (provider, scope)?" by scanning the
 * per-agent YAML files under the config dir. Subscription interest lives in
 * each agent's YAML `subscriptions:` block (identity lives in the same YAMLs'
 * `identity:` block + optional shared-identities.json — there is no agents.json).
 *
 * FAIL-CLOSED: a malformed config file makes agentConfigs() throw
 * (ConfigException), which propagates out of the synchronous dispatch loop to
 * a 5xx. That is correct, not a storm to suppress — kanban-board HOLDS the
 * event on its retry curve and redelivers once the operator fixes the config.
 * Swallowing the error and returning the valid subset would silently drop the
 * broken agent's events with no backstop.
 *
 * Parsed configs are memoized for the lifetime of the instance (one per
 * request). A per-FPM-worker cache is a future optimisation; correctness here
 * does not depend on it.
 */
final class SubscriptionRegistry
{
    /**
     * @var list<AgentConfig>|null
     */
    private ?array $configs = null;

    public function __construct(private string $configDir) {}

    /**
     * @return list<AgentConfig> agents subscribed to the given (provider, scope)
     */
    public function subscribedTo(string $provider, string $scopeId): array
    {
        $matches = [];
        foreach ($this->agentConfigs() as $cfg) {
            foreach ($cfg->subscriptions as $sub) {
                if ($sub->provider === $provider && $sub->scopeId === $scopeId) {
                    $matches[] = $cfg;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * @return list<AgentConfig>
     *
     * @throws ConfigException if any config file is malformed (fail-closed)
     */
    public function agentConfigs(): array
    {
        if ($this->configs !== null) {
            return $this->configs;
        }

        $configs = [];
        foreach (glob(rtrim($this->configDir, '/').'/*.yml') ?: [] as $file) {
            $configs[] = AgentConfig::load(basename($file, '.yml'), $this->configDir);
        }

        return $this->configs = $configs;
    }
}
