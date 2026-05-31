<?php

namespace App\Bridge\Support;

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
     * The trimmed token, or null when the file is absent or blank.
     */
    public static function readTrimmed(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $token = trim((string) file_get_contents($path));

        return $token !== '' ? $token : null;
    }
}
