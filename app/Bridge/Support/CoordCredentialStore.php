<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\PathResolvesToNoFileException;
use App\Bridge\Exceptions\UnreadableFileException;

/**
 * ONE read of the coordination framework's credential store, `credentials.ini`: which `[github]` key
 * a repo routes to, and which token FILE that key names — never a token (card#11208 / DL-456).
 *
 * ⭐ WHY THE BRIDGE READS THE STORE ITSELF. A fine-grained PAT covers one resource owner, so an
 * install mapping repos under two owners needs a token per repo, and the store already holds one:
 * `[git-credential-map]` routes `host/owner/repo` to a key, and `[github] <key>_file` names the
 * file. The framework's helper (`git-credential-coord`) answers the same question, but as a
 * subprocess that finds the store through `$HOME` / `$COORD_CREDENTIALS`, which PHP-FPM does not
 * have — so the receiver never reached it (DL-184). Reading the two sections in-process gives the
 * receiver and the CLI one answer, and copies nothing: the bridge reads the file the store points at.
 *
 * ⛔ THE SEAM (canon #7). The INI format is the FRAMEWORK's: the bridge is a consumer of a file it
 * does not own. The framework has agreed to treat the two sections' shape as a published contract
 * (rt#593; framework card#11209 publishes the grammar). What this class reads is a declared SUBSET
 * of what the framework's own parser (`coord_credentials.py`, Python `configparser` with
 * `interpolation=None` and case-preserving option names) accepts; anything the subset does not
 * cover is reported as MALFORMED by name, never guessed at:
 *  - the WHOLE file must parse as that parser would: no line before the first `[section]`, no line
 *    that is neither a comment (`#`/`;` first), a `[section]` nor a `name = value` / `name: value`,
 *    no empty name, no section opened twice, no name set twice in one section, valid UTF-8;
 *  - in `[git-credential-map]` and `[github]`: names (and the map's values) are printable ASCII with
 *    no whitespace, no value continues onto an indented line, and `[DEFAULT]` sets nothing (that
 *    parser copies `[DEFAULT]` into every section);
 *  - a map value, or the `<key>`/`<key>_file` value asked for, holding `%%` or `%(` is refused, as
 *    the framework's reader refuses it.
 * Routing is the helper's, exactly: `github.com/owner/repo`, then `github.com/owner`, then
 * `github.com`, matched with the case written; a blank value moves on to the next candidate. The
 * `[github]` name is matched exactly, then case-insensitively, as the framework's reader does.
 *
 * ⛔ WHERE THE BRIDGE DEPARTS FROM THE HELPER, deliberately, and each is fail-loud — none can hand a
 * repo a token the helper would not: an INLINE `[github] <key>` value (deprecated by the framework,
 * card#7226) is refused rather than read, so no token value ever passes through this parser; a
 * RELATIVE pointer is refused (it resolves against whatever directory the reader runs in); a `~`
 * pointer expands against the home of the STORE'S OWNER, who is the user the helper runs as, not the
 * home of whichever process asks; `~user/` is refused.
 *
 * ⛔ NO STORE TEXT THAT COULD BE A TOKEN REACHES A MESSAGE: a malformed store is named by line
 * number, and a name with a credential's shape (a token pasted as a map value, which then stands as
 * the `[github]` key) is printed through {@see PastedSecretShape::displayName()} — the framework's
 * `_safe_coordinate`. These messages are logged on every delivery and printed by `bridge:check`.
 *
 * ⚑ A MISSING STORE IS AN EMPTY STORE (every repo unmapped), as for the framework's reader — but
 * only where this process can see that it is missing: under a directory it cannot traverse that is
 * UNREADABLE, and a store that is present and cannot be read or parsed is a fault. The caller must
 * resolve NO token for any repo then, because the store may map it.
 *
 * The setting: `bridge.coord_credentials_path`, else `credentials.ini` beside
 * `bridge.coord_config_path` (DL-450's roster). That default is a GUESS that holds on a solo seat
 * only: on a pm install the roster sits in the coordination repo checkout while the framework keeps
 * the store at `~/.config/coord/credentials.ini`, so the default names a file that is not there, and
 * an absent store is an empty one — a mapped repo silently falls to the single token file
 * (card#11619). {@see missingAtDefault()} is what lets `bridge:check` say so. The
 * ambient `$COORD_CREDENTIALS` is never read — the reason DL-450 Decision 1 gives for `$COORD_CONFIG`.
 * The file is read with {@see UntrustedPathContents}: it belongs to the coordination project's user
 * and `bridge:check` may read it as root, so a symlink at the path is refused.
 */
