<?php

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Drives the REAL `bin/check-doc-refs.php` — the copy CI runs — over synthetic repo trees,
 * one tree per vector so no verdict is ever read through another's leftovers.
 *
 * The script resolves its scan root as `dirname(__DIR__)`, so copying it one level below
 * `<tmp>` makes `<tmp>` the repo it examines. Deliberately NO root argument was added to the
 * script for this: a path argument that overrides the scanned set is precisely how a run fakes
 * coverage, and these gates exist to catch what a targeted pass misses.
 *
 * IT GOES IN `harness/`, NOT `bin/`, AND THAT IS LOAD-BEARING. `bin/` is inside the surface
 * rule 1's member leg reads, so the script parked there was examined by itself against every
 * fixture — a `Class::member` quoted in its own comments became a finding the moment a vector
 * declared a class of that name, reporting a defect in the FIXTURE as one in the script. It
 * fired exactly once, on a comment, which is one time more than a hazard needs to fire. No
 * directory named `harness` is scanned by any of the three rules, so the copy is invisible to
 * all of them; a vector that wants to exercise a path-based exemption writes that path itself,
 * where the intent is legible.
 *
 * SHARED RATHER THAN COPIED. The script carries several independent rules with overlapping
 * surfaces, and each rule's vectors have to run against a tree built the same way — a second
 * divergent copy of this harness would let one rule be exercised on a tree shape the other's
 * evidence never covered, which is the failure the rules themselves are built against.
 */
trait DocRefGateHarness
{
    /** Outside every scanned root, so the script under test is not part of the tree it examines. */
    private const HARNESS_DIR = 'harness';

    /** @var list<string> fixture trees to remove after the test */
    private array $gateTrees = [];

    /**
     * @param  array<string, string>  $files  repo-relative path => FULL file content
     * @return array{0: int, 1: string} exit code, combined output
     */
    protected function runGate(array $files): array
    {
        $root = $this->makeGateTree();
        foreach ($files as $rel => $content) {
            $path = $root.'/'.$rel;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }

        $out = [];
        $rc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/'.self::HARNESS_DIR.'/check-doc-refs.php').' 2>&1', $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    protected function removeGateTrees(): void
    {
        foreach ($this->gateTrees as $tree) {
            $this->removeTree($tree);
        }
        $this->gateTrees = [];
    }

    /** A fresh repo root whose scanned surface is EMPTY until a vector writes into it. */
    private function makeGateTree(): string
    {
        $root = sys_get_temp_dir().'/doc-refs-'.bin2hex(random_bytes(8));
        mkdir($root.'/'.self::HARNESS_DIR, 0755, true);
        copy(base_path('bin/check-doc-refs.php'), $root.'/'.self::HARNESS_DIR.'/check-doc-refs.php');
        $this->gateTrees[] = $root;

        return $root;
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
}
