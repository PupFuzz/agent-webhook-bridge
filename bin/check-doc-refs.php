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
 * RULE 1 (DL-013): assert that every PHP file path, FQCN and class MEMBER named in this repo's
 * prose resolves to something that exists. The doc system is
 * the onboarding map (for the next maintainer AND the next AI session), so a
 * doc that names a since-deleted class (e.g. ProviderApiConfig after DL-007) is
 * worse than no doc — it sends you to a file that isn't there. This converts
 * "remember to update the docs" into "CI fails if you didn't."
 *
 * Checked, per backtick-quoted token on a line — the first two over the non-historical
 * CLAUDE_*.md docs, the third over the whole source surface (see THE MEMBER LEG below):
 *   - a repo file path ending in .php that contains a '/', with optional brace
 *     expansion: `app/Bridge/Support/{AgentConfig,SubscriptionConfig}.php`
 *   - an `App\...` FQCN: `App\Bridge\Support\SecretFile` → app/Bridge/Support/SecretFile.php
 *   - a MEMBER citation — `App\Bridge\Support\Finding::warn()`, `CheckSlot::BoardToolsSshAdvisory`,
 *     `DlTokenGrammar::sole()` — resolved to the declaring file, then to the member itself
 * A citation whose OWN SENTENCE carries an explicit removed-marker — "removed", "renamed",
 * "deleted", "no longer exists", "there is no" — is skipped (history may name dead classes
 * deliberately); the vocabulary is satisfiable by an annotation appended beside a frozen
 * sentence, never only by an edit to it, and that annotation must NAME the citation it
 * discharges. {@see $removedMarker} for the bound on those words and
 * {@see annotationCovers()} for the scope, which both of rule 1's escape hatches share.
 *
 * THE MEMBER LEG (card#6025). The FQCN leg STRIPPED a `::member` suffix before asking whether
 * anything existed, so the class resolved and the half of the citation that names BEHAVIOUR was
 * never read — `DlTokenGrammar::sole()` and a phantom spelled exactly like it were equally
 * invisible. A phantom member is the same defect as a phantom class one level down — it sends a
 * maintainer to a method that is not there — and it is minted by CURRENT drafting, not only by
 * deletion.
 *
 * WHAT THIS LEG DOES NOT CLOSE, stated because card#6025's third instance is exactly it. That
 * instance was a bare `correlationRefs()` in a merged decision-log entry: it carries NO class
 * and no `::`, so it never was a token this script reads and it still is not — the `::member`
 * strip was not the mechanism that hid it, and this leg would not have caught it. It is closed
 * by the correction to that sentence, not by a gate, and the same shape can recur tomorrow.
 * Bare `func()` in prose stays deliberately out of the token set: the overwhelming majority of
 * them here are external vocabulary or historical narration rather than claims about this tree,
 * and admitting them would red on every one. No count of them is quoted here — a figure in a
 * comment is a quoted authority no later pass recomputes; the population re-derives with
 *   grep -rohE '`[a-z][A-Za-z0-9_]*\(\)`' CLAUDE_*.md README.md docs app tests bin | sort -u | wc -l
 *
 * IT ANSWERS ONLY WHERE IT CAN. A member is a finding when the class file is under `app/`
 * AND its whole ancestry (parents, traits) is resolvable there and declares nothing by that
 * name. A class whose ancestry leaves `app/` — a framework base, a vendor trait — is
 * UNVERIFIABLE by a filesystem scan and passes: the alternative is a gate that reds on
 * inherited members it cannot see, which is the cry-wolf trade this script's other rules
 * refuse. Same for the name itself: a bare `Command` is resolved only when ONE construct in
 * this tree carries it, counting both files under `app/` and the non-`App\` classes the tree
 * imports under that name — an `app/…/Command.php` beside `Illuminate\Console\Command` makes
 * the citation unattributable, and guessing between them would invent the finding.
 *
 * IT READS THE WHOLE SOURCE SURFACE — {@see scannedSources()}, the `app/` + `tests/` + `docs/` +
 * `bin/` + root `*.md` set rules 2 and 3 already walk — and NOT the six-doc current-state list
 * the path/FQCN leg reads. A member citation makes the same claim wherever it is written, and
 * those six docs are a minority of the places this repo writes them: of the eight false prose
 * claims card#6025 fixed, six sat outside that list. THIS FILE IS IN THAT SURFACE, and the test
 * harness copies it into every fixture tree it builds — so a `Class::member` quoted in here must
 * name a class no fixture declares, or the script reds on its own comments.
 *
 * CLAUDE_DECISIONS.md IS SCANNED FOR MEMBERS, AND FOR NOTHING ELSE. It is append-only
 * history, and its FILE-PATH prose is time-stamped rather than current: the path leg over it
 * measures 25 findings and 0 defects — anticipated-file lists from a design DL, specimen
 * paths in an argument about non-ASCII (`app/café.php`), shell command lines, glob patterns.
 * Greening those would mean editing frozen entries to suit a live rule. A member citation is
 * different in kind: it names a construct that either exists in this tree or does not, and
 * the escape hatch does not require rewriting the sentence — the removed-marker vocabulary
 * matches an ANNOTATION appended beside the original, which is what the freeze rule already
 * prescribes (see the DL-273 `correlationRefs()` annotation). What the annotation owes in
 * return is the citation itself: the marker is scoped to the sentence it is written in, so an
 * appended clause discharges the citations it REPEATS and no others (card#7127). The log's
 * OTHER structural exemption is {@see citedAsRejected()}: an alternative the entry says it
 * rejected is absent on purpose, and it has always been read at that same scope.
 *
 * THE COVERAGE IS MEASURED, NOT ASSUMED, AND THE MEASUREMENT SHIPS. `--census` prints every
 * bucket — resolved, reported, class not under `app/`, ambiguous class name, unverifiable
 * ancestry, removed-marker sentence, rejected alternative — re-derived over the tree it is run on,
 * and every run prints the unexamined tally on its own line. Deliberately no figures in this
 * comment: a figure here is a quoted authority, and the census that stood here could not be
 * recomputed by anything shipped — the defect this rule exists to catch, one level up.
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

/**
 * Every bucket this run's member leg put a citation in, counted by the scan itself.
 *
 * A CENSUS THAT IS NOT DERIVED BY THE SHIPPED CODE IS A QUOTED AUTHORITY: the figures that
 * used to sit in this file's docblock were reproducible only by an instrumented variant of
 * it, so no later pass could move them. Passing a bucket records one citation; passing
 * nothing reads the counts back. `--census` prints them; every run prints the unexamined
 * ones, because a clean line that speaks for citations the leg never answered for is the
 * same defect one level up (DL-251).
 *
 * @return array<string, int>
 */
function tally(?string $bucket = null): array
{
    static $counts = [
        'resolved' => 0,
        'reported' => 0,
        'class_not_under_app' => 0,
        'ambiguous_class_name' => 0,
        'unverifiable_ancestry' => 0,
        'removed_marker_sentence' => 0,
        'rejected_alternative' => 0,
        'depth_bail' => 0,
    ];
    if ($bucket !== null) {
        $counts[$bucket]++;
    }

    return $counts;
}

/**
 * The class file a `Class::member` citation names, and — when it names none — WHICH population
 * it fell into, so the run can disclose what it declined to answer instead of dropping it.
 *
 * An `App\…` prefix is resolved by namespace. A namespace-less name is resolved by basename,
 * and ONLY when exactly one construct in this tree carries it: a second file under `app/`, or a
 * non-`App\` class the tree IMPORTS under that name, makes the citation unattributable.
 * Guessing between candidates would invent the finding — `Command::argument()` beside an
 * unrelated `app/Command.php` is the shape that convicts where this scan can see nothing.
 *
 * Restricted to `app/` on purpose. The prose here cites test classes, external product
 * vocabulary and cross-repo constructs in the same `Foo::bar()` shape, and a scan that
 * reached them would answer about whichever file happened to share a basename.
 *
 * @return array{file: ?string, reason: string}
 */
function classFileForCitation(string $root, string $class): array
{
    if (str_starts_with($class, 'App\\')) {
        $rel = 'app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';

        return is_file($root.'/'.$rel)
            ? ['file' => $rel, 'reason' => 'resolved']
            : ['file' => null, 'reason' => 'class_not_under_app'];
    }
    if (str_contains($class, '\\')) {
        return ['file' => null, 'reason' => 'class_not_under_app'];
    }
    $matches = [];
    foreach (appClassFiles($root) as $rel) {
        if (basename($rel, '.php') === $class) {
            $matches[] = $rel;
        }
    }
    if ($matches === []) {
        return ['file' => null, 'reason' => 'class_not_under_app'];
    }
    if (count($matches) > 1 || in_array($class, importedNonAppNames($root), true)) {
        return ['file' => null, 'reason' => 'ambiguous_class_name'];
    }

    return ['file' => $matches[0], 'reason' => 'resolved'];
}

/**
 * The class file an ANCESTOR resolves to — FQCN only, never a basename. An ancestry that
 * leaves `App\` is unverifiable by a filesystem scan, and the whole point of qualifying the
 * ancestor first is that `extends Command` under `use Illuminate\Console\Command;` must not be
 * answered by whatever `app/` file happens to share the basename.
 */
function classFileForAncestor(string $root, string $fqcn): ?string
{
    if (! str_starts_with($fqcn, 'App\\')) {
        return null;
    }
    $rel = 'app/'.str_replace('\\', '/', substr($fqcn, strlen('App\\'))).'.php';

    return is_file($root.'/'.$rel) ? $rel : null;
}

/**
 * Every name under which this tree imports a class from OUTSIDE `App\` — the alias when the
 * import carries one, the basename otherwise. Column-0 `use` only: an indented one is a trait
 * use inside a class body, which names no import.
 *
 * @return list<string>
 */
function importedNonAppNames(string $root): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $names = [];
    foreach (appClassFiles($root) as $rel) {
        foreach (importsOf((string) @file_get_contents($root.'/'.$rel)) as $alias => $fqcn) {
            if (! str_starts_with($fqcn, 'App\\')) {
                $names[$alias] = true;
            }
        }
    }
    $cache = array_keys($names);

    return $cache;
}

/**
 * Every repo-relative *.php path under app/, computed once.
 *
 * @return list<string>
 */
function appClassFiles(string $root): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (is_dir($root.'/app')) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $cache[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }

    return $cache;
}

/**
 * Is `$member` declared by `$class` (as declared in `$rel`) or by an ancestor of it?
 *
 * THREE-VALUED, and that is the whole design. `true` = found; `false` = the class and its
 * whole ancestry are readable under `app/` and none of them declares it; `null` = the
 * ancestry LEAVES `app/` (a framework base class, a vendor trait), so the question was not
 * answered. Only `false` is reported. A two-valued version of this function reds every
 * citation of an inherited member — the failure mode that makes a gate get switched off.
 *
 * SCOPED TO THE CITED CLASS'S BODY, not the file: a second class in one file would otherwise
 * vouch for the first, since a whole-file scan cannot tell whose body a declaration sits in.
 *
 * The member forms are the ones PHP actually declares: a method, a class constant, an enum
 * case, and a property (promoted or not). They are matched as declarations, never as uses,
 * so a call to `sole()` inside a class does not vouch for `sole()` existing on it.
 */
function memberDeclared(string $root, string $rel, string $class, string $member, int $depth = 0): ?bool
{
    if ($depth > 4) {
        tally('depth_bail');

        return null;
    }
    $src = @file_get_contents($root.'/'.$rel);
    if ($src === false) {
        return null;
    }
    $shape = classShape($src, $class);
    if ($shape === null) {
        return null;
    }
    // The three methods PHP synthesises onto every backed/pure enum. They are declared in no
    // file, so a scan for declarations reads `Severity::cases()` as a phantom.
    if ($shape['kind'] === 'enum' && in_array($member, ['cases', 'from', 'tryFrom'], true)) {
        return true;
    }

    $name = preg_quote(ltrim($member, '$'), '/');
    $declarations = str_starts_with($member, '$')
        ? ['/(?:public|protected|private|readonly|var)\s+(?:[^;{()]*\s)?\$'.$name.'\b/']
        : [
            '/\bfunction\s+'.$name.'\s*\(/i',
            '/\bconst\s+(?:[A-Za-z_][A-Za-z0-9_]*\s+)?'.$name.'\b/',
            '/\bcase\s+'.$name.'\b/',
            '/(?:public|protected|private|readonly)\s+(?:[^;{()]*\s)?\$'.$name.'\b/',
        ];
    foreach ($declarations as $pattern) {
        if (preg_match($pattern, $shape['body']) === 1) {
            return true;
        }
    }

    $answered = true;
    foreach (ancestorsOf($src, $shape) as $ancestor) {
        $ancestorFile = classFileForAncestor($root, $ancestor);
        if ($ancestorFile === null) {
            $answered = false;

            continue;
        }
        $inherited = memberDeclared($root, $ancestorFile, shortName($ancestor), $member, $depth + 1);
        if ($inherited === true) {
            return true;
        }
        if ($inherited === null) {
            $answered = false;
        }
    }

    return $answered ? false : null;
}

/**
 * The declaration `$class` makes in `$src`: its kind, the header between the name and the
 * opening brace, and the body inside it.
 *
 * TOKENIZED rather than pattern-matched, because the two things a regex gets wrong here are
 * the two this function exists for. A whole-file match lets a sibling class in the same file
 * declare the member — so the body is delimited by real brace depth, counting the `{` that
 * opens an interpolated string as well. And `enum` is a WORD as well as a keyword: a docblock
 * saying "mentions an enum Severity in passing" made a plain class answer `cases()`/`from()`,
 * where `T_ENUM` is emitted for the declaration and never for the prose.
 *
 * @return array{kind: string, header: string, body: string}|null
 */
function classShape(string $src, string $class): ?array
{
    $tokens = @token_get_all($src);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (! is_array($tok) || ! in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }
        // `Foo::class` carries the same token id as a declaration keyword, and an anonymous
        // `new class extends Base` would otherwise read as a declaration OF `Base` — the first
        // T_STRING after the keyword is the parent's name when the class has none of its own.
        $before = previousSignificant($tokens, $i);
        if ($before === T_DOUBLE_COLON || $before === T_NEW) {
            continue;
        }
        $kind = strtolower($tok[1]);
        $name = null;
        $header = '';
        $j = $i + 1;
        for (; $j < $count && $tokens[$j] !== '{'; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($name === null && is_array($t) && $t[0] === T_STRING) {
                $name = $t[1];
            }
            $header .= is_array($t) ? $t[1] : $t;
        }
        if ($name !== $class || $j >= $count) {
            continue;
        }
        $body = '';
        $depth = 0;
        for (; $j < $count; $j++) {
            $t = $tokens[$j];
            $text = is_array($t) ? $t[1] : $t;
            if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $body .= $text;
        }

        return ['kind' => $kind, 'header' => $header, 'body' => $body];
    }

    return null;
}

/** The id of the last token before `$i` that is not whitespace or a comment, or null. */
function previousSignificant(array $tokens, int $i): int|string|null
{
    for ($k = $i - 1; $k >= 0; $k--) {
        $t = $tokens[$k];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($t) ? $t[0] : $t;
    }

    return null;
}

/**
 * The FULLY QUALIFIED names a class body inherits from: `extends`/`implements` targets from
 * the header, and the traits its body `use`s.
 *
 * QUALIFIED THROUGH THE FILE'S IMPORT BLOCK, which is the whole point. An unqualified
 * `extends Command` in a file carrying `use Illuminate\Console\Command;` is definitively not
 * an `app/` class, but as a bare basename it resolves against `app/` and lets any same-named
 * neighbour answer for a framework base — a FALSE finding, on a class this scan cannot see
 * into, of exactly the cry-wolf kind that gets a gate switched off. An unimported bare name is
 * qualified with the file's own namespace, which is what PHP itself does.
 *
 * The body is already delimited to the cited class, so a trait `use` is unambiguous there,
 * while a closure's `use (` cannot match. (A `use A, B { … }` conflict block contributes its
 * names; there is none in this tree.)
 *
 * @param  array{kind: string, header: string, body: string}  $shape
 * @return list<string>
 */
function ancestorsOf(string $src, array $shape): array
{
    $imports = importsOf($src);
    $namespace = namespaceOf($src);

    $header = (string) preg_replace('/^\s*[A-Za-z0-9_]+\s*(?::\s*[A-Za-z_\\\\]+)?/', '', $shape['header']);
    $header = (string) preg_replace('/\b(?:extends|implements)\b/', ',', $header);

    $names = preg_split('/[\s,]+/', trim($header)) ?: [];
    if (preg_match_all('/(?:^|[;{}\s])use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\,\s]*?)\s*[;{]/', $shape['body'], $m) !== false) {
        foreach ($m[1] as $group) {
            foreach (preg_split('/[\s,]+/', trim($group)) ?: [] as $name) {
                $names[] = $name;
            }
        }
    }

    $out = [];
    foreach ($names as $name) {
        if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', $name) !== 1) {
            continue;
        }
        $out[] = qualifyName($name, $namespace, $imports);
    }

    return $out;
}

/** The namespace `$src` declares, or '' for the global one. */
function namespaceOf(string $src): string
{
    return preg_match('/^namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;/m', $src, $m) === 1 ? $m[1] : '';
}

/**
 * The file's import block as alias => FQCN. Column-0 `use` only: an indented one sits inside a
 * class body and is a trait use, not an import. A grouped `use App\{A, B};` is not matched —
 * there is none in this tree, and an unmatched import leaves its ancestor unqualified and so
 * unresolvable, which is an unanswered question rather than a wrong answer.
 *
 * @return array<string, string>
 */
function importsOf(string $src): array
{
    $out = [];
    if (preg_match_all('/^use\s+(?!function\b|const\b)([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/m', $src, $m, PREG_SET_ORDER) === false) {
        return $out;
    }
    foreach ($m as $use) {
        $fqcn = $use[1];
        $alias = ($use[2] ?? '') !== '' ? $use[2] : (str_contains($fqcn, '\\') ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn);
        $out[$alias] = $fqcn;
    }

    return $out;
}

/** The class name without its namespace — the name the declaration itself carries. */
function shortName(string $fqcn): string
{
    return str_contains($fqcn, '\\') ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn;
}

/**
 * PHP's own name resolution, narrowed to what this scan needs: a leading `\` is already
 * absolute, a first segment matching an import takes that import's FQCN, and anything else is
 * relative to the file's namespace.
 *
 * @param  array<string, string>  $imports
 */
function qualifyName(string $name, string $namespace, array $imports): string
{
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }
    $first = explode('\\', $name)[0];
    if (isset($imports[$first])) {
        return $imports[$first].substr($name, strlen($first));
    }

    return $namespace === '' ? $name : $namespace.'\\'.$name;
}

/**
 * Does an annotation matching `$marker` DISCHARGE the citation `$tok` written on this line?
 *
 * ONE SCOPE, TWO VOCABULARIES — that is the whole reason this function exists (card#7127). Rule 1
 * carries two escape hatches, a removed-marker and a rejected alternative, and both answer the
 * same question: *does this annotation cover this citation?* They used to answer it at two
 * granularities in one file — the marker over the whole LINE, the rejection over the SENTENCE —
 * which is two formats for one thing, and left a third hatch free to arrive at a third. The
 * granularity is decided here, once; a caller supplies only the words it accepts. WHAT COUNTS AS
 * A SENTENCE is {@see sentencesOf()}'s answer, and its terminator set is deliberately not the
 * obvious one — read that docblock before assuming where a fragment ends.
 *
 * SENTENCE-SCOPED, AND THE ANNOTATION MUST NAME WHAT IT DISCHARGES. A line-scoped marker
 * discharges every citation on its line, and the lines carrying one are the longest prose in this
 * repo: a `docs/CHANGELOG.md` entry is a single line that narrates a removal AND cites the live
 * members that replaced it, so one truthful "X was removed" switched the gate off for all of them
 * — silently, and growing with every release note. No figure is quoted for that loss on purpose;
 * `--census` re-derives the buckets over the tree it is run on, and a number written here would be
 * a quoted authority no later pass recomputes.
 *
 * WHAT IT COSTS THE FROZEN DOCS, stated because it is a real cost and not a free win. The freeze
 * rule forbids editing a merged decision-log sentence (DL-277), so the discharge is an annotation
 * appended AFTER it — and an appended annotation is a different sentence. It must therefore repeat
 * the citation it discharges, which is one token more than "removed in v0.60" and is what makes an
 * annotation legible at all on a forty-sentence entry: an unnamed marker at the end of such an
 * entry says nothing about WHICH of its citations went. The reverse trade — keeping the wide scope
 * so the shorter annotation keeps working — is what was measured and rejected.
 *
 * THE UNIT IS A SENTENCE WITHIN A LINE, stated because it is a real edge rather than an oversight:
 * rule 1 reads one line at a time, so a marker written on the PREVIOUS wrapped line of the same
 * markdown paragraph does not reach the citation. The report names a file and a line, so the scope
 * an author is asked to satisfy is the one the report points at; reaching the paragraph instead
 * would need a rule that knows where a paragraph ends across six different prose surfaces, and
 * would re-admit exactly the distance this function exists to bound.
 */
function annotationCovers(string $line, string $tok, string $marker): bool
{
    foreach (sentencesOf($line) as $sentence) {
        if (str_contains($sentence, '`'.$tok.'`') && preg_match($marker, $sentence) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Is this citation inside a sentence that says the construct was REJECTED?
 *
 * Every decision-log entry carries an "Alternatives considered" section, and an alternative is
 * named in the same `Class::member` shape as the built thing. The member leg reads this file
 * too, so its examples must be quotable: there is no `ReactionTarget::survivesEcho` / `Severity::NotRequested`,
 * by decision — and a gate that reds on them demands that a log of choices name only the choice
 * that was made.
 *
 * The scope is {@see annotationCovers()}'s, and this hatch is where that scope was first got
 * right: a decision-log entry is one enormous line whose alternatives sit beside the
 * consequences, so a line-scoped read of "rejected" would exempt the built constructs cited two
 * clauses away. Measured over `CLAUDE_DECISIONS.md` when it landed: 11 citations exempted, of
 * which 2 were findings.
 */
function citedAsRejected(string $line, string $tok): bool
{
    return annotationCovers($line, $tok, '/\brejected\b|\bnot built\b|\bnot chosen\b|\bnot adopted\b/i');
}

/**
 * The `Class::member` citation a token makes, or null if it makes none.
 *
 * `::class` is excluded (it is a language construct, not a member), as are the pseudo-classes
 * `self`/`static`/`parent`, which name no file.
 *
 * READ UP TO THE FIRST `(`, so a citation that carries ARGUMENTS is the same citation. The
 * anchored form matched `Foo::bar()` and dropped `Foo::bar(string $x, …)` before the census
 * ever counted it — 96 `::`-bearing tokens in the scanned docs alone, 36 of them genuine
 * citations resolving under `app/`. The argument list is prose about the signature; the
 * referent is the member, and it is the referent this leg answers for.
 *
 * @return array{0: string, 1: string}|null [class, member]
 */
function memberCitation(string $tok): ?array
{
    $tok = trim($tok);
    $args = strpos($tok, '(');
    if ($args !== false) {
        $tok = substr($tok, 0, $args);
    }
    if (preg_match('/^\\\\?([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::(\$?[A-Za-z_][A-Za-z0-9_]*)$/', $tok, $m) !== 1) {
        return null;
    }
    if ($m[2] === 'class' || in_array(strtolower($m[1]), ['self', 'static', 'parent', 'this'], true)) {
        return null;
    }

    return [$m[1], $m[2]];
}

// The vocabulary that deliberately names dead code: an explicit removed-marker, or a
// struck-through (~~…~~) historical heading. ⚠ `~~` NARROWED WITH THE PROSE MARKERS
// (card#7127): a struck block spanning several sentences on one line now discharges only the
// fragment carrying the `~~`, not the whole line. Zero instances in this tree strike more than
// one fragment, so this is disclosed rather than measured — and it is named here because every
// other sentence describing the scope talks about prose markers, where a reader would not
// infer it. `ever existed` is the marker the freeze rule
// forces on the decision log — a frozen sentence cannot be corrected in place, so the
// correction is an annotation appended beside it saying the referent never existed (DL-273).
//
// EVERY WORD HERE IS SATISFIABLE BY AN ANNOTATION APPENDED BESIDE THE ORIGINAL, which is why
// the vocabulary is spelled as statements rather than as a directive: the freeze rule forbids
// editing a merged entry, so the only discharge a frozen sentence can offer is a clause added
// after it. A rule whose escape hatch required rewriting the sentence would have no escape
// hatch on the two documents that need one most. What the annotation may NOT omit is the
// citation itself — {@see annotationCovers()} owns that scope, and owns it for both hatches.
//
// `removed` AND `renamed` ARE WORD-BOUNDED, not parenthesised, and that is a widening this
// change owed. `\(removed\b` matched only "(removed in vX)", so "`X` removed (dead)" — a
// perfect removal marker — did not discharge, and a rename ("`X` is renamed `Y`") had no
// vocabulary at all. Both are the speech act this list exists for, and both are what a
// CHANGELOG entry says by construction: the surface widening that brought release notes and
// living plan docs into scope is what turned that narrowness into a gate reddening on correct
// prose. Word-bounded is the bound that keeps it honest — an identifier that merely STARTS
// with the word (`removedAt`, `renamedTo`) discharges nothing.
$removedMarker = '/\bremoved\b|\brenamed\b|\bdeleted\b|no longer exists|there is no\b|replaced by\b|ever existed\b|~~/i';
$errors = [];

// RULE 1a — the path / FQCN leg, over the CURRENT-STATE docs only.
foreach ($docs as $doc) {
    $path = $root.'/'.$doc;
    if (! is_file($path)) {
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        if (preg_match_all('/`([^`]+)`/', $line, $m) === false) {
            continue;
        }
        foreach ($m[1] as $tok) {
            if (annotationCovers($line, $tok, $removedMarker)) {
                continue;
            }
            foreach (refsFromToken($tok) as $ref) {
                if (! refResolves($root, $ref)) {
                    $errors[] = sprintf('%s:%d  names `%s` (missing)', $doc, $i + 1, $tok);
                }
            }
        }
    }
}

// RULE 1b — the MEMBER leg, over every source rules 2 and 3 walk (see the rule docblock).
foreach (scannedSources($root) as $rel) {
    foreach (file($root.'/'.$rel, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        if (preg_match_all('/`([^`]+)`/', $line, $m) === false) {
            continue;
        }
        foreach ($m[1] as $tok) {
            $citation = memberCitation($tok);
            if ($citation === null) {
                continue;
            }
            if (annotationCovers($line, $tok, $removedMarker)) {
                tally('removed_marker_sentence');

                continue;
            }
            if (citedAsRejected($line, $tok)) {
                tally('rejected_alternative');

                continue;
            }
            [$class, $member] = $citation;
            $resolved = classFileForCitation($root, $class);
            if ($resolved['file'] === null) {
                tally($resolved['reason']);

                continue;
            }
            // The DECLARED name is the short one — an FQCN citation and a bare one name the
            // same declaration, and only one of the two spellings appears in the file.
            $declared = memberDeclared($root, $resolved['file'], shortName($class), $member);
            if ($declared === null) {
                tally('unverifiable_ancestry');

                continue;
            }
            if ($declared) {
                tally('resolved');

                continue;
            }
            tally('reported');
            $errors[] = sprintf('%s:%d  names `%s` — %s declares no %s (missing)', $rel, $i + 1, $tok, $resolved['file'], $member);
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

/**
 * `$text` split into sentences.
 *
 * A TERMINATOR IS NOT ALWAYS FOLLOWED BY WHITESPACE, and in this repo it usually is not. The
 * obvious pattern — `[.!?]\s+` — reads *"…was removed.** `Foo::bar()` is live"* as ONE sentence,
 * because the bold close sits between the period and the space. `docs/CHANGELOG.md` and
 * `CLAUDE_DECISIONS.md` are the two surfaces the sentence scope exists for and they carry hundreds
 * of that terminator each, so under the naive pattern a marker sentence merges with everything
 * after it and hands the whole line back to the marker — the line scope restored at green, one
 * character from the case the tests do cover. Closing emphasis, quotes and brackets are therefore
 * part of the terminator: `.**`, `.*`, `."`, `.)`, and the `.**]**` an appended annotation ends on.
 *
 * BYTE-ORIENTED, NOT `/u`, AND THAT IS MEASURED RATHER THAN ASSUMED. The class carries three
 * multi-byte closers, so `/u` is the reflex; but `preg_split` with `/u` returns `false` on input
 * that is not valid UTF-8, and this function's fallback is the WHOLE text — which would silently
 * restore the widest possible scope on precisely the file nobody inspects. Without `/u` the same
 * five inputs (`’`, `”`, `»`, `…`, an em-dash mid-prose) split identically and every fragment is
 * valid UTF-8, because each closer's bytes are consumed whole or not at all. The fallback stays
 * fail-open on purpose — a fragment this function cannot compute must never invent a finding — and
 * this is the bound that keeps the fail-open path unreachable for real input.
 *
 * @return list<string>
 */
function sentencesOf(string $text): array
{
    return preg_split('/(?<=[.!?])[)\]"\'’”*_`»]*\s+/', $text) ?: [$text];
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

/**
 * What the member leg DECLINED to answer, on every run.
 *
 * Three populations `continue` in silence otherwise, and a closing line reading "all PHP
 * references resolve" would then speak for citations this leg never examined — the exact shape
 * DL-251 / stage 10 removed from `bridge:check`, where a leg that measured nothing had been
 * reporting `ok`. The buckets sum to the population, so the disclosure is arithmetic rather
 * than adjectival: `--census` prints the same numbers with the examined half beside them.
 */
$census = tally();
$unexamined = $census['class_not_under_app'] + $census['ambiguous_class_name'] + $census['unverifiable_ancestry']
    + $census['removed_marker_sentence'] + $census['rejected_alternative'];

if (in_array('--census', $argv, true)) {
    fwrite(STDOUT, "doc-refs member census (re-derived over this tree by this run):\n");
    foreach ($census as $bucket => $n) {
        fwrite(STDOUT, sprintf("  %-24s %d\n", $bucket, $n));
    }
    fwrite(STDOUT, sprintf(
        "  %-24s %d\n",
        'TOTAL citations',
        $census['resolved'] + $census['reported'] + $unexamined
    ));
    fwrite(STDOUT, "`depth_bail` is not a bucket of the total — it counts ancestry walks abandoned at the depth bound, each of which lands its citation in `unverifiable_ancestry` unless another ancestor answered first.\n");
}

fwrite(STDOUT, sprintf(
    "doc-refs: member citations — %d examined (%d resolved, %d reported), %d NOT examined: %d class not under app/, %d ambiguous class name, %d unverifiable ancestry, %d in a removed-marker sentence, %d a rejected alternative; %d ancestry walk(s) hit the depth bound. This run says nothing about the members in that second half.\n",
    $census['resolved'] + $census['reported'],
    $census['resolved'],
    $census['reported'],
    $unexamined,
    $census['class_not_under_app'],
    $census['ambiguous_class_name'],
    $census['unverifiable_ancestry'],
    $census['removed_marker_sentence'],
    $census['rejected_alternative'],
    $census['depth_bail'],
));

if ($errors !== [] || $citeErrors !== [] || $claimErrors !== []) {
    if ($errors !== []) {
        fwrite(STDERR, "Dangling doc references (a CLAUDE_*.md names a PHP file, class or class member that does not exist):\n");
        foreach ($errors as $e) {
            fwrite(STDERR, "  - {$e}\n");
        }
        fwrite(STDERR, "\nFix the reference, or — if the construct was deliberately removed or renamed — say so in the\nSAME SENTENCE as the citation (\"removed\", \"renamed\", \"there is no …\"). CLAUDE_DECISIONS.md\nand docs/CHANGELOG.md entries are frozen, so that marker goes in an ANNOTATION appended after\nthe original sentence — and the annotation must REPEAT the citation it discharges, in\nbackticks, or it covers nothing on that line. An alternative the entry says it REJECTED needs\nnothing. See DL-013 and its DL-291 annotation.\n");
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
