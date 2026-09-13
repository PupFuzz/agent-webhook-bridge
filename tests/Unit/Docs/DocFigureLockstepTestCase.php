<?php

namespace Tests\Unit\Docs;

use Tests\TestCase;

/**
 * The shape shared by every guard that holds a LOAD-BEARING FIGURE WRITTEN IN PROSE in lockstep
 * with the constant that owns it. Extracted at the second real caller (canon #5): the
 * `board_my_cards` default cap (card#8985 / DL-365) and the channel server's first reporting
 * snapshot (card#9336).
 *
 * ⭐ WHY A FIGURE IS WRITTEN DOWN AT ALL, since the canon's default is a derivation and not a
 * number. Both subjects are read by someone who DOES NOT HAVE THIS CHECKOUT — a seat reading the
 * board-tools contract, an operator reconciling a fleet from a snapshot directory — so "read the
 * constant" is a pointer to a place the reader cannot reach, which is the defect, not the fix. The
 * canon's own exception applies instead: a threshold may be stated as a figure when it carries a
 * pin and something that reds when the pin and the prose disagree. This class is that something.
 *
 * ⭐ THE POPULATION IS DERIVED ON EVERY RUN, not a list of the files the minting card happened to
 * touch. It is every tracked file matching the subclass's marker spelling, so a surface that starts
 * restating the figure tomorrow joins the denominator by existing rather than by somebody
 * remembering to add it here. That is the same reasoning the crontab-line census is built on, and
 * for the same reason: a guard scoped to the instances that produced it goes quiet exactly when a
 * new copy appears.
 *
 * ⚠ WHAT A GREEN RUN DOES NOT BUY, stated so it is not read as more than it is:
 *   (a) It keys on ONE SPELLING, {@see marker()}. Prose that states the figure some other way is
 *       invisible to it, and a subject that is never stated at all is invisible to it too — the
 *       presence witness catches the second case only once a surface HAS stated it in the house
 *       spelling. The spelling is therefore a convention this repo keeps, not a property the guard
 *       can enforce; what the guard buys is that every copy written in the house spelling is true.
 *   (b) HISTORY is excluded. A released changelog entry and an append-only decision-log entry
 *       record what shipped at a point in time; rewriting them to match a later value would
 *       falsify the record, so a stale figure there is correct rather than drifted.
 *   (c) `tests/` is excluded — a figure written there is an ASSERTION against the code, which reds
 *       on drift by itself. (Subclass near-miss fixtures and rendered golden captures live there.)
 *   (d) `examples/channel-servers/` is excluded — consumers COPY that directory, and their copy
 *       cannot be re-synced by an edit here.
 */
abstract class DocFigureLockstepTestCase extends TestCase
{
    /**
     * The house spelling as a regex with ONE capture group around the figure.
     *
     * ⛔ IT MUST NOT MATCH THE CONSTANT'S OWN DECLARATION. A guard that read the definition as a
     * restatement would compare the constant against itself and could never fail; the subclass
     * pins that in {@see test_the_predicate_discriminates()}.
     */
    abstract protected function marker(): string;

    /** The house spelling as a human reads it, quoted in the presence witness's message. */
    abstract protected function houseSpelling(): string;

    /** How the failure message names the constant, e.g. `BoardMyCardsTool::DEFAULT_MAX_CARDS`. */
    abstract protected function constantName(): string;

    /** The constant's value, as the prose spells it. */
    abstract protected function constantValue(): string;

    /** How the failure message names what the figure IS, e.g. `a default board_my_cards cap`. */
    abstract protected function subject(): string;

    /**
     * Required of every subclass, because a predicate nobody watched discriminate is a decoration:
     * pin a real hit AND the near misses the bounds above claim are out of the census.
     */
    abstract public function test_the_predicate_discriminates(): void;

    /**
     * Append-only records of what shipped; see bound (b). Override to widen, never to empty.
     *
     * @return list<string>
     */
    protected function historyFiles(): array
    {
        return [
            'CLAUDE_DECISIONS.md',
            'docs/CHANGELOG.md',
        ];
    }

    /**
     * See bounds (c) and (d).
     *
     * @return list<string>
     */
    protected function excludedPrefixes(): array
    {
        return [
            'tests/',
            'examples/channel-servers/',
        ];
    }

    public function test_every_stated_figure_agrees_with_the_constant(): void
    {
        $stated = [];
        foreach ($this->trackedFiles() as $path) {
            foreach ($this->figuresIn((string) file_get_contents(base_path($path))) as $figure) {
                $stated[] = [$path, $figure];
            }
        }

        // ⚠ THE PRESENCE WITNESS FIRST. An empty census passes every comparison below, so without
        // this the guard would go green the moment the marker spelling was edited away — reporting
        // where the search stopped, not the state of the tree.
        $this->assertNotSame([], $stated, 'No tracked live surface states '.$this->subject().' in the house spelling ('.$this->houseSpelling().'). Either the spelling drifted or the figure was dropped from the contract docs; both are findings, not a pass.');

        foreach ($stated as [$path, $figure]) {
            $this->assertSame(
                $this->constantValue(),
                $figure,
                "{$path} states ".$this->subject()." as {$figure}, but ".$this->constantName().' is '.$this->constantValue().'. Update the prose in the same change that moves the constant.'
            );
        }
    }

    /** @return list<string> */
    protected function figuresIn(string $text): array
    {
        preg_match_all($this->marker(), $text, $matches);

        return array_values($matches[1]);
    }

    /**
     * Every tracked file the census reads, repo-relative. `git ls-files` IS the exclusion of
     * `vendor/` and of anything untracked, and its FAILURE is reported rather than returned as an
     * empty population — a derivation that did not run is not a tree with nothing in it.
     *
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $output = [];
        exec('cd '.escapeshellarg(base_path()).' && git ls-files', $output, $status);
        $this->assertSame(0, $status, 'git ls-files failed — the population could not be derived, which is not the same as an empty one.');

        $kept = [];
        foreach ($output as $path) {
            if (in_array($path, $this->historyFiles(), true)) {
                continue;
            }
            foreach ($this->excludedPrefixes() as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }
            if (is_file(base_path($path))) {
                $kept[] = $path;
            }
        }

        return $kept;
    }
}
