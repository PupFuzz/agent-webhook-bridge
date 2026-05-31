<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\InsecureSecretPermsException;

/**
 * The one place the SSH-style `mode & 0o077` perms gate for an at-rest secret
 * file lives (DL-010). DL-008 established that a group/world-readable secret on
 * a multi-tenant host is no boundary at all — a co-tenant who can *read* the
 * HMAC secret forges signed webhooks, and one who can read an API/writeback
 * token writes directly upstream. The provisioner *writes* 0600, but nothing
 * *enforced* it on read (a cp/umask accident leaves a token 0644), and the
 * check was applied only to the lower-value channel token. This unifies it:
 * every secret reader consults the same predicate, fail-closed.
 *
 * Callers map the failure to their own surface:
 *  - HMAC receiver (VerifyHmacSignature) — isInsecure() → 500 secret_perms_insecure
 *  - API/writeback token (bridge:provision) — read() throws → command error
 *  - channel token (ChannelToken) — isInsecure() → ChannelTokenException (DL-008 contract)
 */
final class SecretFile
{
    /**
     * True when the file exists and is group/world-readable. A missing file is
     * NOT insecure (returns false) — the caller decides what an absent secret
     * means (unknown_scope / skip / throw), so this gate never converts
     * "absent" into "insecure".
     */
    public static function isInsecure(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }
        // Read live perms, not a cached stat: a fail-closed security gate must
        // reflect the file's current mode. PHP's per-process stat cache persists
        // across FPM requests, so a worker that cached the old mode could keep
        // trusting a since-loosened secret (or rejecting a since-fixed one).
        clearstatcache(true, $path);
        $perms = @fileperms($path);

        return $perms !== false && ($perms & 0o077) !== 0;
    }

    /**
     * Perms-enforced trimmed read for the general secret/token case: null when
     * the file is absent or blank (caller decides whether that is fatal),
     * throws InsecureSecretPermsException when it is present but
     * group/world-readable. Trimming stays in TokenFile (the single trim
     * primitive, DL-008) so the "how is it trimmed" edge can't drift.
     */
    public static function read(string $path): ?string
    {
        if (self::isInsecure($path)) {
            throw new InsecureSecretPermsException(self::permsMessage($path));
        }

        return TokenFile::readTrimmed($path);
    }

    public static function permsMessage(string $path): string
    {
        $perms = @fileperms($path);

        return sprintf(
            'secret file at %s is group/world-readable (mode %04o) — chmod 600',
            $path,
            $perms === false ? 0 : ($perms & 0o777),
        );
    }
}
