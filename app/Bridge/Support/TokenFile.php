<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\UnreadableSecretException;

/**
 * Read a secret token from a file: the single trim/non-empty primitive shared
 * by every token reader (the API token in bridge:provision and the channel auth
 * token), so the "is it empty / how is it trimmed" edge can't drift between
 * call sites. Callers layer their own policy on the null return (skip vs throw)
 * and any perms enforcement (see ChannelToken).
 */
final class TokenFile
{
    /**
     * The trimmed token; null when the file is absent or blank; throws
     * {@see UnreadableSecretException} when a file IS there and this process could not
     * read it.
     *
     * WHY THE READ ITSELF IS THE AUTHORITY, and not an `is_readable()` gate: that
     * predicate answers for the REAL uid (wrong under setuid) and cannot see an ACL, an
     * immutable bit, or a filesystem that refuses the open — so it both misses failures
     * and, worse, invites the caller to treat its `false` as absence. The open's own
     * return value is the only answer that is never a proxy.
     *
     * THE SUPPRESSION IS LOAD-BEARING, NOT HYGIENE. Unsuppressed, this warning's meaning
     * was decided by whatever error handler happened to be installed: under the console
     * and HTTP kernels Laravel converts it to an uncaught `ErrorException` (a 500 on the
     * board-tools door, an aborted `bridge:check`), while under a handler that only logs
     * — `tinker`'s, for one — `file_get_contents` returns false and the same unreadable
     * secret silently became `null`, i.e. "absent". One input, two contradictory
     * outcomes, neither documented. Converting it here makes the outcome a TYPE the
     * callers can switch on, identically in every context (card#5778).
     */
    public static function readTrimmed(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            // Past tense on the presence half deliberately: the gate above OBSERVED a
            // regular file, and asserting it is still there would be a claim about a
            // moment this process never measured (the file can be unlinked in between).
            // The permissions reading is what the operator needs and it holds either way.
            throw new UnreadableSecretException(
                "secret file at {$path} could not be read by this process (a regular file was "
                .'found at the path, so this is a permissions fault rather than an absence) — '
                .'ownership and mode are relative to the asking user, so another OS user may read it fine'
            );
        }
        $token = trim($raw);

        return $token !== '' ? $token : null;
    }
}
