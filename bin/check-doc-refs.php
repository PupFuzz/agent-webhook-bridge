#!/usr/bin/env php
<?php

/**
 * Doc-sync guard (DL-013): assert that every PHP file path / FQCN named in the
 * non-historical CLAUDE_*.md docs resolves to an extant file. The doc system is
 * the onboarding map (for the next maintainer AND the next AI session), so a
 * doc that names a since-deleted class (e.g. ProviderApiConfig after DL-007) is
 * worse than no doc — it sends you to a file that isn't there. This converts
 * "remember to update the docs" into "CI fails if you didn't."
 *
 * Checked, per backtick-quoted token on a line:
 *   - a repo file path ending in .php that contains a '/', with optional brace
 *     expansion: `app/Bridge/Support/{AgentConfig,SubscriptionConfig}.php`
 *   - an `App\...` FQCN: `App\Bridge\Support\SecretFile` → app/Bridge/Support/SecretFile.php
 * A line carrying an explicit removed-marker — "(removed in …)", "deleted",
 * "no longer exists", "there is no" — is skipped (history may name dead classes
 * deliberately). CLAUDE_DECISIONS.md is excluded outright (append-only history).
 *
 * Exit 0 = all references resolve; exit 1 = at least one dangling reference.
 */
$root = dirname(__DIR__);

$docs = [
    'CLAUDE.md',
    'CLAUDE_ARCHITECTURE.md',
    'CLAUDE_CONVENTIONS.md',
    'CLAUDE_TESTING.md',
    'CLAUDE_DEPLOYMENT.md',
    'CLAUDE_GOTCHAS.md',
];

/**
 * Expand a backtick token into the repo-relative .php paths it asserts exist.
 * Returns [] for anything that isn't a concrete file reference (prose, a
 * namespace, a `<Placeholder>` template).
 */
function refsFromToken(string $tok): array
{
    $tok = trim($tok);

    // `<Provider>Adapter.php` and friends are templates, not references — skip.
    if (str_contains($tok, '<') || str_contains($tok, '>')) {
        return [];
    }

    // App\... FQCN (strip a trailing ::class / ::method() suffix) → app/.../Class.
    // A namespace (directory) is a valid target too — resolved in the caller.
    if (str_starts_with($tok, 'App\\')) {
        $fqcn = preg_replace('/::.*$/', '', $tok);
        if (preg_match('/^App\\\\[A-Za-z0-9_\\\\]+$/', (string) $fqcn) === 1) {
            return ['fqcn:app/'.str_replace('\\', '/', substr((string) $fqcn, strlen('App\\')))];
        }

        return [];
    }

    if (! str_ends_with($tok, '.php')) {
        return [];
    }

    // Brace expansion: dir/{A,B,C}.php — also covers the bare `{A,B}Command.php`.
    if (preg_match('/^(.*)\{([^}]+)\}(.*)$/', $tok, $m) === 1) {
        $out = [];
        foreach (explode(',', $m[2]) as $part) {
            $out[] = $m[1].trim($part).$m[3];
        }

        return $out;
    }

    return [$tok];
}

/**
 * Does a single ref resolve?
 *  - fqcn:…  → a class file OR a namespace directory
 *  - a path with '/'  → that file (a `...` segment globs migration timestamps)
 *  - a bare Foo.php (no '/')  → any file with that basename anywhere in the repo
 *    (basenames are unique here; this catches the CLAUDE.md "Critical paths" form)
 */
function refResolves(string $root, string $ref): bool
{
    if (str_starts_with($ref, 'fqcn:')) {
        $base = $root.'/'.substr($ref, strlen('fqcn:'));

        return is_file($base.'.php') || is_dir($base);
    }
    if (! str_contains($ref, '/')) {
        // Only a real filename stem is a reference — a bare ".php" (the file
        // extension named in prose) or a dotted token is not.
        if (preg_match('/^[A-Za-z0-9_]+\.php$/', $ref) !== 1) {
            return true;
        }

        return in_array($ref, repoPhpBasenames($root), true);
    }
    if (str_contains($ref, '...')) {
        return glob($root.'/'.str_replace('...', '*', $ref)) !== [];
    }

    return is_file($root.'/'.$ref);
}

/**
 * Every *.php basename in the repo (app/, tests/, database/, config/, routes/,
 * bin/), computed once. Used to resolve bare `Foo.php` references.
 *
 * @return list<string>
 */
function repoPhpBasenames(string $root): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (['app', 'tests', 'database', 'config', 'routes', 'bin'] as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $cache[] = $file->getFilename();
            }
        }
    }

    return $cache;
}

// Skip a line that deliberately names dead code: an explicit removed-marker, or
// a struck-through (~~…~~) historical heading.
$skip = '/\(removed\b|\bdeleted\b|no longer exists|there is no\b|replaced by\b|~~/i';
$errors = [];

foreach ($docs as $doc) {
    $path = $root.'/'.$doc;
    if (! is_file($path)) {
        continue;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        if (preg_match($skip, $line) === 1) {
            continue;
        }
        if (preg_match_all('/`([^`]+)`/', $line, $m) === false) {
            continue;
        }
        foreach ($m[1] as $tok) {
            foreach (refsFromToken($tok) as $ref) {
                if (! refResolves($root, $ref)) {
                    $errors[] = sprintf('%s:%d  names `%s` (missing)', $doc, $i + 1, $tok);
                }
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Dangling doc references (a CLAUDE_*.md names a PHP file that does not exist):\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    fwrite(STDERR, "\nFix the reference, or — if the class was deliberately removed — note it on the\nsame line with a marker like \"(removed in vX)\". See DL-013.\n");
    exit(1);
}

fwrite(STDOUT, "doc-refs: all PHP references in CLAUDE_*.md resolve.\n");
exit(0);
