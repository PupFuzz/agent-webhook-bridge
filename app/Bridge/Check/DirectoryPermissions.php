<?php

namespace App\Bridge\Check;

use App\Bridge\Support\Finding;

/**
 * The group/world-accessible verdict on a directory holding secrets (DL-014), shared by
 * the config-dir and secret-dir checks (DL-242 stage 6).
 *
 * EXTRACTED RATHER THAN COPIED because stage 6 is where the second caller arrives. It
 * was a private method on `CheckCommand` with exactly two call sites, and both migrated
 * into checks in the same stage — so the alternative was two copies of one permission
 * verdict, which is the defect shape this program exists to remove.
 *
 * IT RETURNS A FINDING OR NULL rather than yielding, because the caller decides where the
 * warn sits in ITS output: the config-dir check emits it directly after its own `ok`
 * line, and the secret-dir check emits it only when the two directories differ.
 */
final class DirectoryPermissions
{
    /**
     * Warn (not fail) when a secret-holding dir is group/world-accessible (DL-014).
     * On a multi-tenant host these dirs must be owner-only (0700); a co-tenant who
     * can traverse one can read the HMAC secrets / tokens in it. Warn, not fail —
     * perms are operator-owned and the per-secret 0600 gate (DL-010) is the hard
     * backstop enforced fail-closed at point-of-use regardless of dir perms.
     */
    public static function warnIfInsecure(string $label, string $dir): ?Finding
    {
        clearstatcache(true, $dir);
        $perms = fileperms($dir);
        if ($perms !== false && ($perms & 0o077) !== 0) {
            return Finding::warn(sprintf('%s %s is group/world-accessible (mode %04o) — chmod 700 (it holds secrets)', $label, $dir, $perms & 0o777));
        }

        return null;
    }
}
