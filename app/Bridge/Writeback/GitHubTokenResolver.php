<?php

namespace App\Bridge\Writeback;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\InsecureSecretPermsException;
use App\Bridge\Support\CoordCredentialStore;
use App\Bridge\Support\PathHelper;
use App\Bridge\Support\ProcessIdentity;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\TokenPath;
use Throwable;

/**
 * Resolves the GitHub token for a repo — the ONE resolver every bridge leg that reaches GitHub uses:
 * the receiver's writes (the DL-390 correlation comment, the DL-408 label), promote-on-release, and
 * the CLI commands (`bridge:reconcile` among them). So `bridge:check` and the code it vouches for
 * cannot disagree about which token a repo gets (DL-185, DL-456).
 *
 * Precedence (per repo, most specific first — DL-456, card#11208):
 *   1. the repo's own `write_token_path` in `writeback.json` — for a repo whose store key is
 *      deliberately read-only. A PATH, never a copy. Authoritative: missing/blank fails loud.
 *   2. the coord credential store, read in-process ({@see CoordCredentialStore}):
 *      `[git-credential-map]` routes the repo to a key, `[github] <key>_file` names the file.
 *      A MAPPED repo whose file is missing, blank, unreadable or misdeclared fails loud — it never
 *      falls through to the single file, which may belong to another owner (a fine-grained PAT
 *      covers one). A store that is present and unreadable or unparseable resolves nothing for any
 *      repo, because it may map the repo.
 *   3. the single file: `providers.github.token_path` (authoritative within this leg: missing or
 *      blank fails loud), else the conventional `<secret_dir>/github/token`.
 *   4. `GH_TOKEN` — ONLY for a caller that asks (`$ambient`: the artisan commands), and only when no
 *      source above applies. The receiver never asks: under PHP-FPM it is absent anyway, and a
 *      shell `bridge:replay` must resolve the token the receiver would.
 *
 * Nothing here spawns a process or reads `$HOME`/`$COORD_CREDENTIALS`, so the receiver and a shell
 * get one answer for one install. A problem names its source ({@see TokenSource}) and carries a
 * {@see TokenFileFault}; a resolved token carries the source and the file it was read from. Never
 * throws.
 */
final class GitHubTokenResolver
{
    private bool $writebackLoaded = false;

    private ?WritebackConfig $writeback = null;

    private ?string $writebackFault = null;

    private ?CoordCredentialStore $store = null;

    /** @var array<string, TokenResolution> memoized per (ambient, RAW repo key). */
    private array $memo = [];

    /**
     * A resolver over an already-loaded `writeback.json` (null: this install has none), so a
     * command that loaded it does not load it twice and resolves against the copy it acts on. The
     * plain constructor loads `writeback.json` itself, on first use.
     */
    public static function forWriteback(?WritebackConfig $writeback): self
    {
        $resolver = new self;
        $resolver->writebackLoaded = true;
        $resolver->writeback = $writeback;

        return $resolver;
    }

    /** A resolver for a run whose `writeback.json` did not load: no repo's override is known. */
    public static function forUnreadWriteback(string $why): self
    {
        $resolver = new self;
        $resolver->writebackLoaded = true;
        $resolver->writebackFault = $why;

        return $resolver;
    }

    /**
     * The token for a repo, any spelling: the store is asked with the spelling `writeback.json`
     * uses for it where it maps the repo, because `[git-credential-map]` is case-sensitive.
     * `$ambient` adds the `GH_TOKEN` leg — for an artisan command, never for the receiver.
     */
    public function resolveFor(string $repo, bool $ambient = false): TokenResolution
    {
        return $this->memo[($ambient ? 'ambient:' : 'runtime:').$repo] ??= $this->resolve($repo, $ambient);
    }

