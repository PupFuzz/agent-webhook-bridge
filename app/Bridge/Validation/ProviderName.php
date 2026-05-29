<?php

namespace App\Bridge\Validation;

/**
 * Provider URL-slug validator: lowercase letters/digits/underscore only.
 * PATTERN mirrors lib/validators.py's PROVIDER_NAME_PATTERN.
 */
final class ProviderName
{
    public const PATTERN = '^[a-z0-9_]+$';

    public static function matches(string $value): bool
    {
        return preg_match('/'.self::PATTERN.'/', $value) === 1;
    }
}
