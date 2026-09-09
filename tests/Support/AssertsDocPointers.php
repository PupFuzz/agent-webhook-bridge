<?php

namespace Tests\Support;

/**
 * Assert that a `<path> § <heading>` pointer this program PRINTS names a heading the file
 * actually has.
 *
 * A POINTER WITH NO CHECK IS A COMMENT. These strings are printed to operators and emitted
 * to machine consumers, so a heading renamed in the doc leaves both following a section that
 * does not exist — and nothing else in this repo joins the two.
 *
 * A TRAIT AT THE SECOND CALLER, not a second copy. `CheckNextStepsTest` had this inline for
 * `NextSteps::DOC` and `BoardToolsLostCheck::DOC` needs exactly the same assertion; two
 * copies would be two chances to drift, and the first copy also hard-coded the FILE beside a
 * pointer that already names it — so a pointer into a different doc would have been checked
 * against `board-tools.md` and passed or failed for the wrong reason. Named rather than
 * `{@see}`-linked, as {@see SkipsAsRoot} records: pint rewrites a docblock FQCN into a real
 * `use`, and a consumer of this trait must not become its import.
 */
trait AssertsDocPointers
{
    protected function assertDocPointerNamesARealHeading(string $pointer): void
    {
        // Split rather than assumed: a pointer that is not in `<path> § <heading>` form is a
        // pointer this assertion cannot answer for, and saying so beats passing vacuously.
        $parts = explode(' § ', $pointer, 2);
        $this->assertCount(2, $parts, "'{$pointer}' is not a `<path> § <heading>` pointer, so nothing was checked");
        [$path, $heading] = $parts;

        $doc = base_path($path);
        $this->assertFileExists($doc, "'{$pointer}' names a file this repo does not have");

        $this->assertStringContainsString(
            "\n## {$heading}\n",
            (string) file_get_contents($doc),
            "'{$pointer}' names a section {$path} does not have",
        );
    }
}
