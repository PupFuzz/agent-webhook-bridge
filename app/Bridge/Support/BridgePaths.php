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

    /**
     * The seen-cursor file for a serving agent (or the shared inbox when null) —
     * the single home for the cursor-path mapping, so bridge:inbox (writer) and
     * bridge:prune (retention) can never derive it two different ways:
     * null → inbox-seen.json, <agent> → inbox-seen-<agent>.json.
     */
    public static function seenPath(?string $agent): string
    {
        return $agent === null
            ? self::stateDir().'/inbox-seen.json'
            : self::agentSeenPath($agent);
    }

    /**
     * Read a seen-cursor into a list of ids (missing/garbage → []). A non-locking
     * peek — the reader that only needs to filter (bridge:inbox's unseen scan) uses
     * this; the read-modify-write callers go through updateSeenLocked instead.
     *
     * @return list<string>
     */
    public static function readSeen(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        return self::decodeSeen((string) file_get_contents($path));
    }

    /**
     * Parse a seen-cursor's raw JSON into a list of string ids (missing/garbage → []).
     * The single parse home so every reader — readSeen (by path) and updateSeenLocked
     * (from an open, locked handle) — agrees on the shape.
     *
     * @return list<string>
     */
    private static function decodeSeen(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Read-modify-write a seen-cursor with an exclusive lock held across the WHOLE
     * read → transform → write — the JSON-array sibling of filterJsonlLocked, and the
     * ONLY sanctioned writer of a cursor.
     *
     * Why this exists: readSeen() then a plain write locks only the write (DL-212), so
     * two concurrent cursor RMWs interleave — bridge:inbox advancing its cursor while a
     * prune sweep intersects the same file could have the prune write a set computed
     * from a pre-advance read, dropping a just-marked id back to unseen (one
     * re-delivered wake; bounded by DL-012's read-side collapse, never lost — but real,
     * card #4630). Holding one LOCK_EX across both callers' RMW serializes them: the
     * loser blocks, then re-reads the post-write cursor and transforms that. The write
     * is skipped when $transform returns an unchanged set, so a steady-state prune sweep
     * doesn't churn an unchanged cursor's mtime.
     *
     * Cursors stay install-user-owned (not group-shared, unlike per-agent inbox files)
     * — only the process running bridge:inbox/bridge:prune writes them (DL-006), so no
     * applyInboxPerms here. Unlike filterJsonlLocked this slurps the whole file: a
     * seen-cursor is a bounded id list, not the unbounded inbox stream.
     *
     * @param  callable(list<string>): list<string>  $transform
     */
    public static function updateSeenLocked(string $path, callable $transform): void
    {
        self::ensureDir(dirname($path));
        $h = @fopen($path, 'c+');
        if ($h === false) {
            throw new \RuntimeException("bridge: failed to open {$path} for a seen-cursor update");
        }

        try {
            if (! flock($h, LOCK_EX)) {
                throw new \RuntimeException("bridge: failed to lock {$path} for a seen-cursor update");
            }

            rewind($h);
            $current = self::decodeSeen((string) stream_get_contents($h));
            $next = $transform($current);

            if ($next === $current) {
                return;   // unchanged — don't churn the cursor's mtime
            }

            rewind($h);
            if (ftruncate($h, 0) === false
                || fwrite($h, (string) json_encode($next, JSON_UNESCAPED_SLASHES)) === false) {
                throw new \RuntimeException("bridge: failed to write {$path} seen-cursor");
            }
            fflush($h);
        } finally {
            @flock($h, LOCK_UN);
            @fclose($h);
        }
    }

    public static function sanitizeAgent(string $agent): string
    {
        return PathHelper::sanitizeSegment($agent, 'agent');
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
     * Create a directory (and parents) owner-only (0700) if absent. The ONE
     * place the bridge creates a state/secret-holding dir, so the mode can't
     * drift per call site (DL-016) — these dirs sit next to HMAC secrets/tokens.
     */
    public static function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    /**
     * Append one JSON-line record to a state file, creating parent dirs.
     * Keys are recursively sorted for deterministic output.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function appendJsonl(string $path, array $entry): void
    {
        self::ensureDir(dirname($path));
        self::writeFile(
            $path,
            json_encode(self::ksortRecursive($entry), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * Where a rewrite's kept-set stops living in memory and spills to a temp file.
     * Bounds a trim's heap cost independently of how large the inbox grew.
     */
    private const REWRITE_SPILL_BYTES = 8 * 1024 * 1024;

    /**
     * Read-modify-write a state file with an exclusive lock held across the WHOLE
     * sequence, and hand the raw lines to $transform.
     *
     * Why this exists: `readJsonl()` then `writeFile(..., LOCK_EX)` locks only the
     * write, so a concurrent `appendJsonl()` landing in between is truncated away by
     * the rewrite — the append returns OK, the receiver acks 200, and the intent is
     * gone with no redelivery. That is the one outcome the dispatch contract calls
     * unacceptable (see CLAUDE_ARCHITECTURE.md, treatment B: we would rather 5xx and
     * be re-delivered than silently lose a staged intent). Reproduced at 150k lines
     * with a ~330ms window (DL-199).
     *
     * `appendJsonl()` takes an advisory `LOCK_EX` on the same inode, so holding it
     * here serializes the two correctly: an append that arrives mid-rewrite BLOCKS
     * until the rewrite is done, then lands on the trimmed file. Blocking a
     * concurrent append briefly is the price of not losing it — and only a pass that
     * actually drops a line rewrites at all.
     *
     * STREAMED, one line at a time, deliberately: slurping the file would make peak
     * memory scale with it, and this runs under PHP-FPM's `memory_limit` (128M on the
     * reference install). Exhausting it raises an **E_ERROR**, which is NOT catchable
     * by the gate's `catch (\Throwable)` — the pass would die after its back-off
     * marker was already set, so retention would go inert for a day at a time while
     * `bridge:check` still reported it healthy. That is DL-012's silent-inertness
     * failure rebuilt inside its own fix, so peak memory is bounded to one line.
     *
     * Kept lines are copied VERBATIM rather than re-encoded — nothing here needs to
     * reformat them, and not touching them cannot corrupt them.
     *
     * @param  callable(array<string, mixed>): bool  $keep  per-line predicate
     * @return array{0:int,1:int} [lines read, lines kept]
     */
    public static function filterJsonlLocked(string $path, callable $keep): array
    {
        $h = @fopen($path, 'c+');
        if ($h === false) {
            throw new \RuntimeException("bridge: failed to open {$path} for rewrite");
        }

        $tmp = null;

        try {
            if (! flock($h, LOCK_EX)) {
                throw new \RuntimeException("bridge: failed to lock {$path} for rewrite");
            }

            // Spills to a real temp file past the threshold, so the kept set is
            // bounded on disk, not in the worker's heap.
            $tmp = fopen('php://temp/maxmemory:'.self::REWRITE_SPILL_BYTES, 'w+');
            if ($tmp === false) {
                throw new \RuntimeException('bridge: failed to open a rewrite buffer');
            }

            $read = 0;
            $kept = 0;
            rewind($h);
            while (($raw = fgets($h)) !== false) {
                $row = json_decode(rtrim($raw, "\n"), true);
                if (! is_array($row)) {
                    continue;   // same posture as readJsonl: undecodable lines are skipped
                }
                $read++;
                if ($keep($row)) {
                    $kept++;
                    fwrite($tmp, rtrim($raw, "\n")."\n");
                }
            }

            if ($kept === $read) {
                return [$read, $kept];   // nothing dropped — don't rewrite at all
            }

            rewind($tmp);
            if (fseek($h, 0) !== 0 || ftruncate($h, 0) === false || stream_copy_to_stream($tmp, $h) === false) {
                throw new \RuntimeException("bridge: failed to rewrite {$path}");
            }
            fflush($h);

            return [$read, $kept];
        } finally {
            if (is_resource($tmp)) {
                fclose($tmp);
            }
            @flock($h, LOCK_UN);
            @fclose($h);
        }
    }

    /**
     * file_put_contents that THROWS on a write-syscall failure (ENOSPC / EROFS /
     * EACCES) instead of returning false silently (#2055). For a durability write
     * (intent staging via appendJsonl) the throw propagates → treatment-B → 5xx →
     * upstream redelivers, rather than dropping the intent with a false 200. For
     * the cursor/inbox rewrites it surfaces a partial-write failure instead of
     * silently corrupting state. The single write primitive for the package.
     */
    public static function writeFile(string $path, string $contents, int $flags = 0): void
    {
        // Suppress the native warning (we raise a clear exception instead) but
        // capture its reason so the operator sees ENOSPC/EROFS/EACCES, not a bare
        // "write failed".
        if (@file_put_contents($path, $contents, $flags) === false) {
            $reason = error_get_last()['message'] ?? 'disk full / read-only fs / permissions?';

            throw new \RuntimeException("bridge: failed to write {$path} ({$reason})");
        }
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
