<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * Read a required config sub-mapping from a parsed YAML/JSON block: an absent or
 * null value defaults to empty; a present non-array value is malformed and throws
 * rather than degrading. The strict counterpart to {@see StringList::coerce} and
 * {@see AgentConfig::section} tolerant coercion — do NOT fold the two together.
 *
 * `$label` is the fully-qualified key name the caller wants in the thrown message
 * (a bare `surface`, or a prefixed `classifier.config.impl_ci_wake`), so callers
 * keep their own error vocabulary while sharing the shape check.
 */
final class ConfigMapping
{
    /**
     * @param  array<mixed>  $raw
     * @return array<mixed>
     */
    public static function require(array $raw, string $key, string $label): array
    {
        $value = $raw[$key] ?? null;
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            throw new ConfigException("{$label} must be a mapping");
        }

        return $value;
    }
}
