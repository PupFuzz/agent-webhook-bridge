<?php

namespace Tests\Unit\Docs;

use App\Bridge\Tools\BoardMyCardsTool;
use Tests\TestCase;

/**
 * The default `board_my_cards` card cap, wherever this tree STATES it, held in lockstep with the
 * constant that enforces it (card#8985 / DL-365).
 *
 * ⭐ A FIGURE IN PROSE IS A RESTATEMENT OF WHATEVER IT COUNTED, and this one cannot simply be
 * deleted in favour of a pointer: a seat reading the board-tools contract has to know how many
 * cards a default call returns before it decides whether to narrow or to raise `limit`, and
 * "read the constant" is not an answer for a consumer that does not have this checkout. So the
 * figure stays written down and carries a CHECK instead — which is the canon's own exception for
 * a load-bearing threshold, on the terms it names: a pin, and something that reds when the pin
 * and the code disagree.
 *
 * ⭐ THE POPULATION IS DERIVED ON EVERY RUN, not a list of the files this card happened to touch.
 * It is every tracked file matching the marker spelling below, so a surface that starts restating
 * the cap tomorrow joins the denominator by existing rather than by somebody remembering to add
 * it here. That is the same reasoning the crontab-line census is built on, and for the same
 * reason: a guard scoped to the instances that produced it goes quiet exactly when a new copy
 * appears.
 *
 * ⚠ WHAT IT DOES NOT CATCH, stated so a green run is not read as more than it is:
 *   (a) It keys on ONE SPELLING — `<N> cards per list`. Prose that states the cap some other way
 *       ("fifty-two cards", "the default window is 52") is invisible to it. The spelling is
 *       therefore a convention this repo keeps, not a property the guard can enforce; what the
 *       guard buys is that every copy written in the house spelling is true.
 *   (b) HISTORY is excluded. A released changelog entry and an append-only decision-log entry
 *       record what shipped at a point in time; rewriting them to match a later cap would
 *       falsify the record, so a stale figure there is correct rather than drifted.
 *   (c) `tests/` is excluded — a figure written there is an ASSERTION against the code, which
 *       reds on drift by itself. (This file's own near-miss fixtures live there too.)
 *   (d) `examples/channel-servers/` deliberately states NO number at all, because consumers COPY
 *       that directory and their copy cannot be re-synced by an edit here.
 */
class BoardMyCardsCapLockstepTest extends TestCase
{
    /**
     * The house spelling every live restatement of the cap uses. Anchored on the trailing words
     * so that the constant's own declaration (`DEFAULT_MAX_CARDS = 52;`) is NOT a restatement of
     * itself — a guard that read the definition as a copy would be green by construction.
     */
    private const MARKER = '/\b(\d{1,4}) cards per list\b/';

    /** Append-only records of what shipped; see bound (b). */
    private const HISTORY = [
        'CLAUDE_DECISIONS.md',
        'docs/CHANGELOG.md',
    ];

    /** See bounds (c) and (d). */
    private const EXCLUDED_PREFIXES = [
        'tests/',
        'examples/channel-servers/',
    ];

    public function test_every_stated_default_cap_agrees_with_the_constant(): void
    {
        $stated = [];
        foreach ($this->trackedFiles() as $path) {
            foreach ($this->figuresIn((string) file_get_contents(base_path($path))) as $figure) {
                $stated[] = [$path, $figure];
            }
        }

        // ⚠ THE PRESENCE WITNESS FIRST. An empty census passes every comparison below, so
        // without this the guard would go green the moment the marker spelling was edited away
        // — reporting where the search stopped, not the state of the tree.
        $this->assertNotSame([], $stated, 'No tracked live surface states the board_my_cards default cap in the house spelling ("<N> cards per list"). Either the spelling drifted or the figure was dropped from the contract docs; both are findings, not a pass.');

        foreach ($stated as [$path, $figure]) {
            $this->assertSame(
                (string) BoardMyCardsTool::DEFAULT_MAX_CARDS,
                $figure,
                "{$path} states a default board_my_cards cap of {$figure} cards per list, but BoardMyCardsTool::DEFAULT_MAX_CARDS is ".BoardMyCardsTool::DEFAULT_MAX_CARDS.'. Update the prose in the same change that moves the constant.'
            );
        }
    }

    public function test_the_predicate_discriminates(): void
    {
        // A real restatement, bolded exactly as the contract doc writes it.
        $this->assertSame(['52'], $this->figuresIn('the cap is **52 cards per list**, derived'));
        // The CONSTANT'S OWN DECLARATION is not a restatement — if this matched, the guard would
        // compare the constant against itself and could never fail.
        $this->assertSame([], $this->figuresIn('public const DEFAULT_MAX_CARDS = 52;'));
        // A near miss in the other direction: the figure without the marker's trailing words is
        // out of the census by design (bound (a)), and saying so here is what keeps that bound
        // honest rather than aspirational.
        $this->assertSame([], $this->figuresIn('the cap is 52 cards'));
    }

    /** @return list<string> */
    private function figuresIn(string $text): array
    {
        preg_match_all(self::MARKER, $text, $matches);

        return array_values($matches[1]);
    }

    /** @return list<string> */
    private function trackedFiles(): array
    {
        $output = [];
        exec('cd '.escapeshellarg(base_path()).' && git ls-files', $output, $status);
        $this->assertSame(0, $status, 'git ls-files failed — the population could not be derived, which is not the same as an empty one.');

        $kept = [];
        foreach ($output as $path) {
            if (in_array($path, self::HISTORY, true)) {
                continue;
            }
            foreach (self::EXCLUDED_PREFIXES as $prefix) {
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
