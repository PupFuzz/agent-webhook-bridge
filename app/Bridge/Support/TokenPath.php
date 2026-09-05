<?php

namespace App\Bridge\Support;

/**
 * On-disk location of a per-provider API token, used by bridge:provision.
 * Convention: <secret_dir>/<provider>/token — keyed on (secret_dir, provider),
 * NOT per-agent, because a token is a per-provider-account credential: several
 * agents sharing one upstream account (DL-002 shared_identities) share one
 * token, and the canonical install points multiple agents at the same token
 * file. A per-agent override (agent YAML `api.<provider>.token_path`) wins when
 * an agent genuinely authenticates as a distinct account. Mirrors
 * SecretPath::for (the sibling per-(provider, scope) HMAC-secret shape).
 */
final class TokenPath
{
    public static function for(string $secretDir, string $provider): string
    {
        return sprintf('%s/%s/token', rtrim($secretDir, '/'), $provider);
    }

    /**
     * The DEDICATED writeback token path (DL-009): <secret_dir>/<provider>/
     * writeback-token. Distinct from for() on purpose — the writeback token is
     * narrower than the provisioning one, so it must NOT collide with that broad
     * token at the same (secret_dir, provider) key. Which board permissions it
     * carries is docs/writeback.md § 1's permission table to state, not this
     * docblock's: the spelling here was "card-move scope only", which has been
     * wrong since the first non-stage-only PATCH shipped (DL-328 bound (e)).
     */
    public static function forWriteback(string $secretDir, string $provider): string
    {
        return sprintf('%s/%s/writeback-token', rtrim($secretDir, '/'), $provider);
    }
}
