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

/**
 * SECOND CHECK — line-number citations (DL-242 stage 2).
 *
 * A reference like "CheckCommand L240" or a bare "(L554 warns …)" asserts a fact about
 * an OFFSET, and an offset is invalidated by any edit above it. `CheckCommand::handle()`
 * is being migrated out one cluster per stage, so every stage moves lines and silently
 * restales every citation into it — stage 2 moved ~66 lines and invalidated ~23 of them
 * at once. NO OTHER GATE CATCHES THIS: not phpstan, not pint, and not the golden suite,
 * because a stale comment is still valid PHP that still passes every assertion.
 *
 * TWO RULES, because the citations that rot worst are the ones that never name their file.
 * `GoldenInstall`'s "(L554 warns about a default agent…)" was missed by a manual pass
 * precisely because grepping for `CheckCommand` could not find it — nothing on that line
 * says which file L554 belongs to:
 *
 *   1. A citation naming the migrating file — `CheckCommand L240`, `CheckCommand.php:961`
 *      — is an error ANYWHERE.
 *   2. A BARE `L<n>` is an error anywhere in the check-registry surface, where an
 *      unqualified offset means `CheckCommand` by context.
 *
 * Deliberately NOT a repo-wide ban on offsets: the receiver core is documented as static
 * (DL-001), and `CLAUDE_GOTCHAS.md`'s cites into it have held for months. Banning those
 * too would trade a real defect for churn in docs that are not rotting.
 *
 * Name the construct instead — a method, a loop, or a message string. Construct names are
 * greppable and move with the code.
 */
$stableAnchors = [
    // Not under migration; its offsets have been re-verified and are allowed to be cited.
    'GitHubTokenResolver',
];

/** The file under active migration: any citation of ITS offsets is an error anywhere. */
$volatileFile = 'CheckCommand';

/** Where a bare, unqualified `L<n>` can only mean the migrating file. */
$bareCiteSurface = '#^(app/Bridge/Check/|tests/Support/CheckGolden/|tests/Feature/Console/Check/|docs/CHECK-REGISTRY-PLAN\.md)#';

// Append-only history (records what was true at that version — rewriting it would be a
// lie about the past), generated files (whose predicate ids are literally `if-L116`), and
// this script, which must be able to quote the citation forms it rejects.
$citeExcluded = '#^(CLAUDE_DECISIONS\.md|docs/CHANGELOG\.md|docs/reviews/|docs/check-golden-coverage\.|bin/check-doc-refs\.php$)#';

/** @return list<string> repo-relative *.php and *.md paths under the scanned roots */
function scannedSources(string $root): array
{
    $out = [];
    foreach (['app', 'tests', 'docs', 'bin'] as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'md'], true)) {
                $out[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    foreach (glob($root.'/*.md') ?: [] as $md) {
        $out[] = substr($md, strlen($root) + 1);
    }

    return $out;
}

$citeErrors = [];
foreach (scannedSources($root) as $rel) {
    if (preg_match($citeExcluded, $rel) === 1) {
        continue;
    }
    $inBareSurface = preg_match($bareCiteSurface, $rel) === 1;
    foreach (file($root.'/'.$rel, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        // The glue between the name and the offset is a bounded any-char window, not an
        // enumerated set: the repo's own house style backtick-quotes class names, so the
        // literal `CheckCommand` L240 — the single most likely way to write this citation —
        // slipped a `[\s:]*` connector entirely, as did `CheckCommand::handle()` L240.
        // Enumerating quoting forms is the losing half of that trade; bounding the distance
        // is the winning one.
        $namesVolatile = preg_match('/'.$volatileFile.'[^\n]{0,24}?\b(L|:)\d{2,4}\b/', $line) === 1;
        $bareOffset = preg_match('/\bL\d{2,4}\b/', $line) === 1;
        if (! $namesVolatile && ! ($inBareSurface && $bareOffset)) {
            continue;
        }
        // The exemption covers BARE offsets only. A line that names the migrating file
        // is citing it no matter what else it mentions — without this bound, one anchor
        // word anywhere on the line whitelists the exact citation the rule exists to catch.
        if (! $namesVolatile) {
            foreach ($stableAnchors as $anchor) {
                if (str_contains($line, $anchor)) {
                    continue 2;
                }
            }
        }
        $citeErrors[] = sprintf('%s:%d  %s', $rel, $i + 1, trim($line));
    }
}

if ($errors !== [] || $citeErrors !== []) {
    if ($errors !== []) {
        fwrite(STDERR, "Dangling doc references (a CLAUDE_*.md names a PHP file that does not exist):\n");
        foreach ($errors as $e) {
            fwrite(STDERR, "  - {$e}\n");
        }
        fwrite(STDERR, "\nFix the reference, or — if the class was deliberately removed — note it on the\nsame line with a marker like \"(removed in vX)\". See DL-013.\n");
    }
    if ($citeErrors !== []) {
        fwrite(STDERR, "Line-number citations (an offset goes stale the next time anything above it moves):\n");
        foreach ($citeErrors as $e) {
            fwrite(STDERR, "  - {$e}\n");
        }
        fwrite(STDERR, "\nName the construct instead — the method, the loop, or the message text. If the\ntarget file really is stable, add it to \$stableAnchors in this script with a reason.\nSee docs/CHECK-REGISTRY-PLAN.md § Stage 2 result.\n");
    }
    exit(1);
}

fwrite(STDOUT, "doc-refs: all PHP references in CLAUDE_*.md resolve; no line-number citations.\n");
exit(0);
