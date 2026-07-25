<?php

namespace App\Bridge\Validation;

/**
 * Format-only validator for the `channel:` block's filesystem paths
 * (`channel.socket`, and `channel.server_path` since DL-229): a non-empty
 * absolute path, no null byte, no `..` segment. Does NOT stat() the path —
 * existence checks happen later (at dispatch time for the socket, since the
 * socket server may not be running at config-load; at `bridge:check` time for
 * the deployed snapshot). The `..` rejection prevents escaping a chmod-700
 * parent at dispatch time.
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
