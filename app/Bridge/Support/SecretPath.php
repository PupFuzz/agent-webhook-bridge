<?php

namespace App\Bridge\Support;

/**
 * The on-disk location of a per-(provider, scope) HMAC secret. This shape is a
 * contract BOTH halves of the bridge must agree on — the receiver middleware
 * reads it to verify signatures, and provisioning writes it — so it lives in
 * one place. The scope's `/` is URL-encoded so a slash-bearing scope
 * (GitHub's org/repo) stays a single filename segment.
 */
final class SecretPath
{
    public static function for(string $secretDir, string $provider, string $scopeId): string
    {
        return sprintf(
            '%s/%s/webhook-secret-scope-%s',
            rtrim($secretDir, '/'),
            $provider,
            str_replace('/', '%2F', $scopeId),
        );
    }
}
