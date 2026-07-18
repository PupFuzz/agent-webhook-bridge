<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

final class PathHelper
{
    /**
     * Expand a leading `~` or `~/` to the current user's home directory for the
     * config's path fields. Returns the value unchanged when there is no home
     * dir or no leading ~.
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
     * Expand runtime placeholders in a channel.socket path (DL-039) so an
     * operator can write a uid-agnostic literal instead of pinning
     * `/run/user/<uid>` — which silently breaks when the install is restored on a
     * host where the OS uid changed:
     *   ${XDG_RUNTIME_DIR} → $XDG_RUNTIME_DIR, or `/run/user/<uid>` when the env
     *                        is unset (its systemd-canonical value; PHP-FPM
     *                        usually doesn't inherit XDG_RUNTIME_DIR, so deriving
     *                        from the running uid is the robust resolution).
     *   ${uid}             → the running process uid.
     * Returns the path unchanged when it contains no `${` token. Throws (fail-
     * closed) when a token is present but unresolvable, so a uid-agnostic config
     * never silently resolves to an empty/wrong path.
     */
    public static function expandRuntimeTokens(string $path): string
    {
        if (! str_contains($path, '${')) {
            return $path;
        }

        $uid = function_exists('posix_getuid') ? posix_getuid() : null;
        $replacements = [];

        if (str_contains($path, '${XDG_RUNTIME_DIR}')) {
            $xdg = getenv('XDG_RUNTIME_DIR');
            if ($xdg === false || $xdg === '') {
                if ($uid === null) {
                    throw new ConfigException('channel.socket uses ${XDG_RUNTIME_DIR} but it is unset and the uid is undeterminable (no posix extension) — set channel.socket to an explicit path');
                }
                $xdg = '/run/user/'.$uid;
            }
            $replacements['${XDG_RUNTIME_DIR}'] = rtrim($xdg, '/');
        }

        if (str_contains($path, '${uid}')) {
            if ($uid === null) {
                throw new ConfigException('channel.socket uses ${uid} but the running uid is undeterminable (no posix extension) — set channel.socket to an explicit path');
            }
            $replacements['${uid}'] = (string) $uid;
        }

        // strtr does ONE simultaneous left-to-right pass, so a token that appears
        // INSIDE a substituted value (e.g. an XDG_RUNTIME_DIR env that itself
        // contains '${uid}') is left literal, never re-expanded.
        $path = strtr($path, $replacements);

        // Fail-closed on any unconsumed token (a typo like ${XDG_RUNTIME}) rather
        // than letting it ride as a literal directory name to a confusing later
        // error — matches the fail-closed config posture.
        if (str_contains($path, '${')) {
            throw new ConfigException("channel.socket has an unrecognized \${...} placeholder (only \${XDG_RUNTIME_DIR} and \${uid} are supported): {$path}");
        }

        return $path;
    }

    /**
     * Turn a caller's opaque key (target id, debounce key, agent name) into one
     * filesystem-safe path segment: every run of characters outside [a-z0-9_-]
     * (case-insensitive) collapses to a single '_', and an empty result takes
     * `$fallback`. Dots are NOT in the kept set, so a '.'/'..' component can
     * never form — the result is always safe as a standalone path component,
     * not only when a caller wraps it between a literal prefix and suffix.
     *
     * The single sanitizer for the package (card #4497): callers that need
     * slashes preserved must URL-encode first.
     */
    public static function sanitizeSegment(string $value, string $fallback = '_'): string
    {
        $clean = preg_replace('/[^a-z0-9_-]+/i', '_', $value) ?? '';

        return $clean === '' ? $fallback : $clean;
    }
}
