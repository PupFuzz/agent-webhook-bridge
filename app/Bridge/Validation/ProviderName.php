<?php

namespace App\Bridge\Validation;

/**
 * Provider URL-slug validator: lowercase letters/digits/underscore only.
 */
final class ProviderName
{
    public const PATTERN = '^[a-z0-9_]+$';

    public static function matches(string $value): bool
    {
        // `D` (PCRE_DOLLAR_ENDONLY): `$` matches only at the very end, not before
        // a trailing "\n" — so "github\n" can't slip a second line past the anchor.
        return preg_match('/'.self::PATTERN.'/D', $value) === 1;
    }
}
