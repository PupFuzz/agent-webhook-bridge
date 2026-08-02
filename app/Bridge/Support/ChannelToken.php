<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ChannelTokenException;
use App\Bridge\Exceptions\ChannelTokenFault;
use App\Bridge\Exceptions\UnreadableSecretException;

/**
 * Read an agent's channel auth token (channel.auth.token_path) for the
 * Bearer-gated loopback/tunnel push, with fail-closed validation.
 *
 * Perms are load-bearing, not hygiene (DL-008): on a multi-user host the bridge
 * pushes over loopback TCP, which any local account can POST to — so the Bearer
 * token IS the trust boundary, and a token file any local account can READ is
 * no boundary at all (they'd forge the header). So we refuse a group/world-
 * readable token SSH-style (mode & 0o077), at point-of-use, not merely by
 * convention. token_path is operator-config-sourced (not classifier-payload-
 * sourced like channel.socket), so the socket's symlink/TOCTOU defense doesn't
 * apply — the 0600 owner-only perm is the defense.
 */
final class ChannelToken
{
    public static function read(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ChannelTokenException("channel auth token not readable at {$path}", self::unreadableFault($path));
        }
        // The mode & 0o077 gate lives in SecretFile (DL-010, shared with the HMAC
        // receiver + API/writeback token); the channel-specific message + the
        // ChannelTokenException type (DL-008 contract) stay here.
        if (SecretFile::isInsecure($path)) {
            throw new ChannelTokenException(sprintf(
                'channel auth token at %s is group/world-readable (mode %04o) — chmod 600',
                $path,
                (int) fileperms($path) & 0o777,
            ), ChannelTokenFault::InsecurePerms);
        }
        try {
            $token = TokenFile::readTrimmed($path);
        } catch (UnreadableSecretException $e) {
            // The `is_readable()` gate above is a PREDICTION and this is the actual open,
            // so the two can disagree — an ACL, a network filesystem, or an unlink in the
            // window between them. Mapped rather than allowed to escape: every caller of
            // this method is written against ChannelTokenException, and NotReadable is
            // already the right fault for it (card#5778).
            throw new ChannelTokenException($e->getMessage(), ChannelTokenFault::NotReadable);
        }
        if ($token === null) {
            throw new ChannelTokenException("channel auth token at {$path} is empty", ChannelTokenFault::EmptyFile);
        }

        return $token;
    }

    /**
     * Which of the first gate's three worlds we are in — asked HERE, immediately after the
     * gate that failed, rather than by the consumer. It does re-query the path (`is_file`
     * comes back off PHP's stat cache; `is_executable` does not), so the claim is adjacency,
     * not a single syscall: there is no window a caller can act in between the two, whereas
     * a consumer deriving this from the path later reads a file that has had one
     * (see {@see ChannelTokenFault}).
     *
     * Order matters: traversability is asked FIRST because without it `is_file()` cannot
     * answer at all, and its `false` would read as an absence this process never
     * established. {@see PathVisibility} owns that predicate — the same guard the stat-
     * conflation sweep hoisted for the sibling legs.
     */
    private static function unreadableFault(string $path): ChannelTokenFault
    {
        if (! PathVisibility::ancestorIsTraversable($path)) {
            return ChannelTokenFault::NotVisible;
        }

        // Reached only when the gate above failed, so `is_file()` true ⇒ `is_readable()`
        // was the half that failed.
        return is_file($path) ? ChannelTokenFault::NotReadable : ChannelTokenFault::Missing;
    }
}
