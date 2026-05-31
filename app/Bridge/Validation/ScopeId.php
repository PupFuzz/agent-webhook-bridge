<?php

namespace App\Bridge\Validation;

/**
 * Segment-shape scope_id validator. Accepts kanban's numeric `5`, GitHub's
 * `org/repo`, hyphenated names, dotted slugs. Rejects `..`, `//`, leading/
 * trailing `/`, and anything outside `[a-zA-Z0-9_-]` (intra-segment `.`,
 * segment-separator `/`). The `..` rejection is the path-traversal defense:
 * the scope becomes a filename component when loading the per-scope secret.
 *
 * PATTERN is the single source of truth for scope-id validation; any change
 * must be matched by the test suite (it is also the path-traversal boundary).
 */
final class ScopeId
{
    public const PATTERN = '^[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*(/[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*)*$';

    public static function matches(string $value): bool
    {
        // `D` (PCRE_DOLLAR_ENDONLY): `$` matches only at the very end, not before
        // a trailing "\n" — so "5\n" / "org/repo\n" can't slip a second line past
        // the anchor (this is also the path-traversal boundary).
        return preg_match('#'.self::PATTERN.'#D', $value) === 1;
    }
}
