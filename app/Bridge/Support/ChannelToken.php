<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ChannelTokenException;

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
            throw new ChannelTokenException("channel auth token not readable at {$path}");
        }
        // The mode & 0o077 gate lives in SecretFile (DL-010, shared with the HMAC
        // receiver + API/writeback token); the channel-specific message + the
        // ChannelTokenException type (DL-008 contract) stay here.
        if (SecretFile::isInsecure($path)) {
            throw new ChannelTokenException(sprintf(
                'channel auth token at %s is group/world-readable (mode %04o) — chmod 600',
                $path,
                (int) fileperms($path) & 0o777,
            ));
        }
        $token = TokenFile::readTrimmed($path);
        if ($token === null) {
            throw new ChannelTokenException("channel auth token at {$path} is empty");
        }

        return $token;
    }
}
