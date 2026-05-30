<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the per-install runtime-state directory. State files (inbox.jsonl,
 * inbox-<agent>.jsonl, seen cursors, handler-log.jsonl, registry-*.jsonl,
 * spawn-*.log) live under config('bridge.state_dir') — defaulting to
 * config('bridge.config_dir')/state (the v0.11.x layout, so Claude Code hooks +
 * external grep tooling keep working). Point BRIDGE_STATE_DIR outside the
 * 0700 secret-holding config_dir when a co-located different-OS-user agent must
 * read its own per-agent inbox via the group convention (see docs/multi-agent.md).
 */
final class BridgePaths
{
    public static function stateDir(): string
    {
        $stateDir = config('bridge.state_dir');
        if (is_string($stateDir) && $stateDir !== '') {
            return rtrim($stateDir, '/');
        }

        $dir = config('bridge.config_dir');
        if (! is_string($dir) || $dir === '') {
            throw new ConfigException('bridge.config_dir is not configured (set BRIDGE_CONFIG_DIR)');
        }

        return rtrim($dir, '/').'/state';
    }

    /**
     * Read a JSONL state file into a list of decoded rows (the symmetric reader
     * to appendJsonl). Missing file → []. Non-object/garbage lines are skipped.
     * One canonical parse so every state-file consumer agrees on the flags.
     *
     * @return list<array<string, mixed>>
     */
    public static function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $row = json_decode($raw, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Short-circuiting scan for a stable `id` — does NOT materialize the whole
     * file (the dedup check runs per intent per delivery, so the early return
     * matters as inbox files grow).
     */
    public static function jsonlContainsId(string $path, string $id): bool
    {
        if (! is_file($path)) {
            return false;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $row = json_decode($raw, true);
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The inbox lines for a serving agent (or the shared inbox when null) —
     * the single home for the layout-fallback contract: a per-agent file when
     * present (per-agent / both layout), else the shared inbox filtered by the
     * `agent` tag (so --agent works under every layout).
     *
     * @return list<array<string, mixed>>
     */
    public static function agentInboxLines(?string $agent): array
    {
        if ($agent === null) {
            return self::readJsonl(self::stateDir().'/inbox.jsonl');
        }

        $perAgent = self::agentInboxPath($agent);
        if (is_file($perAgent)) {
            return self::readJsonl($perAgent);
        }

        return array_values(array_filter(
            self::readJsonl(self::stateDir().'/inbox.jsonl'),
            fn (array $line) => ($line['agent'] ?? null) === $agent,
        ));
    }

    /**
     * Per-agent inbox/seen file path for the given serving agent. The agent name
     * is sanitized to a filesystem-safe token (defense-in-depth — agent names
     * already match the <agent>.yml convention, but a stray '/' must never
     * escape the state dir).
     */
    public static function agentInboxPath(string $agent): string
    {
        return self::stateDir().'/inbox-'.self::sanitizeAgent($agent).'.jsonl';
    }

    public static function agentSeenPath(string $agent): string
    {
        return self::stateDir().'/inbox-seen-'.self::sanitizeAgent($agent).'.json';
    }

    public static function sanitizeAgent(string $agent): string
    {
        $clean = preg_replace('/[^a-z0-9_-]+/i', '_', $agent) ?? '';

        return $clean === '' ? 'agent' : $clean;
    }

    /**
     * The configured per-agent inbox file mode (octal). BRIDGE_INBOX_FILE_MODE
     * is a string like "0640"; parsed as octal. Falls back to 0640.
     */
    public static function inboxFileMode(): int
    {
        $mode = config('bridge.inbox_file_mode', '0640');

        return is_string($mode) && $mode !== '' ? (int) octdec($mode) : 0640;
    }

    /**
     * Recognized BRIDGE_INBOX_LAYOUT values.
     *
     * @var list<string>
     */
    public const INBOX_LAYOUTS = ['shared', 'per-agent', 'both'];

    /**
     * The validated inbox layout. Throws (fail-closed) on an unrecognized value
     * rather than silently degrading to shared — consistent with the project's
     * fail-closed-on-misconfig posture.
     */
    public static function inboxLayout(): string
    {
        $layout = config('bridge.inbox_layout', 'shared');
        $layout = is_string($layout) && $layout !== '' ? $layout : 'shared';
        if (! in_array($layout, self::INBOX_LAYOUTS, true)) {
            throw new ConfigException(sprintf(
                "BRIDGE_INBOX_LAYOUT '%s' is invalid — use one of: %s",
                $layout,
                implode(', ', self::INBOX_LAYOUTS),
            ));
        }

        return $layout;
    }

    /**
     * Validate the inbox-surfacing config with actionable messages. Called by
     * bridge:check (operator preflight) and the dispatch path (fail-closed).
     */
    public static function validateInboxConfig(): void
    {
        $layout = self::inboxLayout();   // throws on an invalid layout

        // Cross-user group read is only safe under per-agent layout: a
        // group-readable state dir under shared/both also exposes the shared
        // inbox.jsonl (every agent's intents) to the group. Refuse the unsafe
        // combination with a message that names the fix.
        $group = config('bridge.inbox_group');
        if (is_string($group) && $group !== '' && $layout !== 'per-agent') {
            throw new ConfigException(
                "BRIDGE_INBOX_GROUP is set (cross-user read) but BRIDGE_INBOX_LAYOUT is '{$layout}'. ".
                "Cross-user group read requires 'per-agent' layout — under shared/both the group-readable state dir ".
                'would also expose the shared inbox.jsonl (every agent\'s intents). Set BRIDGE_INBOX_LAYOUT=per-agent.'
            );
        }

        $mode = config('bridge.inbox_file_mode', '0640');
        if (! is_string($mode) || preg_match('/^0?[0-7]{3}$/', $mode) !== 1) {
            throw new ConfigException(sprintf(
                "BRIDGE_INBOX_FILE_MODE '%s' must be an octal file mode like 0640.",
                is_scalar($mode) ? (string) $mode : gettype($mode),
            ));
        }
    }

    /**
     * Apply the configured mode (+ group, when BRIDGE_INBOX_GROUP is set) to a
     * per-agent inbox file so a co-located OS-user agent in that group can read
     * its own inbox. Best-effort but NOT silent: a chmod/chgrp the install user
     * can't make is logged (the operator's group-setup to fix) rather than
     * either 5xx-ing staging or vanishing. This sets perms on the FILE only —
     * the enclosing state dir's traversability is the operator's setup
     * (`install -d -m 0750 -g <group>`), so the bridge never widens a directory
     * that may hold other agents' state. See docs/multi-agent.md cross-user.
     */
    public static function applyInboxPerms(string $path): void
    {
        if (! is_file($path)) {
            return;
        }
        if (@chmod($path, self::inboxFileMode()) === false) {
            Log::warning(sprintf('bridge: chmod %s to 0%s failed — check file ownership / BRIDGE_INBOX_FILE_MODE', $path, decoct(self::inboxFileMode())));
        }

        $group = config('bridge.inbox_group');
        if (is_string($group) && $group !== '' && @chgrp($path, $group) === false) {
            Log::warning(sprintf(
                "bridge: chgrp %s to '%s' failed — the install user must be a member of that group (docs/multi-agent.md cross-user setup)",
                $path,
                $group,
            ));
        }
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
