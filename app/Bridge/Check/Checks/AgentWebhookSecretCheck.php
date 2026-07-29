<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SecretPath;

/**
 * Per-subscription webhook-secret presence and perms, migrated out of
 * `CheckCommand::handle()`'s per-agent loop (DL-242 stage 5b).
 *
 * Both arms are silent-until-wrong on purpose: a provisioned install prints nothing
 * here, and the two failures are the ones that go unnoticed live. A MISSING secret
 * makes the receiver 401 the delivery (`unknown_scope`) — invisible until an operator
 * notices activity went missing — and INSECURE perms make it 500
 * (`secret_perms_insecure`) at the first delivery, so both are warned at preflight
 * where they are cheap.
 *
 * SEPARATE FROM THE API-TOKEN CHECK despite sharing the secret-dir gate. They iterate
 * different things (every subscription vs each distinct provider) and fail with
 * different consequences, so folding them into one check would give stage 8 a single
 * inventory row that means two things.
 *
 * NO GOLDEN FIXTURE WRITES A GROUP/WORLD-READABLE SECRET, so the harness cannot tell the
 * insecure arm's two branches apart. Mutating that arm reds `AgentWebhookSecretCheckTest`
 * and nothing else in the suite — measured by mutation, which is the only instrument that
 * answers a whole-suite question (see CLAUDE_TESTING.md's golden-harness rules). (Named,
 * never `{@see}`-linked: pint would turn the FQCN into a real `use`.)
 */
final class AgentWebhookSecretCheck implements PerAgentCheck
{
    public function id(): string
    {
        return 'agent.webhook_secrets';
    }

    /**
     * @return iterable<Finding>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $secretDir = $ctx->secretDir;
        if ($secretDir === null) {
            return;   // no absolute secret dir ⇒ no path to check; stage 8 turns this into a returned disposition
        }

        $name = $config->agentName;
        foreach ($config->subscriptions as $sub) {
            $secretPath = SecretPath::for($secretDir, $sub->provider, $sub->scopeId);
            if (! is_file($secretPath)) {
                yield Finding::warn("agent {$name}: {$sub->provider}:{$sub->scopeId} has no secret at {$secretPath} — run bridge:provision");
            } elseif (SecretFile::isInsecure($secretPath)) {
                yield Finding::warn("agent {$name}: ".SecretFile::permsMessage($secretPath).' — the receiver will 500 (secret_perms_insecure) until fixed');
            }
        }
    }
}
