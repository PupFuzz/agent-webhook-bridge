<?php

namespace App\Bridge\Validation;

/**
 * channel.name validator: lowercase letters/digits/underscore/hyphen,
 * non-empty. Stricter than the channel server's free-form BRIDGE_CHANNEL_NAME
 * because the bridge composes it into a UDS socket-path component, so it must
 * be filesystem-safe (no slashes/colons/spaces/uppercase). PATTERN mirrors
 * lib/validators.py's CHANNEL_NAME_PATTERN.
 */
final class ChannelName
{
    public const PATTERN = '^[a-z0-9_-]+$';

    public static function matches(string $value): bool
    {
        return $value !== '' && preg_match('/'.self::PATTERN.'/', $value) === 1;
    }
}
