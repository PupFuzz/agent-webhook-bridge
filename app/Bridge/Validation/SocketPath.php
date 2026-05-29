<?php

namespace App\Bridge\Validation;

/**
 * Format-only validator for a channel.socket path. Mirrors
 * lib/validators.py's validate_socket_path_format: a non-empty absolute path,
 * no null byte, no `..` segment. Does NOT stat() the path — existence and
 * UDS-file-type checks happen at dispatch time, since the socket server may
 * not be running at config-load. The `..` rejection prevents escaping a
 * chmod-700 parent at dispatch time.
 */
final class SocketPath
{
    public static function isValid(string $value): bool
    {
        if ($value === '' || str_contains($value, "\x00")) {
            return false;
        }

        if (! str_starts_with($value, '/')) {
            return false;
        }

        return ! in_array('..', explode('/', $value), true);
    }
}
