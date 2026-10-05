<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\PathResolvesToNoFileException;
use App\Bridge\Exceptions\UnreadableFileException;

/**
 * ONE read of the coordination project's `coordination.config.json`: its decoded contents, or
 * WHY there are none, named as one of four faults (card#11172 / DL-450).
 *
 * ⭐ THE RUNTIME READS IT. The coord roster in this file is the single store of each agent's
 * kanban user id (card#10868 Q1), and since DL-450 the bridge reads it where the id is used —
 * the take, the correction's assignee arm, kanban event attribution and self echo-suppression —
 * rather than from a YAML copy. {@see configured} is that read: the setting
 * `bridge.coord_config_path` and nothing else. No ambient `$COORD_CONFIG`, which PHP-FPM does
 * not inherit; no home-relative default, which would answer about whichever OS user asked.
 *
 * THE FAULTS ARE DISTINCT BECAUSE THEIR REMEDIES — AND WHO THEY HOLD FOR — DIFFER. Unset and
 * not-absolute are `.env` edits. ABSENT (no file, under ancestors this process can traverse),
 * NOT_A_FILE (a symlink, a directory/FIFO/socket/device, or a file past the read bound) and
 * MALFORMED (bytes that are not a JSON object) are the same for EVERY reader, the receiver's
 * pool user included. Only UNREADABLE — a permission refusal, or a path this process could not
 * resolve — is UID-RELATIVE ({@see UnreadableFileException}): `bridge:check` reading as the
 * operator then says nothing about the FPM pool user.
 *
 * ⚠ A RELATIVE PATH IS REFUSED rather than resolved: it would resolve against the working
 * directory, which is the checkout under the CLI and `public/` under FPM, so the two would read
 * different files under one setting.
 *
 * THE READER IS {@see UntrustedPathContents}, as the writeback compares' read was (card#9121):
 * the file belongs to the coordination project, and `bridge:check` routinely reads it as root. So
 * a SYMLINK at the path is refused, by the CLI and the runtime alike — point the setting at the
 * file itself.
 *
 * CACHED PER PROCESS, KEYED ON THE FILE'S `lstat` — device, inode, size, mtime and ctime — so a
 * changed file is re-read and an unchanged one is not. Under PHP-FPM a static lives for one
 * request, so the cache only saves the second read inside a delivery or a tool call; a CLI
 * process keeps it for its run. ⚠ The bound: PHP's `stat` times are whole seconds, so a rewrite
 * IN PLACE (same inode) to the same size within the same second as the cached read is not seen
 * by this process. A write through a new file and a rename — how editors and the coord tooling
 * replace a file — changes the inode and is always seen.
 */
final class CoordConfigFile
{
    public const UNSET = 'unset';

    public const NOT_ABSOLUTE = 'not_absolute';

    public const ABSENT = 'absent';

    public const NOT_A_FILE = 'not_a_file';

    public const UNREADABLE = 'unreadable';

    public const MALFORMED = 'malformed';

    /** The `.env` key an operator sets — named in every message that asks them to. */
    public const SETTING = 'BRIDGE_COORD_CONFIG_PATH';

    /** @var array<string, array{0: string, 1: self}> path → [lstat signature, the read it answered] */
    private static array $cache = [];

    /**
     * @param  ?string  $fault  one of the constants above, or null when $config is the file
     * @param  string  $detail  what the reader saw, for the message (empty when readable)
     * @param  array<mixed>  $config
     */
    private function __construct(
        public readonly ?string $path,
        public readonly ?string $fault,
        public readonly string $detail,
        private readonly array $config = [],
    ) {}

    /** The file the runtime reads: `bridge.coord_config_path`, and only that. */
    public static function configured(): self
    {
        $path = config('bridge.coord_config_path');

        return self::at(is_string($path) && trim($path) !== '' ? trim($path) : null);
    }

    public static function at(?string $path): self
    {
        if ($path === null) {
            return new self(null, self::UNSET, '');
        }
        if (! str_starts_with($path, '/')) {
            return new self($path, self::NOT_ABSOLUTE, '');
        }

        clearstatcache(true, $path);
        $stat = @lstat($path);
        $signature = $stat === false
            ? 'none'
            : implode(':', [$stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']]);
        if (isset(self::$cache[$path]) && self::$cache[$path][0] === $signature) {
            return self::$cache[$path][1];
        }

        $read = self::read($path, $stat);
        if ($stat !== false) {
            // A path with no `lstat` is never cached: its answer can turn on an ancestor's
            // permissions, which no signature here would see change.
            self::$cache[$path] = [$signature, $read];
        }

        return $read;
    }

    /**
     * @param  array<int|string, int>|false  $stat  the `lstat` the cache key was taken from
     */
    private static function read(string $path, array|false $stat): self
    {
        $shown = PastedSecretShape::displayPathSetting($path);
        if ($stat === false) {
            // `lstat` answers false for a removed file AND for one under a directory this
            // process may not traverse; only the first is the same for every reader.
            return PathVisibility::ancestorIsTraversable($path)
                ? new self($path, self::ABSENT, "there is no file at {$shown}")
                : new self($path, self::UNREADABLE, "a directory above {$shown} is not traversable by this OS user, so whether the file exists was not measured");
        }
        $refusal = UntrustedPathContents::lstatRefusal($stat);
        if ($refusal !== null) {
            return new self($path, self::NOT_A_FILE, $refusal);
        }

        try {
            $raw = UntrustedPathContents::read($path, 'coordination.config.json');
        } catch (PathResolvesToNoFileException $e) {
            return new self($path, self::NOT_A_FILE, $e->getMessage());
        } catch (UnreadableFileException $e) {
            return new self($path, self::UNREADABLE, $e->getMessage());
        }
        if ($raw === null) {
            return new self($path, self::ABSENT, "there is no file at {$shown} (it was removed while being read)");
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return new self($path, self::MALFORMED, json_last_error() === JSON_ERROR_NONE ? 'it decodes to a JSON scalar' : json_last_error_msg());
        }

        return new self($path, null, '', $decoded);
    }

    public function readable(): bool
    {
        return $this->fault === null;
    }

    /**
     * The decoded file. Only a readable read has one — asking an unreadable one is a caller bug.
     *
     * @return array<mixed>
     */
    public function config(): array
    {
        if ($this->fault !== null) {
            throw new \LogicException('CoordConfigFile::config() asked of a read that failed ('.$this->fault.')');
        }

        return $this->config;
    }

    /**
     * Why there is no file to read, as one clause naming the setting or the path — the words every
     * refusal, log line and `bridge:check` finding about this read uses, so they cannot drift.
     */
    public function faultClause(): string
    {
        $shown = $this->shownPath();

        return match ($this->fault) {
            self::UNSET => self::SETTING.' is not set in this install\'s .env',
            self::NOT_ABSOLUTE => self::SETTING." is '{$shown}', which is not an absolute path",
            self::ABSENT => "there is no coord roster at {$shown}",
            self::NOT_A_FILE => "the coord roster at {$shown} is not a file the bridge will read: {$this->detail}",
            self::UNREADABLE => "the coord roster at {$shown} could not be read by this OS user: {$this->detail}",
            self::MALFORMED => "the coord roster at {$shown} is not a JSON object ({$this->detail})",
            default => "the coord roster at {$shown} was read",
        };
    }

    /**
     * The setting's value as a message may print it — {@see PastedSecretShape::displayPathSetting()}.
     * `path` stays the value as read, for the read; every message naming the file uses this.
     */
    public function shownPath(): string
    {
        return PastedSecretShape::displayPathSetting((string) $this->path);
    }
}
