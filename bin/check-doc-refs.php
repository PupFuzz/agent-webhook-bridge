#!/usr/bin/env php
<?php

/**
 * Doc-sync guard. THREE INDEPENDENT RULES run here, each with its own docblock below, sharing
 * one rationale: a doc or comment that names something untrue is worse than none, and none of
 * these defects is visible to phpstan, pint or the suite — a false comment is still valid PHP
 * that passes every assertion.
 *
 *   1. dangling references — a CLAUDE_*.md names a PHP file or FQCN that does not exist (DL-013)
 *   2. line-number citations — a comment or doc cites an OFFSET into the migrating file (DL-242)
 *   3. coverage-membership claims — a check docblock names the generated gap list without
 *      saying what naming it does not buy (DL-243)
 *
 * Exit 0 = every rule clean; exit 1 = at least one finding, reported per rule with the file and
 * line it fired on. An exit code alone cannot say WHICH rule fired, so each block names itself.
 *
 * RULE 1 (DL-013): assert that every PHP file path / FQCN named in the
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
// the two files that must be able to quote the citation forms this rule rejects: this
// script, and the test that drives it over them.
$citeExcluded = '#^(CLAUDE_DECISIONS\.md|docs/CHANGELOG\.md|docs/reviews/|docs/check-golden-coverage\.|bin/check-doc-refs\.php$|tests/Feature/Workflows/DocRefCitationLintTest\.php$)#';

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

/**
 * THIRD CHECK — coverage-membership claims (DL-243).
 *
 * `docs/check-golden-coverage.md` is GENERATED and BOUNDED to `CheckCommand::handle()`. A check
 * that migrated into the registry has no predicates left there, so a comment claiming its
 * predicate is one of that file's disclosed gaps is FALSE BY CONSTRUCTION — no regeneration can
 * make it true again. Nothing else catches it: a false comment is still valid PHP that passes
 * phpstan, pint, and every assertion in the suite.
 *
 * THE SHAPE THIS EXISTS FOR is not ordinary staleness. Fifteen of these claims went false in one
 * merge — the stage that finished the migration — without a character changing in any of the
 * files holding them. A defect class whose truth value flips on an unrelated event is one a
 * periodic re-read cannot be relied on to catch, so it needs a gate that runs every time.
 *
 * WHITELIST, NOT BLACKLIST. A block that mentions the coverage file (or "disclosed gap") must
 * ALSO state what the mention does not buy. This is the same trade the citation rule above
 * makes: an unrecognised WRONG sentence would pass silently, where an unrecognised RIGHT one
 * merely fails loudly and is fixed by naming the bound — which is what the author owed the
 * reader anyway. Enumerating wrong phrasings is the losing half.
 *
 * WHAT IT DOES NOT PROVE, stated because an unstated bound is how this defect class began: a
 * marker is a phrase, not a semantics. A false membership claim that also carries a sanctioned
 * sentence still passes. What this gates is the path that actually minted the fifteen — a
 * docblock written from a neighbour's wording — not the truth of any individual claim.
 *
 * SCOPE is the check surface, where a docblock speaks FOR a check. `docs/` is deliberately out:
 * `docs/CHECK-REGISTRY-PLAN.md` narrates the gap list at length, and a whitelist over narrative
 * prose is churn rather than a gate. Because the surface is a root allow-list rather than a
 * repo-wide scan, this rule needs no exclusion twin like `$citeExcluded`: neither this script nor
 * the test that drives it lives under those roots, so both quote rejected claims freely.
 *
 * The unit is a PHP COMMENT, so a non-.php file placed under those roots is not scanned. There
 * are none today and the repo's prose lives in `docs/`; this is a stated bound, not a claim that
 * the surface is airtight.
 */
$claimSurface = '#^(app/Bridge/Check/|tests/Unit/Bridge/Check/|tests/Support/CheckGolden/|tests/Feature/Console/Check/)#';

/** A mention of the generated gap list, in the forms this repo actually writes it. */
$claimTrigger = '/check-golden-coverage|disclosed[- ]gaps?/i';

/**
 * Three of the four sanctioned ways to license a mention. Each states a CONSEQUENCE rather than a
 * fact of absence, because absence is the trap: "absent from that list ENTIRELY" reads as
 * reassurance until the block also says that absence protects nothing.
 */
$claimMarkers = [
    '/\bnot\b[^.]{0,40}\bprotection\b/i',
    '/in either direction/i',
    '/\bnever\b[^.]{0,24}\bdisclosed gaps?\b/i',
];

/**
 * The fourth way, and the root the other three are consequences OF: name the bound itself — the
 * file is generated from `CheckCommand::handle()`, so a check that migrated out is absent from it
 * by construction. "enumerates predicates in", "walks … only" and "no longer lives in" are three
 * spellings of that one statement and the next author will write a fourth, so what is matched is
 * the BOUND being named, not the verb that names it.
 *
 * THE SAME SENTENCE, NOT MERELY THE SAME BLOCK — and a distance window is NOT a substitute for
 * that, which a planted claim proved rather than review catching it. Every migrated check opens
 * "…migrated out of `CheckCommand::handle()` (DL-242 stage N)", so `handle()` sits within a
 * sentence or two of the top of almost every docblock in this surface; a proximity window read
 * that boilerplate as a bound and licensed a membership claim planted directly beneath it. The
 * grammatical link is the whole content of the marker: a bound stated ABOUT the coverage file
 * shares a sentence with the mention of it, and a bound merely PRESENT in the block does not.
 */
