<?php

namespace App\Bridge\Support;

final class PathHelper
{
    /**
     * Expand a leading `~` or `~/` to the current user's home directory,
     * mirroring Python's os.path.expanduser for the config's path fields.
     * Returns the value unchanged when there is no home dir or no leading ~.
     */
    public static function expandUser(string $path): string
    {
        if ($path !== '~' && ! str_starts_with($path, '~/')) {
            return $path;
        }

        $home = getenv('HOME');
        if ($home === false || $home === '') {
            return $path;
        }

        return $home.substr($path, 1);
    }

    /**
     * Replace any character outside [a-zA-Z0-9._-] with '_', so a caller's
     * opaque key (target id, debounce key) is safe as a single filesystem
     * path segment. Callers that need slashes etc. must URL-encode first.
     */
    public static function sanitizeSegment(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $value) ?? '';
    }
}
