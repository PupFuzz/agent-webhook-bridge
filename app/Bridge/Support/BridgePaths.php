<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * Resolves the per-install runtime-state directory. State files (inbox.jsonl,
 * handler-log.jsonl, registry-*.jsonl, spawn-*.log) live under
 * config('bridge.config_dir')/state — preserved from v0.11.x so Claude Code
 * hooks + external grep tooling keep working. One install per agent, so the
 * install's config dir IS the agent's.
 */
final class BridgePaths
{
    public static function stateDir(): string
    {
        $dir = config('bridge.config_dir');
        if (! is_string($dir) || $dir === '') {
            throw new ConfigException('bridge.config_dir is not configured (set BRIDGE_CONFIG_DIR)');
        }

        return rtrim($dir, '/').'/state';
    }

    /**
     * Append one JSON-line record to a state file, creating parent dirs.
     * Keys are recursively sorted for deterministic output (matches the
     * Python handlers' json.dumps(sort_keys=True)).
     *
     * @param  array<string, mixed>  $entry
     */
    public static function appendJsonl(string $path, array $entry): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents(
            $path,
            json_encode(self::ksortRecursive($entry), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function ksortRecursive(array $value): array
    {
        ksort($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::ksortRecursive($v);
            }
        }

        return $value;
    }
}
