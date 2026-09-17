<?php

namespace Tests\Unit\Docs;

use App\Bridge\Tools\BoardMyCardsTool;

/**
 * The default `board_my_cards` card cap, wherever this tree STATES it, held in lockstep with the
 * constant that enforces it (card#8985 / DL-365).
 *
 * ⭐ A FIGURE IN PROSE IS A RESTATEMENT OF WHATEVER IT COUNTED, and this one cannot simply be
 * deleted in favour of a pointer: a seat reading the board-tools contract has to know how many
 * cards a default call returns before it decides whether to narrow or to raise `limit`, and
 * "read the constant" is not an answer for a consumer that does not have this checkout.
 *
 * ⭐ THE MECHANISM IS {@see DocFigureLockstepTestCase}'s, hoisted there at its second caller
 * (card#9336) rather than copied: the re-derived tracked-file population, the HISTORY and
 * excluded-prefix bounds, and the presence witness are that class's, and its docblock owns the
 * reasoning behind each. What is subject-specific is below.
 *
 * ⚠ The one bound this subject adds to that class's (a): the spelling is `<N> cards per list`, so
 * prose that states the cap some other way ("fifty-two cards", "the default window is 52") is
 * invisible to the census.
 */
class BoardMyCardsCapLockstepTest extends DocFigureLockstepTestCase
{
    protected function marker(): string
    {
        // Anchored on the TRAILING WORDS so that the constant's own declaration
        // (`DEFAULT_MAX_CARDS = 52;`) is NOT a restatement of itself.
        return '/\b(\d{1,4}) cards per list\b/';
    }

    protected function houseSpelling(): string
    {
        return '"<N> cards per list"';
    }

    protected function constantName(): string
    {
        return 'BoardMyCardsTool::DEFAULT_MAX_CARDS';
    }

    protected function constantValue(): string
    {
        return (string) BoardMyCardsTool::DEFAULT_MAX_CARDS;
    }

    protected function subject(): string
    {
        return 'the board_my_cards default cap';
    }

    public function test_the_predicate_discriminates(): void
    {
        // A real restatement, bolded exactly as the contract doc writes it.
        $this->assertSame(['52'], $this->figuresIn('the cap is **52 cards per list**, derived'));
        // The CONSTANT'S OWN DECLARATION is not a restatement — if this matched, the guard would
        // compare the constant against itself and could never fail.
        $this->assertSame([], $this->figuresIn('public const DEFAULT_MAX_CARDS = 52;'));
        // A near miss in the other direction: the figure without the marker's trailing words is
        // out of the census by design, and saying so here is what keeps that bound honest rather
        // than aspirational.
        $this->assertSame([], $this->figuresIn('the cap is 52 cards'));
    }
}