function boundNamedInSameSentence(string $text, string $trigger): bool
{
    foreach (sentencesOf($text) as $sentence) {
        if (preg_match($trigger, $sentence) === 1 && preg_match('/handle\(\)/', $sentence) === 1) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function sentencesOf(string $text): array
{
    return preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];
}

/**
 * Every comment block in a PHP source, flattened to a single line.
 *
 * THE UNIT IS THE BLOCK, NOT THE LINE. These claims run three to six wrapped lines, and the two
 * halves — the mention and the bound that licenses it — routinely sit on different ones. A
 * line-scoped rule would reject nearly every correct sentence in the repo while accepting a claim
 * whose mention happened to wrap away from its verb.
 *
 * Tokenized rather than pattern-matched, so a comment delimiter inside a string literal cannot
 * open a phantom block — the fixture strings in this rule's own test are exactly that shape.
 * Consecutive `//` lines are joined into one block: they are one authorial unit, and splitting
 * them would reintroduce the line-scoping this rule exists to avoid.
 *
 * @return list<array{line: int, text: string}>
 */
function commentBlocks(string $source): array
{
    $blocks = [];
    $run = null;
    $runLine = 0;
    $runLast = 0;

    $flush = function () use (&$blocks, &$run, &$runLine): void {
        if ($run !== null) {
            $blocks[] = ['line' => $runLine, 'text' => flattenComment($run)];
            $run = null;
        }
    };

    foreach (token_get_all($source) as $tok) {
        if (! is_array($tok)) {
            continue;
        }
        [$id, $text, $line] = $tok;
        if ($id !== T_COMMENT && $id !== T_DOC_COMMENT) {
            continue;
        }
        if (preg_match('#^\s*(//|\#)#', $text) !== 1) {
            $flush();
            $blocks[] = ['line' => $line, 'text' => flattenComment($text)];

            continue;
        }
        if ($run !== null && $line === $runLast + 1) {
            $run .= "\n".$text;
            $runLast = $line;

            continue;
        }
        $flush();
        $run = $text;
        $runLine = $line;
        $runLast = $line;
    }
    $flush();

    return $blocks;
}

/** Strip comment syntax and collapse the wrapping, so a phrase split across lines still matches. */
function flattenComment(string $raw): string
{
    $raw = (string) preg_replace('#^\s*/\*+#', ' ', $raw);
    $raw = (string) preg_replace('#\*+/\s*$#', ' ', $raw);
    $raw = (string) preg_replace('#^\s*(\*+|//+|\#)#m', ' ', $raw);

    return trim((string) preg_replace('/\s+/', ' ', $raw));
}

/**
 * Report the SENTENCE the mention sits in. A fixed byte window around the match was the obvious
 * form and is wrong twice: this prose is full of em-dashes, so a window splits a multi-byte
 * character and emits invalid UTF-8 into the CI log, and a fragment is harder to locate in the
 * file than the whole sentence it came from.
 */
function claimExcerpt(string $text, string $trigger): string
{
    foreach (sentencesOf($text) as $sentence) {
        if (preg_match($trigger, $sentence) === 1) {
            return trim($sentence);
        }
    }

    return trim($text);
}

$claimErrors = [];
foreach (scannedSources($root) as $rel) {
    if (! str_ends_with($rel, '.php') || preg_match($claimSurface, $rel) !== 1) {
        continue;
    }
    foreach (commentBlocks((string) file_get_contents($root.'/'.$rel)) as $block) {
        if (preg_match($claimTrigger, $block['text']) !== 1) {
            continue;
        }
        if (boundNamedInSameSentence($block['text'], $claimTrigger)) {
            continue;
        }
        foreach ($claimMarkers as $marker) {
            if (preg_match($marker, $block['text']) === 1) {
                continue 2;
            }
        }
        $claimErrors[] = sprintf('%s:%d  %s', $rel, $block['line'], claimExcerpt($block['text'], $claimTrigger));
    }
}

if ($errors !== [] || $citeErrors !== [] || $claimErrors !== []) {
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
    if ($claimErrors !== []) {
        fwrite(STDERR, "Coverage-membership claims (a block naming the generated gap list must say what naming it does NOT buy):\n");
        foreach ($claimErrors as $e) {
            fwrite(STDERR, "  - {$e}\n");
        }
        fwrite(STDERR, "\ndocs/check-golden-coverage.md is generated from CheckCommand::handle() alone, so a\nmigrated check is absent from it BY CONSTRUCTION — and absence there is not protection.\nSay so in the same block: that absence from the file is not protection, that it does not\nspeak for the predicate in either direction, or that the leg was never a disclosed gap.\nSee DL-243.\n");
    }
    exit(1);
}

fwrite(STDOUT, "doc-refs: all PHP references in CLAUDE_*.md resolve; no line-number citations; no unbounded coverage claims.\n");
exit(0);
