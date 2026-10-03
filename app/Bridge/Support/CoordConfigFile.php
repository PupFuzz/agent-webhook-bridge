<?php

namespace App\Bridge\Support;

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
 * THE FAULTS ARE DISTINCT BECAUSE THEIR REMEDIES ARE. Unset and not-absolute are `.env` edits;
 * unreadable is a path or a permission, and is UID-RELATIVE ({@see UnreadableFileException}) —
 * `bridge:check` reading it as the operator says nothing about the FPM pool user; malformed is
 * the file's own bytes, the same for every reader.
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

        $read = self::read($path);
        self::$cache[$path] = [$signature, $read];

        return $read;
    }

    private static function read(string $path): self
    {
        try {
            $raw = UntrustedPathContents::read($path, 'coordination.config.json');
        } catch (UnreadableFileException $e) {
            return new self($path, self::UNREADABLE, $e->getMessage());
        }
        if ($raw === null) {
            return new self($path, self::UNREADABLE, "there is no file at {$path} as far as this process can see (absent, or a directory above it is not traversable by this OS user)");
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
        return match ($this->fault) {
            self::UNSET => self::SETTING.' is not set in this install\'s .env',
            self::NOT_ABSOLUTE => self::SETTING." is '{$this->path}', which is not an absolute path",
            self::UNREADABLE => "the coord roster at {$this->path} could not be read: {$this->detail}",
            self::MALFORMED => "the coord roster at {$this->path} is not a JSON object ({$this->detail})",
            default => "the coord roster at {$this->path} was read",
        };
    }
}
