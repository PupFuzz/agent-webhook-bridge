<?php

namespace App\Bridge\Validation;

/**
 * Segment-shape scope_id validator. Accepts kanban's numeric `5`, GitHub's
 * `org/repo`, hyphenated names, dotted slugs. Rejects `..`, `//`, leading/
 * trailing `/`, and anything outside `[a-zA-Z0-9_-]` (intra-segment `.`,
 * segment-separator `/`). The `..` rejection is the path-traversal defense:
 * the scope becomes a filename component when loading the per-scope secret.
 *
 * PATTERN is kept character-for-character identical to lib/validators.py's
 * SCOPE_ID_PATTERN (the Python provisioner side must reject the same set).
 */
final class ScopeId
{
    public const PATTERN = '^[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*(/[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*)*$';

    public static function matches(string $value): bool
    {
        return preg_match('#'.self::PATTERN.'#', $value) === 1;
    }
}
