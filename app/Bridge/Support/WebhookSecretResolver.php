<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\UnreadableFileException;

/**
 * THE resolution of a per-(provider, scope) HMAC secret from disk: where it lives, the
 * perms it must have, and — the edge this class exists for — exactly how its bytes are
 * NORMALIZED before they are used as a key.
 *
 * The receiver has always `trim()`ed the file's contents, and every producer of a
 * signature has to apply the SAME normalization or every signature it makes is rejected
 * with `sig_mismatch` — a 401 that reads as a receiver bug and gets debugged at the
 * wrong end. That rule used to be written down only in prose in the deployment runbook
 * (as `openssl -hmac "$SECRET"`, whose `$(cat …)` happens to trim the trailing newline
 * as a side effect of command substitution), so the next copy of the runbook re-minted
 * it. It is now code with one caller-visible contract and a test on it (DL-322).
 *
 * ⛔ WHY THIS DOES NOT CALL {@see TokenFile::readTrimmed}, the codebase's other trim:
 * that primitive answers null for "absent" and "blank" alike, and the receiver's status
 * contract must tell those two apart — an absent secret is `401 unknown_scope` (an
 * unknown subscriber), a blank one is `500 empty_secret_file` (this install is broken).
 * The read-vs-absence discrimination underneath IS shared: {@see FileContents}.
 */
final class WebhookSecretResolver
{
    /**
     * The trimmed secret for this (provider, scope), or the reason there isn't one.
     *
     * Order is load-bearing and matches the receiver's documented contract: the perms
     * gate runs BEFORE the read so an ABSENT secret still resolves as `UnknownScope`
     * rather than as insecure (`SecretFile::isInsecure` is false for a missing file).
     */
    public static function resolve(string $provider, string $scopeId): string|WebhookSecretFailure
    {
        $secretDir = self::secretDir();
        if ($secretDir instanceof WebhookSecretFailure) {
            return $secretDir;
        }

        $secretPath = SecretPath::for($secretDir, $provider, $scopeId);

        // Fail-closed on a group/world-readable secret (DL-010): a co-tenant who can
        // read it forges valid signatures, so a leaked-perms secret is no boundary.
        if (SecretFile::isInsecure($secretPath)) {
            return WebhookSecretFailure::SecretPermsInsecure;
        }

        try {
            $raw = FileContents::read($secretPath, 'secret file');
        } catch (UnreadableFileException) {
            return WebhookSecretFailure::SecretUnreadable;
        }
        if ($raw === null) {
            return WebhookSecretFailure::UnknownScope;
        }

        $secret = trim($raw);

        return $secret !== '' ? $secret : WebhookSecretFailure::EmptySecretFile;
    }

    /**
     * Where this (provider, scope)'s secret would live — for a diagnostic that names the
     * path. Null only when `bridge.secret_dir` itself is unusable, i.e. exactly when
     * `resolve()` answers one of the two `ConfigSecretDir*` cases and there is no path
     * to name.
     */
    public static function pathFor(string $provider, string $scopeId): ?string
    {
        $secretDir = self::secretDir();

        return $secretDir instanceof WebhookSecretFailure
            ? null
            : SecretPath::for($secretDir, $provider, $scopeId);
    }

    private static function secretDir(): string|WebhookSecretFailure
    {
        $secretDir = config('bridge.secret_dir');
        if (! is_string($secretDir) || trim($secretDir) === '') {
            return WebhookSecretFailure::ConfigSecretDirMissing;
        }
        if (! str_starts_with($secretDir, '/')) {
            return WebhookSecretFailure::ConfigSecretDirNotAbsolute;
        }

        return $secretDir;
    }
}