final class CoordCredentialStore
{
    public const SETTING = 'BRIDGE_COORD_CREDENTIALS_PATH';

    public const FILE = 'credentials.ini';

    public const MAP_SECTION = 'git-credential-map';

    public const GITHUB_SECTION = 'github';

    /** The value the framework's template seeds for an unfilled slot. */
    public const PLACEHOLDER = 'REPLACE_ME';

    private const HOST = 'github.com';

    public const UNSET = 'unset';

    public const NOT_ABSOLUTE = 'not_absolute';

    public const NOT_A_FILE = 'not_a_file';

    public const UNREADABLE = 'unreadable';

    public const MALFORMED = 'malformed';

    private const READ_SECTIONS = [self::MAP_SECTION, self::GITHUB_SECTION];

    /** A name (and a map value) the subset reads: printable ASCII, no whitespace. */
    private const NAME = '/\A[\x21-\x7e]+\z/';

    /** Python's `str.isspace()` set, near enough: ASCII whitespace, the C0 separators, NEL and Unicode space separators. */
    private const SPACE = '[\s\x{1c}-\x{1f}\x{85}\p{Zs}\x{2028}\x{2029}]';

    /**
     * @param  array<string, array<string, string>>  $sections  the two read sections, name => value
     */
    private function __construct(
        public readonly ?string $path,
        public readonly ?string $fault,
        public readonly string $detail,
        public readonly bool $present,
        private readonly array $sections = [],
        private readonly ?int $owner = null,
    ) {}

    /** Whether the path came from the roster's directory rather than from the setting. */
    private bool $defaulted = false;

    /** The store the bridge reads: the setting, else `credentials.ini` beside the coord roster. */
    public static function configured(): self
    {
        $explicit = config('bridge.coord_credentials_path');
        if (is_string($explicit) && trim($explicit) !== '') {
            return self::at(trim($explicit));
        }
        $roster = config('bridge.coord_config_path');
        if (is_string($roster) && trim($roster) !== '' && str_starts_with(trim($roster), '/')) {
            $store = self::at(dirname(trim($roster)).'/'.self::FILE);
            $store->defaulted = true;

            return $store;
        }

        return new self(null, self::UNSET, '', false);
    }

    public static function at(string $path): self
    {
        if (! str_starts_with($path, '/')) {
            return new self($path, self::NOT_ABSOLUTE, '', false);
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return PathVisibility::ancestorIsTraversable($path)
                ? new self($path, null, '', false)
                : new self($path, self::UNREADABLE, 'a directory above it is not traversable by this OS user, so whether it exists was not measured', false);
        }
        $refusal = UntrustedPathContents::lstatRefusal($stat);
        if ($refusal !== null) {
            return new self($path, self::NOT_A_FILE, $refusal, true);
        }
        try {
            $raw = UntrustedPathContents::read($path, 'coord credential store');
        } catch (PathResolvesToNoFileException $e) {
            return new self($path, self::NOT_A_FILE, $e->getMessage(), true);
        } catch (UnreadableFileException $e) {
            return new self($path, self::UNREADABLE, $e->getMessage(), true);
        }
        if ($raw === null) {
            return new self($path, null, '', false);
        }
        [$sections, $why] = self::parse($raw);
        if ($why !== null) {
            return new self($path, self::MALFORMED, $why, true);
        }

        return new self($path, null, '', true, $sections, app(ProcessIdentity::class)->ownerOf($path));
    }

    public function readable(): bool
    {
        return $this->fault === null;
    }

    /**
     * The setting is unset, the path was guessed from the roster's directory, and this process can
     * see that no store is there — read as an empty store, which is right only where the guess was.
     */
    public function missingAtDefault(): bool
    {
        return $this->defaulted && $this->fault === null && ! $this->present;
    }

