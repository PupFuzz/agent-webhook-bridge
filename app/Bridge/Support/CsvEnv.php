<?php

namespace App\Bridge\Support;

/**
 * Split a comma-separated string into a `list<string>` of trimmed, non-empty
 * values. Empty / whitespace-only input yields `[]`. The pure split/trim/drop-
 * empties core shared by the comma-separated `BRIDGE_*` config idioms (the spawn
 * allowlist and the global echo ids), so that shape can't drift between them.
 *
 * The `env()` read stays in config/bridge.php: env() is only safe inside the
 * config directory (it returns null once config is cached — larastan's
 * noEnvCallsOutsideOfConfig), so this helper takes the already-resolved string.
 */
final class CsvEnv
{
    /**
     * @return list<string>
     */
    public static function parse(string $csv): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $csv)),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