    private function resolve(string $repo, bool $ambient): TokenResolution
    {
        $this->loadWriteback();
        if ($this->writebackFault !== null) {
            return TokenResolution::problem("writeback.json did not load ({$this->writebackFault}), so whether {$repo} declares a write_token_path was NOT determined, and no other source stands in for it", TokenFileFault::Undetermined, TokenSource::WriteTokenPath);
        }
        $configured = $this->writeback?->configuredRepoFor($repo) ?? $repo;

        // 1: the repo's own override.
        $override = $this->writeback?->mappingFor($repo)?->writeTokenPath;
        if ($override !== null) {
            return $this->readTokenFile($override, TokenSource::WriteTokenPath, "write_token_path for {$configured} ({$override})", "the write_token_path writeback.json declares for {$configured}") ?? self::unplacedProblem($override, "the write_token_path writeback.json declares for {$configured}", TokenSource::WriteTokenPath);
        }

        // 2: the store.
        $store = $this->store ??= CoordCredentialStore::configured();
        if (! $store->readable()) {
            return TokenResolution::problem(
                $store->faultClause().', so which repos it maps was NOT determined, and the single token file does not stand in for a repo it may map',
                $store->fault === CoordCredentialStore::UNREADABLE ? TokenFileFault::Unreadable : TokenFileFault::Misconfigured,
                TokenSource::Store,
                $store->path,
            );
        }
        $route = $store->routeFor($configured);
        if ($route !== null) {
            return $this->resolveStoreKey($store, $configured, $route['key'], $route['matched']);
        }

        // 3: the single file.
        if (($file = $this->resolveFileLeg()) !== null) {
            return $file;
        }
        $path = $this->tokenPath();
        [$fault, $clause] = self::unplaced($path);

        // 4: GH_TOKEN, for a caller that asked.
        if ($ambient) {
            $env = $this->envToken();
            if ($env !== null) {
                return TokenResolution::resolved($env, 'GH_TOKEN', TokenSource::Ambient);
            }

            return TokenResolution::problem("no github token: {$clause}, no [git-credential-map] entry for {$configured}, and GH_TOKEN is unset", $fault, TokenSource::TokenFile, $path);
        }

        return TokenResolution::problem("no github token file: {$clause}", $fault, TokenSource::TokenFile, $path);
    }

    /**
     * The single file ONLY — what `bridge:check` asks about when it knows of no repo to resolve for
     * (its `writeback.json` did not load). The override stays authoritative. Never throws.
     */
    public function resolveFromFile(): TokenResolution
    {
        if (($file = $this->resolveFileLeg()) !== null) {
            return $file;
        }
        $path = $this->tokenPath();
        [$fault, $clause] = self::unplaced($path);

        return TokenResolution::problem("no github token file: {$clause}", $fault, TokenSource::TokenFile, $path);
    }

    /**
     * A repo the store maps: its key's file, or a problem naming the key. Never the single file.
     *
     * ⛔ THE FILE MUST BELONG TO THE STORE'S OWNER. The store lives in the coordination project's
     * account, and `bridge:check` may run as root: without this rule the store's owner would choose
     * a file root opens and sends to GitHub as a bearer token.
     */
    private function resolveStoreKey(CoordCredentialStore $store, string $repo, string $key, string $matched): TokenResolution
    {
        $label = 'store key '.CoordCredentialStore::displayName($key)." ([git-credential-map] {$matched})";
        [$path, $why] = $store->tokenFileFor($key);
        if ($path === null) {
            return TokenResolution::problem("{$label} for {$repo}: {$why}. Fix the coord credential store at {$store->path}; the single token file does not stand in for a repo the store maps", TokenFileFault::Misconfigured, TokenSource::Store, $store->path);
        }
        clearstatcache(true, $path);
        if (file_exists($path)) {
            $identity = app(ProcessIdentity::class);
            $fileOwner = $identity->ownerOf($path);
            $storeOwner = $store->owner();
            if ($fileOwner === null || $storeOwner === null) {
                return TokenResolution::problem("{$label} for {$repo} names {$path}, and this process could not read the owner of ".($fileOwner === null ? 'that file' : "the store at {$store->path}").', so whether it belongs to the store\'s owner was NOT determined', TokenFileFault::Undetermined, TokenSource::Store, $path);
            }
            if ($fileOwner !== $storeOwner) {
                $name = fn (int $uid): string => $identity->accountName($uid) ?? "uid {$uid}";

                return TokenResolution::problem("{$label} for {$repo} names {$path}, which is owned by {$name($fileOwner)} and not by the store's owner {$name($storeOwner)} — the bridge reads a file the store names only when the store's owner owns it", TokenFileFault::Misconfigured, TokenSource::Store, $path);
            }
        }

        return $this->readTokenFile($path, TokenSource::Store, "{$label} ({$path})", "the file {$label} names for {$repo}")
            ?? self::unplacedProblem($path, "the file {$label} names for {$repo}", TokenSource::Store);
    }

