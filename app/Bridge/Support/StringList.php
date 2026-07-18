<?php

namespace App\Bridge\Support;

/**
 * Tolerant coercion of an untrusted YAML/JSON value to a `list<string>`.
 *
 * A non-array container yields `[]`; each element is stringified when scalar
 * and mapped to `''` when not (never throws, never drops). This is the lenient
 * counterpart to ClassifierConfig's strict parsers, which lowercase, reject
 * empties, and throw on a malformed shape — do NOT fold the two together.
 */
final class StringList
{
    /**
     * @return list<string>
     */
    public static function coerce(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $x): string => is_scalar($x) ? (string) $x : '',
            $value,
        ));
    }
}