    /**
     * Why there is no store to read, as one clause naming the setting or the path — the words every
     * resolution problem and `bridge:check` finding about this read use.
     */
    public function faultClause(): string
    {
        $path = $this->shownPath();

        return match ($this->fault) {
            self::UNSET => 'where the coord credential store is was not determined: neither '.self::SETTING.' nor BRIDGE_COORD_CONFIG_PATH (an absolute path, which the store sits beside) is set in this install\'s .env',
            self::NOT_ABSOLUTE => self::SETTING." is '{$path}', which is not an absolute path",
            self::NOT_A_FILE => "the coord credential store at {$path} is not a file the bridge will read: {$this->detail}",
            self::UNREADABLE => "the coord credential store at {$path} could not be read by this OS user: {$this->detail}",
            self::MALFORMED => "the coord credential store at {$path} is not in the shape the bridge reads ({$this->detail}); the offending text is not shown, because in that file a malformed line is often a bare token",
            default => "the coord credential store at {$path} was read",
        };
    }

    /**
     * The store's path as a message may print it — {@see PastedSecretShape::displayPathSetting()}.
     * `path` stays the value as read, for the read; every message naming the store uses this.
     */
    public function shownPath(): string
    {
        return PastedSecretShape::displayPathSetting((string) $this->path);
    }

    /**
     * The `[github]` key a repo routes to and the map name that matched, or null when the store maps
     * it nowhere (or there is no store). Only a readable store may be asked.
     *
     * @return ?array{key: string, matched: string}
     */
    public function routeFor(string $repo): ?array
    {
        $this->assertReadable();
        $map = $this->sections[self::MAP_SECTION] ?? [];
        foreach (self::candidates($repo) as $candidate) {
            $key = $map[$candidate] ?? '';
            if ($key !== '') {
                return ['key' => $key, 'matched' => $candidate];
            }
        }

        return null;
    }

    /**
     * The map names tried for a repo, most specific first — the helper's ladder.
     *
     * @return list<string>
     */
    public static function candidates(string $repo): array
    {
        $path = trim($repo, '/');
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }
        $candidates = $path !== '' ? [self::HOST.'/'.$path] : [];
        if ($path !== '' && str_contains($path, '/')) {
            $candidates[] = self::HOST.'/'.explode('/', $path)[0];
        }
        $candidates[] = self::HOST;