    /**
     * Leg 3: a resolution, a fail-loud problem, or null when it does not apply (no override set, and
     * nothing at the conventional path). Every problem carries its {@see TokenFileFault}.
     */
    private function resolveFileLeg(): ?TokenResolution
    {
        $override = $this->hasTokenPathOverride();
        $path = $this->tokenPath();
        $resolution = $this->readTokenFile($path, TokenSource::TokenFile, $override ? "token_path override ({$path})" : "token file ({$path})", 'the configured token_path');
        if ($resolution === null && $override) {
            // Authoritative but missing/blank → fail loud; nothing below stands in.
            return self::unplacedProblem($path, 'the configured token_path', TokenSource::TokenFile);
        }

        return $resolution;
    }

    /**
     * One read of a token file, for every source: the resolved token, a problem, or null when the
     * file is absent or blank (each caller decides whether that is fatal). An unfilled
     * `REPLACE_ME` is never handed out as a token.
     */
    private function readTokenFile(string $path, TokenSource $kind, string $source, string $what): ?TokenResolution
    {
        $named = $kind === TokenSource::TokenFile ? "github token file {$path}" : "{$what}: github token file {$path}";
        try {
            $token = SecretFile::read($path);   // throws on insecure perms; null when absent or blank
        } catch (InsecureSecretPermsException $e) {
            return TokenResolution::problem("{$named}: ".RedactedErrorText::of($e), TokenFileFault::InsecurePermissions, $kind, $path);
        } catch (Throwable $e) {
            return TokenResolution::problem("{$named}: ".RedactedErrorText::of($e), TokenFileFault::Unreadable, $kind, $path);
        }
        if ($token === null) {
            return null;
        }
        if ($token === CoordCredentialStore::PLACEHOLDER) {
            return TokenResolution::problem("no github token at {$what}: {$path} holds the unfilled ".CoordCredentialStore::PLACEHOLDER.' placeholder', TokenFileFault::Empty, $kind, $path);
        }

        return TokenResolution::resolved($token, $source, $kind, $path);
    }

    private static function unplacedProblem(string $path, string $what, TokenSource $kind): TokenResolution
    {
        [$fault, $clause] = self::unplaced($path);

        return TokenResolution::problem("no github token at {$what}: {$clause}", $fault, $kind, $path);
    }

    /**
     * Which of the three no-bytes states a token path is in once {@see SecretFile::read()} has
     * answered null, and how to say it. `SecretFile` folds them into one answer because no reader
     * can USE any of them; an operator fixes each differently, and a 0-byte file reported as
     * "absent" sends them looking for a file that is there (card#11201).
     *
     * @return array{0: TokenFileFault, 1: string}
     */
    private static function unplaced(string $path): array
    {
        clearstatcache(true, $path);
        if (is_file($path)) {
            return [TokenFileFault::Empty, "{$path} is empty"];
        }
        if (file_exists($path)) {
            return [TokenFileFault::NotAFile, "{$path} is not a regular file"];
        }

        return [TokenFileFault::Absent, "{$path} absent"];
    }

    private function loadWriteback(): void
    {
        if ($this->writebackLoaded) {
            return;
        }
        $this->writebackLoaded = true;
        try {
            $this->writeback = WritebackConfig::loadDefault();
        } catch (ConfigException $e) {
            $this->writebackFault = RedactedErrorText::of($e);
        }
    }

    /**
     * The single file leg 3 reads: an explicit `bridge.providers.github.token_path` override (e.g.
     * a centralized credential reused without a per-install symlink), else the conventional
     * <secret_dir>/github/token.
     */
    public function tokenPath(): string
    {
        $override = config('bridge.providers.github.token_path');
        if (is_string($override) && trim($override) !== '') {
            return PathHelper::expandUser(trim($override));
        }

        return TokenPath::for((string) config('bridge.secret_dir'), 'github');
    }

    /** True when an explicit token_path override is configured (authoritative within the single-file leg). */
    public function hasTokenPathOverride(): bool
    {
        $override = config('bridge.providers.github.token_path');

        return is_string($override) && trim($override) !== '';
    }

    /** Ambient GH_TOKEN, trimmed; null when unset or blank. */
    private function envToken(): ?string
    {
        $env = getenv('GH_TOKEN');
        $env = is_string($env) ? trim($env) : '';

        return $env === '' ? null : $env;
    }
}
