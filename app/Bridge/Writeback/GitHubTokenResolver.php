<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\PathHelper;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\TokenPath;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Resolves the GitHub read token bridge:reconcile uses to read PR state, per repo.
 * The SINGLE home of the token precedence (DL-184 core + DL-185 store-native) — both
 * bridge:reconcile and bridge:check consume this so their view of "is a token
 * available?" can never diverge from what reconcile actually does.
 *
 * Precedence (per repo, most-authoritative first):
 *   1. bridge.providers.github.token_path override — AUTHORITATIVE: read only that
 *      file; blank/missing → fail loud; NO store/env fallback (a wrong path must
 *      fail loud, not silently resolve a different credential). (DL-184)
 *   2. the conventional <secret_dir>/github/token file, when present. (DL-183/184)
 *   3. store-native: `git-credential-coord get`, keyed on the store's
 *      [git-credential-map] (host/owner/repo, most-specific-first) → a per-repo
 *      least-privilege PAT. The default when no explicit token file is placed.
 *      (DL-185)
 *   4. ambient GH_TOKEN (present in an operator shell, absent in the FPM receiver,
 *      so the fallback self-scopes to the reconcile CLI). (DL-184)
 *
 * Guardrails (framework contract — coord docs/CREDENTIALS.md § Non-git consumers):
 *   - GH_TOKEN (4) is a consumer-side fallback consulted ONLY after the store (3)
 *     returns nothing; it can never shadow a store-mapped token.
 *   - The store's empty output is a REAL answer: an unmapped repo falls through to
 *     GH_TOKEN, but a REPLACE_ME placeholder, an unreadable `*_file` (the helper
 *     writes stderr, exits 0, emits no password=), or a helper crash FAIL LOUD —
 *     never a silent fall-through to a wrong-scoped token.
 */
final class GitHubTokenResolver
{
    /** The placeholder the credentials store template seeds for an unfilled slot. */
    private const STORE_PLACEHOLDER = 'REPLACE_ME';

    private const DEFAULT_HELPER = 'git-credential-coord';

    /** @var array<string, TokenResolution> memoized per RAW repo key. */
    private array $memo = [];

    /**
     * The token for a repo (the raw writeback.json mapping key — NOT canonicalized:
     * [git-credential-map] is case-sensitive). Never throws.
     */
    public function resolveFor(string $repo): TokenResolution
    {
        return $this->memo[$repo] ??= $this->resolve($repo);
    }

    private function resolve(string $repo): TokenResolution
    {
        // 1 + 2: explicit token file — the override path when configured, else the
        // conventional <secret_dir>/github/token. Either short-circuits the store.
        $override = $this->hasTokenPathOverride();
        $path = $this->tokenPath();
        try {
            $fileToken = SecretFile::read($path);   // throws on insecure perms; null when absent
        } catch (Throwable $e) {
            return TokenResolution::problem("github token file {$path}: {$e->getMessage()}");
        }
        if ($fileToken !== null && $fileToken !== '') {
            return TokenResolution::resolved($fileToken, $override ? "token_path override ({$path})" : "token file ({$path})");
        }
        if ($override) {
            // Authoritative but missing/blank → fail loud; NO store/env fallback.
            return TokenResolution::problem("no github token at the configured token_path {$path}");
        }

        // 3: store-native (per-repo).
        $store = $this->resolveFromStore($repo);
        if ($store !== null) {
            return $store;   // a resolved token OR a fail-loud problem
        }

        // 4: ambient GH_TOKEN.
        $env = $this->envToken();
        if ($env !== null) {
            return TokenResolution::resolved($env, 'GH_TOKEN');
        }

        return TokenResolution::problem("no github token: {$path} absent, no [git-credential-map] entry for {$repo}, and GH_TOKEN is unset");
    }

    /**
     * The store-native leg. Returns a resolved TokenResolution (a mapped token), a
     * PROBLEM TokenResolution (REPLACE_ME / helper crash / unreadable `*_file`), or
     * null when the leg is NOT APPLICABLE (helper absent, or the repo is unmapped) —
     * in which case the caller falls through to GH_TOKEN.
     */
    private function resolveFromStore(string $repo): ?TokenResolution
    {
        $bin = $this->locateHelper();
        if ($bin === null) {
            return null;   // no store helper on this host → GH_TOKEN-only install
        }

        // git-credential wire format on stdin; shell-free argv exec (no metacharacter
        // surface). Process inherits the CLI env, so the helper sees HOME /
        // COORD_CREDENTIALS to locate the store. A start failure (proc_open unable to
        // fork under resource pressure — distinct from the exit-127 missing-binary
        // case already excluded above) fails loud rather than escaping: the resolver
        // is total (bridge:check depends on it never throwing).
        $request = "protocol=https\nhost=github.com\npath={$repo}\n\n";
        try {
            $result = Process::input($request)->run([$bin, 'get']);
        } catch (Throwable $e) {
            return TokenResolution::problem("git-credential-coord could not be run for {$repo}: {$e->getMessage()}");
        }

        if (! $result->successful()) {
            $err = trim($result->errorOutput());

            return TokenResolution::problem("git-credential-coord get failed for {$repo} (exit {$result->exitCode()})".($err !== '' ? ": {$err}" : ''));
        }

        $password = $this->parsePassword($result->output());
        if ($password !== null && $password !== '') {
            if ($password === self::STORE_PLACEHOLDER) {
                return TokenResolution::problem("[git-credential-map] resolves {$repo} to a {$password} placeholder — fill in the store slot, do not run reconcile with an unset token");
            }

            return TokenResolution::resolved($password, "store (git-credential-coord: {$repo})");
        }

        // No password line. Non-empty stderr ⇒ a helper-side error (an unreadable
        // `*_file`) that must FAIL LOUD per the framework fail-loud-on-`*_file`
        // contract; empty stderr ⇒ genuinely unmapped → fall through to GH_TOKEN.
        $err = trim($result->errorOutput());
        if ($err !== '') {
            return TokenResolution::problem("git-credential-coord could not resolve {$repo}: {$err}");
        }

        return null;   // unmapped (or a blank inline slot) → GH_TOKEN
    }

    /**
     * The configured credential helper, resolved to an executable path, or null when
     * it is absent/unexecutable (leg not applicable) or explicitly disabled (empty
     * config). A bare name is PATH-resolved; a value containing '/' is an explicit
     * path (~ expanded).
     */
    private function locateHelper(): ?string
    {
        $configured = config('bridge.providers.github.credential_helper');
        $helper = is_string($configured) ? trim($configured) : self::DEFAULT_HELPER;
        if ($helper === '') {
            return null;   // explicitly disabled
        }

        if (str_contains($helper, '/')) {
            $path = PathHelper::expandUser($helper);

            return is_executable($path) ? $path : null;
        }

        return (new ExecutableFinder)->find($helper) ?: null;
    }

    private function parsePassword(string $stdout): ?string
    {
        foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
            if (str_starts_with($line, 'password=')) {
                return substr($line, strlen('password='));
            }
        }

        return null;
    }

    /**
     * The token file path legs 1/2 read: an explicit
     * `bridge.providers.github.token_path` override (e.g. a centralized credential
     * reused without a per-install symlink), else the conventional
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

    /** True when an explicit token_path override is configured (authoritative — no store/env fallback). */
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