        return $candidates;
    }

    /**
     * The absolute token-file path `[github] <key>_file` names, or why there is none. The clause
     * never renders the pointer's text: a token pasted into a `_file` slot would be printed with it.
     *
     * The flag is true only where the pointer may well be right and THIS process could not expand
     * it — a `~` pointer whose owner's home could not be read (card#11600): that is undetermined,
     * not a store every reader would refuse.
     *
     * @return array{0: ?string, 1: bool, 2: ?string} [path, false, null] or [null, undetermined, why]
     */
    public function tokenFileFor(string $key): array
    {
        $this->assertReadable();
        $github = $this->sections[self::GITHUB_SECTION] ?? [];
        $name = PastedSecretShape::displayName($key);
        $file = PastedSecretShape::displayName($key.'_file');
        $inline = self::lookup($github, $key);
        if ($inline !== null && $inline !== '') {
            return [null, false, "[github] {$name} holds an INLINE value, and the bridge reads only a `{$file}` pointer — move the token into a file (chmod 600) and point `{$file}` at it (the framework's `/coord:update --area credential-indirection` does this); the value is not shown"];
        }
        $pointer = self::lookup($github, $key.'_file');
        if (($pointer === null || $pointer === '') && PastedSecretShape::looksLikePastedSecret($key)) {
            return [null, false, "[git-credential-map] maps this repo to {$name}, which has the shape of a CREDENTIAL rather than a key name, and [github] has no pointer for it — a map value names a [github] key whose `<key>_file` holds the token's path, never the token; its text is not shown"];
        }
        if ($pointer === null || $pointer === '') {
            return [null, false, "[github] has no `{$file}` pointer, so the key [git-credential-map] names has no token file"];
        }
        if (str_contains($pointer, '%%') || str_contains($pointer, '%(')) {
            return [null, false, "[github] {$file} holds `%%` or `%(`, which the store's own reader refuses"];
        }
        if ($pointer === '~' || str_starts_with($pointer, '~/')) {
            $home = $this->ownerHome();
            if ($home === null) {
                return [null, true, "[github] {$file} starts with `~`, and the home directory of the store's owner could not be read (no posix extension, or an owner this process could not identify)"];
            }

            return [rtrim($home, '/').substr($pointer, 1), false, null];
        }
        if (! str_starts_with($pointer, '/')) {
            return [null, false, "[github] {$file} is not an absolute path (nor `~/…`): a relative path resolves against whatever directory the reader runs in"];
        }

        return [$pointer, false, null];
    }

    /** The store file's owner, or null when it could not be read. Only a readable store may be asked. */
    public function owner(): ?int
    {
        $this->assertReadable();

        return $this->owner;
    }

    private function ownerHome(): ?string
    {
        if ($this->owner === null || ! function_exists('posix_getpwuid')) {
            return null;
        }
        $pw = posix_getpwuid($this->owner);

        return is_array($pw) && $pw['dir'] !== '' ? $pw['dir'] : null;
    }

    private function assertReadable(): void
    {
        if ($this->fault !== null) {
            throw new \LogicException('CoordCredentialStore asked of a read that failed ('.$this->fault.')');
        }
    }

    /**
     * Exact name first, then case-insensitively — the framework reader's `_get`.
     *
     * @param  array<string, string>  $section
     */
    private static function lookup(array $section, string $name): ?string
    {
        if (array_key_exists($name, $section)) {
            return $section[$name];
        }
        foreach ($section as $candidate => $value) {
            if (strtolower((string) $candidate) === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The subset parse. Returns the two read sections, or why the file is outside the subset — by
     * LINE NUMBER, never by its text.
     *
     * @return array{0: array<string, array<string, string>>, 1: ?string}
     */
    private static function parse(string $text): array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return [[], 'it is not valid UTF-8'];
        }
        $sections = [];
        $seenSections = [];
        $seenNames = [];
        $lineOf = [];
        $unparsed = [];
        $current = null;
        $name = null;
        $indent = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $i => $line) {
            $n = $i + 1;
            $value = self::strip($line);
            if ($value === '' || $value[0] === '#' || $value[0] === ';') {
                continue;
            }
            $lineIndent = mb_strlen($line) - mb_strlen((string) preg_replace('/\A'.self::SPACE.'+/u', '', $line));
            if ($current !== null && $name !== null && $lineIndent > $indent) {
                if (in_array($current, self::READ_SECTIONS, true) || $current === 'DEFAULT') {
                    return [[], "line {$n} continues a value onto an indented line in [{$current}], which the bridge does not read"];
                }

                continue;
            }
            $indent = $lineIndent;
            if (preg_match('/\A\[(.+)\]/su', $value, $m) === 1) {
                $current = $m[1];
                $name = null;
                if ($current === 'DEFAULT') {
                    continue;
                }
                if (isset($seenSections[$current])) {
                    return [[], "line {$n} opens a section a second time"];
                }
                $seenSections[$current] = true;

                continue;
            }
            if ($current === null) {
                return [[], "line {$n} comes before any [section] header"];
            }
            if (preg_match('/\A(.*?)\s*([=:])\s*(.*)\z/su', $value, $m) !== 1) {
                $unparsed[] = $n;

                continue;
            }
            $option = self::strip($m[1]);
            if ($option === '') {
                $unparsed[] = $n;
            }
            if (isset($seenNames[$current][$option])) {
                return [[], "line {$n} sets a name a second time in its section"];
            }
            $seenNames[$current][$option] = true;
            $name = $option === '' ? null : $option;
            if ($current === 'DEFAULT') {
                return [[], "line {$n} sets a value in [DEFAULT], which the store's parser copies into every section"];
            }
            if (in_array($current, self::READ_SECTIONS, true)) {
                $sections[$current][$option] = self::strip($m[3]);
                $lineOf[$current][$option] = $n;
            }
        }
        if ($unparsed !== []) {
            return [[], 'line '.implode(', ', $unparsed).' is neither a comment, a [section] nor a `name = value` line'];
        }
        foreach ($sections as $section => $values) {
            foreach ($values as $option => $value) {
                $n = $lineOf[$section][$option];
                if (preg_match(self::NAME, (string) $option) !== 1) {
                    return [[], "line {$n} sets a name in [{$section}] that is not printable ASCII without spaces"];
                }
                if ($section !== self::MAP_SECTION || $value === '') {
                    continue;
                }
                if (preg_match(self::NAME, $value) !== 1) {
                    return [[], "line {$n} maps a [git-credential-map] name to a key name that is not printable ASCII without spaces"];
                }
                if (str_contains($value, '%%') || str_contains($value, '%(')) {
                    return [[], "line {$n} maps a [git-credential-map] name to a value holding `%%` or `%(`, which the store's own reader refuses"];
                }
            }
        }

        return [$sections, null];
    }

    private static function strip(string $text): string
    {
        return (string) preg_replace('/\A'.self::SPACE.'+|'.self::SPACE.'+\z/u', '', $text);
    }
}
