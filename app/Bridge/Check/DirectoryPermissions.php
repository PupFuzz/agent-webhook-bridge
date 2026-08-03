<?php

namespace App\Bridge\Check;

use App\Bridge\Support\Finding;
use App\Bridge\Support\PathVisibility;

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
 *
 * IT STATS ONLY WHAT IT HAS ESTABLISHED IT CAN STAT (DL-264, card#5774), and the guard
 * below is not decoration: `fileperms()` on an unstattable path RAISES, it does not
 * return false. Under Laravel's error handler that warning becomes an uncaught
 * `ErrorException`, so the pre-fix line `$perms = fileperms($dir)` did not silently
 * certify an unmeasurable directory — it ABORTED the whole command, stack trace and exit
 * 1, with no report rendered for any subsystem. Reachable via `InstallSecretDirCheck`
 * (NAMED, never `{@see}`-linked — pint rewrites a docblock FQCN into a real `use`, and
 * importing a consumer here would invert the layer; the same convention `PathVisibility`
 * keeps), which never existence-checks its dir, so `BRIDGE_SECRET_DIR` pointing at an
 * absent or unseeable path took `bridge:check` down. The card that filed this predicted a
 * SILENT false-clean and the `?Finding` return made that reading look right; the real
 * surface disagreed, which is why the fix is a pre-stat gate and not a `=== false` arm —
 * that arm would never have been reached.
 *
 * The gate is a property of this primitive rather than a guard bolted onto that one
 * caller: the sibling caller cannot reach it (it gates on `is_dir()` first, barring a
 * race), but a THIRD caller would inherit the raise, which is how this class of defect
 * keeps being re-minted (card#5698, DL-262, DL-263).
 *
 * ⚠ `file_exists()` IS THE GATE, NOT `is_dir()`, and the difference is deliberate. A
 * `secret_dir` pointing at a REGULAR FILE stats fine and still reports its mode through
 * the warn below — wrong subject, but that is the pre-existing behaviour and it is loud
 * rather than silent. `is_dir()` here would have quietly reclassified it as "does not
 * exist", which is a false claim this class exists to stop making. Tracked as card#5796.
 */
final class DirectoryPermissions
{
    /**
     * Warn (not fail) when a secret-holding dir is group/world-accessible (DL-014).
     * On a multi-tenant host these dirs must be owner-only (0700); a co-tenant who
     * can traverse one can read the HMAC secrets / tokens in it. Warn, not fail —
     * perms are operator-owned and the per-secret 0600 gate (DL-010) is the hard
     * backstop enforced fail-closed at point-of-use regardless of dir perms.
     *
     * AN UNSTATTABLE PATH SPLITS BY CAUSE, and the two are different findings because they
     * ask the operator for different actions. Untraversable ancestor ⇒ this process cannot
     * ANSWER the question, which is `unvalidated` per the `Severity` rule's limb 2 and is
     * the shared guard's job. Traversable ancestor ⇒ the negative stat is conclusive and
     * the path is genuinely absent, which is a measured fact and stays `warn`: an absent
     * dir cannot be chmod'd, and the DL-010 point-of-use gate is fail-closed over it
     * exactly as it is over a loose mode, so neither arm flips the exit code this
     * primitive has never flipped.
     */
    public static function warnIfInsecure(string $label, string $dir): ?Finding
    {
        clearstatcache(true, $dir);

        if (! file_exists($dir)) {
            return PathVisibility::unverifiedUnlessVisible($dir, "{$label} {$dir}")
                ?? Finding::warn("{$label} {$dir} does not exist, so its mode could not be checked and nothing can be read from it — create it (chmod 700), or correct the setting that points here");
        }

        // `!== false` is a type guard, not a reachability one: the gate above already
        // established the stat succeeds, and a failed one would raise rather than return.
        $perms = fileperms($dir);
        if ($perms !== false && ($perms & 0o077) !== 0) {
            return Finding::warn(sprintf('%s %s is group/world-accessible (mode %04o) — chmod 700 (it holds secrets)', $label, $dir, $perms & 0o777));
        }

        return null;
    }
}
