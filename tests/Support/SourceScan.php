<?php

namespace Tests\Support;

/**
 * The line-scanner preamble the source-grep instruments in `tests/Feature/Writeback/`
 * share: walk a PHP source file line by line, skipping the lines that are PROSE.
 *
 * Extracted at the second real caller (canon #5, card#7212 review). Four copies of this
 * loop had been written in one directory — `WritebackSuccessBoardRecordTest` spelled it twice
 * and `WritebackRefusalSignalCoverageTest` twice more (that second file's two copies are a
 * REMAINDER: migrating them was out of this change's file scope, and they are named here so
 * the next toucher finds the owner rather than minting a fifth) — differing only in the regex
 * applied to each line. The comment skip is the
 * load-bearing part and the part that can be wrong: these handlers discuss their own
 * writes at length in prose, so an instrument that scanned comments would report call
 * sites that do not exist, and one that scanned nothing would report a clean repo.
 *
 * ⛔ WHAT THE SKIP IS, exactly, and its bound. It is a LINE test, not a PHP parse: a line
 * whose first non-space character opens or continues a comment (`//`, `/*`, `*`) is
 * dropped. That covers every comment shape these files actually use — `//` runs, and
 * docblocks whose continuation lines all start `*`. It does NOT cover a trailing comment
 * on a code line (`$client->moveCard(...);  // and $client->archiveCard(` would count
 * both), nor a slash-star block comment opened mid-line. Both are absent from the scanned
 * population and both fail LOUD (an extra site the caller must account for), never
 * silent — which is the direction a census instrument must fail in.
 */
final class SourceScan
{
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
}
