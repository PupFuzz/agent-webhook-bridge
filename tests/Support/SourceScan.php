<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The source-reading preamble the census instruments in `tests/Feature/Writeback/` share,
 * in its two shapes: a LINE walk that skips prose, and a TOKENIZED walk over the whole of
 * `app/` that keys every site it finds to the function it sits in.
 *
 * Extracted at the second real caller (canon #5, card#7212 review, then card#8530). Four
 * copies of the line loop had been written in one directory, differing only in the regex
 * applied to each line; the tokenized walk was written once for
 * `GetCardTenantCheckCoverageTest` (card#8440) and is hoisted here at its second caller
 * rather than copied, because the two halves of ONE tenant boundary deriving TWO different
 * populations is how a site ends up covered by neither.
 *
 * ⛔ WHAT THE LINE SKIP IS, exactly, and its bound. {@see codeLines} is a LINE test, not a
 * PHP parse: a line whose first non-space character opens or continues a comment (`//`,
 * `/*`, `*`) is dropped. That covers every comment shape these files actually use — `//`
 * runs, and docblocks whose continuation lines all start `*`. It does NOT cover a trailing
 * comment on a code line (`$client->moveCard(...);  // and $client->archiveCard(` would
 * count both), nor a slash-star block comment opened mid-line. Both are absent from the
 * scanned population and both fail LOUD (an extra site the caller must account for), never
 * silent — which is the direction a census instrument must fail in.
 *
 * ⭐ THE TOKENIZED WALK HAS NO SUCH BOUND, which is why a census over a whole tree uses it:
 * {@see significantTokens} drops comments and docblocks by CONSTRUCTION, so a mention of a
 * call or a field name in prose is not a site whatever spelling the prose uses. What it
 * costs is that the caller must answer at the TOKEN level (`is this token a site?`) rather
 * than with a regex over a line.
 */
final class SourceScan
{
    /**
     * The "enclosing function" of a site that has none — a top-level closure, a bare
     * statement, a constant in a class body. It is a KEY, not a skip: a site the walk
     * cannot attribute to a method must land in the population as an unattributed one.
     * Skipping it was the one shape in card#8440's first draft that failed OPEN.
     */
    public const FILE_SCOPE = '(file scope)';

    /**
     * The floor on the `app/` file population. Not the count — a floor, so a
     * `base_path('app')` that resolved to the wrong tree reports the derivation broken
     * rather than the repo clean.
     */
    private const MIN_APP_FILES = 50;

    /**
     * The non-comment lines of $source, keyed by 1-based line number.
     *
     * A generator rather than an array: every caller consumes it once in a foreach, and
     * the line number must survive the skip (a caller reporting `<file>:<line>` needs the
     * number the line has in the FILE, not its index among the lines that survived).
     *
     * @return \Generator<int, string>
     */
    public static function codeLines(string $source): \Generator
    {
        foreach (explode("\n", $source) as $i => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            yield $i + 1 => $line;
        }
    }

    /**
     * Every site $siteAt recognises in every `*.php` under `app/`, keyed
     * `<path under app/>::<enclosing function>#<ordinal within that function>`.
     *
     * ⭐ ONE POPULATION, DERIVED ONCE. Two coverage classes police the two ends of the
     * belongs-to-mapped-board boundary — the pre-read id check and the post-read compare —
     * and while they derived their own populations (one walking all of `app/`, the other a
     * pair of hand-written globs) a site could be, and was, outside both. The population is
     * a property of the TREE, not of either class, so it is spelled here once.
     *
     * @param  callable(list<array{0: int|string, 1: string}>, int, int): mixed  $siteAt
     * @return array<string, mixed>
     */
    public static function sitesInApp(callable $siteAt): array
    {
        $files = self::appFiles();
        Assert::assertGreaterThanOrEqual(
            self::MIN_APP_FILES,
            count($files),
            'the app/ file population came back short — the derivation, not the code, is what changed.',
        );

        $sites = [];
        foreach ($files as $path) {
            $sites += self::sites((string) file_get_contents($path), self::relativeToApp($path), $siteAt);
        }

        return $sites;
    }

    /** @return list<string> */
    public static function appFiles(): array
    {
        $paths = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    public static function relativeToApp(string $path): string
    {
        return ltrim(str_replace(base_path('app'), '', $path), '/');
    }

    /**
     * Every site $siteAt recognises in $source, keyed
     * `<$file>::<enclosing function>#<ordinal within that function>`.
     *
     * Keyed by FUNCTION AND ORDINAL, never by LINE NUMBER: an offset pin reds on an
     * unrelated docblock edit, and its only remediation ("re-derive the offsets") is the
     * same action that absorbs a real new site without a second thought. The ordinal is
     * what stops a SECOND site in an already-declared method inheriting the first one's
     * disposition.
     *
     * ⛔ $siteAt IS ASKED ABOUT EVERY TOKEN and answers with the site's VALUE, or `null`
     * when the token is not a site. `null` is the ONLY absence — `false`, `0` and `''` are
     * VALUES and land in the map. (`GetCardTenantCheckCoverageTest` stores exactly `false`
     * for an unguarded read, and its fixture control pins those entries, so a walk that
     * confused the two reds there.) $siteAt receives the whole token list, the index, and
     * the index at which the ENCLOSING SCOPE began — a visitor that needs to know what
     * precedes its site inside the same body (a guard, an assignment) looks back to there
     * rather than keeping state the walk would have to reset.
     *
     * @param  callable(list<array{0: int|string, 1: string}>, int, int): mixed  $siteAt
     * @return array<string, mixed>
     */
    public static function sites(string $source, string $file, callable $siteAt): array
    {
        $tokens = self::significantTokens($source);
        $sites = [];
        $function = self::FILE_SCOPE;
        $scopeStart = 0;
        $ordinals = [];
        $depth = 0;
        $awaitingBody = false;
        $bodyDepth = null;

        foreach ($tokens as $i => $token) {
            // Brace tracking is what returns the scope to the FILE once a named body
            // closes. Without it the last method in a file owns everything after it, and a
            // trailing closure's site is attributed to a method it is not in — the
            // misattribution card#8440's fixture control pins.
            if ($token[1] === '{') {
                $depth++;
                if ($awaitingBody) {
                    $bodyDepth = $depth;
                    $awaitingBody = false;
                }

                continue;
            }
            if ($token[1] === '}') {
                $depth--;
                if ($bodyDepth !== null && $depth < $bodyDepth) {
                    $function = self::FILE_SCOPE;
                    $scopeStart = $i + 1;
                    $bodyDepth = null;
                }

                continue;
            }
            if ($token[1] === ';' && $awaitingBody) {
                // An abstract or interface declaration has no body to enter.
                $awaitingBody = false;

                continue;
            }

            if ($token[0] === T_FUNCTION) {
                // A named declaration opens a new body; an anonymous `function (` or an
                // arrow `fn (` does not, so a closure's sites stay attributed to the method
                // that contains it — which is the body a reviewer reads — or, outside any
                // method, to the file scope.
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next[0] === T_STRING) {
                    $function = $next[1];
                    $scopeStart = $i;
                    $awaitingBody = true;
                }

                continue;
            }

            $value = $siteAt($tokens, $i, $scopeStart);
            if ($value === null) {
                continue;
            }

            $ordinals[$function] = ($ordinals[$function] ?? 0) + 1;
            $sites[$file.'::'.$function.'#'.$ordinals[$function]] = $value;
        }

        return $sites;
    }

    /**
     * $source's tokens as `[type, text]`, with whitespace and comments dropped so that
     * neighbour lookups in a visitor are structural rather than layout-dependent. A
     * single-char token (`(`, `{`, `=>` and `::` are not ones) carries its own text as the
     * type.
     *
     * @return list<array{0: int|string, 1: string}>
     */
    public static function significantTokens(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out[] = [$token[0], $token[1]];

                continue;
            }
            $out[] = [$token, $token];
        }

        return $out;
    }
}
