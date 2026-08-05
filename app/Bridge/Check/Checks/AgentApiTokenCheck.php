<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\SecretFile;

/**
 * Per-provider API-token presence and perms — the token `bridge:provision` authenticates
 * with — migrated out of `CheckCommand::handle()`'s per-agent loop (DL-242 stage 5b).
 *
 * ONE TOKEN PER DISTINCT PROVIDER, not per subscription: the token is the provider's,
 * and an agent subscribing twenty github scopes would otherwise warn twenty times about
 * one file. The path is the `<secret_dir>/<provider>/token` convention unless the agent
 * overrides it (`api.<provider>.token_path`), which is why it resolves through the config
 * rather than being composed here.
 *
 * WARN, NOT FAIL, ON BOTH ARMS — a provider may legitimately not be provisioned yet, and
 * the consequences are `bridge:provision`'s to hit: an unreadable token makes it SKIP
 * that provider's scopes, insecure perms make it FAIL outright.
 *
 * SEPARATE FROM THE WEBHOOK-SECRET CHECK — see that check for why the shared secret-dir
 * gate does not merge them.
 */
final class AgentApiTokenCheck implements PerAgentCheck
{
    public function id(): string
    {
        return 'agent.api_tokens';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $secretDir = $ctx->secretDir;
        if ($secretDir === null) {
            yield Silence::because('no absolute secret dir resolved, so there is no token path to check — the dir itself is reported by the install plane');

            return;
        }

        $name = $config->agentName;
        foreach (array_unique(array_map(fn ($s) => $s->provider, $config->subscriptions)) as $provider) {
            $tokenPath = $config->tokenPath($secretDir, $provider);
            if (! is_file($tokenPath) || ! is_readable($tokenPath)) {
                yield Finding::warn("agent {$name}: {$provider} API token not readable at {$tokenPath} — bridge:provision will SKIP {$provider} scopes");
            } elseif (SecretFile::isInsecure($tokenPath)) {
                yield Finding::warn("agent {$name}: ".SecretFile::permsMessage($tokenPath).' — bridge:provision will FAIL until fixed');
            }
        }

        yield Silence::because('every provider this agent subscribes to has a readable, securely-permissioned API token — and an agent with no subscriptions has no provider to check');
    }
}
