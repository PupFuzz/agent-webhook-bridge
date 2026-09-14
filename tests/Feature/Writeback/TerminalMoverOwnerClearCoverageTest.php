<?php

namespace Tests\Feature\Writeback;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Every `->moveCard(` call site in `app/` carries a RULING on the DL-386 owner-tag clear: it
 * calls `OwnerTag::clearAfterTerminalMove()` after its move, or it can never move a card into a
 * terminal stage, and says why.
 *
 * ⭐ THE POPULATION IS DERIVED, NOT LISTED. The first cut of this change named its terminal movers
 * in prose, and a mover added later would have inherited nothing from that prose. The keys below
 * are asserted SET-EQUAL to the call sites {@see SourceScan::sitesInApp} finds, so a new
 * `->moveCard(` site reds until somebody rules on it, and a ruling whose site has gone reds as
 * stale.
 *
 * ⚠ WHAT IS VERIFIED AND WHAT IS NOT. For a {@see CLEARS} ruling the scanner requires a call to the
 * primitive AFTER the move, in the same function body; for a never-terminal ruling it requires
 * there be none. It does NOT verify that a conditional call's condition is the right terminality
 * rule, nor that a never-terminal reason is true — the handler tests own the first, and a
 * reviewer reads the second.
 */
class TerminalMoverOwnerClearCoverageTest extends TestCase
{
    private const MOVE_METHOD = 'moveCard';

    private const CLEAR_CLASS = 'OwnerTag';

    private const CLEAR_METHOD = 'clearAfterTerminalMove';

    /** The ruling for a site whose body calls the clear after its move. */
    private const CLEARS = true;

    /**
     * EVERY DERIVED `->moveCard(` SITE → `self::CLEARS`, or the reason it can never move a card
     * into a terminal stage.
     *
     * @var array<string, true|string>
     */
    private const RULINGS = [
        // The coord-card close, into the configured `coord_card_terminal_stage_id`.
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::moveOne#1' => self::CLEARS,
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::moveOne#2' => 'the REVIVE leg moves a card OUT of the terminal, to coord_card_stage_id or a lane stage, and WritebackConfig refuses either being equal to coord_card_terminal_stage_id',
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::relaneOne#1' => 'the RELANE leg moves lane to lane, and WritebackConfig refuses a coord_card_lane_stage_ids stage equal to coord_card_terminal_stage_id',
        // The dependabot survivor move; clears when WritebackMapping::isTerminalStage() says so.
        'Bridge/Handlers/KanbanDependabotCardHandler.php::handle#1' => self::CLEARS,
        // The PR writeback; clears when WritebackMapping::isTerminalStage() says so.
        'Bridge/Handlers/KanbanMoveCardHandler.php::handle#1' => self::CLEARS,
        // Shipped → Released: the target is the merged_to_main stage, terminal by definition.
        'Bridge/Handlers/KanbanPromoteReleasedHandler.php::promoteIfReleased#1' => self::CLEARS,
        // `bridge:reconcile --fix`; clears when the planned row carried a terminal target.
        'Console/Commands/Bridge/ReconcileCommand.php::finish#1' => self::CLEARS,
    ];

    public function test_every_move_card_site_in_app_carries_an_owner_clear_ruling(): void
    {
        $derived = self::sites();
        $ruled = self::RULINGS;
        ksort($derived);
        ksort($ruled);

        $this->assertSame(
            array_keys($ruled),
            array_keys($derived),
            'the set of `->moveCard(` call sites in app/ is not the set this class has a ruling for. A NEW '
            .'site either calls OwnerTag::clearAfterTerminalMove() after its move — because kanban keeps an '
            .'owner:<project>/<seat> claim on a finished card otherwise, holding a seat — or can never reach a '
            .'terminal stage, and its entry says why. A ruling whose site is gone is stale: delete it.',
        );
    }

    public function test_each_ruling_matches_what_its_site_does(): void
    {
        foreach (self::sites() as $site => $clears) {
            $ruling = self::RULINGS[$site] ?? null;
            if ($ruling === null) {
                continue;   // the set leg above owns an unruled site
            }
            if ($ruling === self::CLEARS) {
                $this->assertTrue($clears, "{$site} is ruled to clear the owner tag, but its body calls no ".self::CLEAR_CLASS.'::'.self::CLEAR_METHOD.'() after the move.');

                continue;
            }
            $this->assertNotSame('', trim($ruling), "{$site} is ruled never-terminal with no reason.");
            $this->assertFalse($clears, "{$site} is ruled never-terminal, yet its body calls ".self::CLEAR_CLASS.'::'.self::CLEAR_METHOD.'() after the move — rule it self::CLEARS instead.');
        }
    }

    /**
     * The instrument's own control, on a fixture whose answer is known: a mention in prose is not
     * a site, a declaration is not a site, a nullsafe call is, and the clear counts only AFTER the
     * move and only in the same body.
     */
    public function test_the_scanner_tells_a_clearing_site_from_a_non_clearing_one(): void
    {
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming ->moveCard( and OwnerTag::clearAfterTerminalMove(. */
            public function clears(): void
            {
                // $client->moveCard($id, $stage);
                $client->moveCard($id, $stage);
                if ($terminal) {
                    OwnerTag::clearAfterTerminalMove($a, $client, $m, 'arm', $id, $repo, $outcome);
                }
            }

            public function clearsBeforeOnly(): void
            {
                OwnerTag::clearAfterTerminalMove($a, $client, $m, 'arm', $id, $repo, $outcome);
                $client?->moveCard($id, $stage);
            }

            public function twoMovesOneClearBetween(): void
            {
                $client->moveCard($id, $terminal);
                OwnerTag::clearAfterTerminalMove($a, $client, $m, 'arm', $id, $repo, $outcome);
                $client->moveCard($id, $lane);
            }

            public function clearInAnotherMethod(): void
            {
                $client->moveCard($id, $stage);
            }

            public function moveCard(int $id, int $stage): void
            {
                OwnerTag::clearAfterTerminalMove($a, $client, $m, 'arm', $id, $repo, $outcome);
            }
        }
        PHP;

        $this->assertSame(
            [
                'Fixture.php::clears#1' => true,
                'Fixture.php::clearsBeforeOnly#1' => false,
                'Fixture.php::twoMovesOneClearBetween#1' => true,
                'Fixture.php::twoMovesOneClearBetween#2' => false,
                'Fixture.php::clearInAnotherMethod#1' => false,
            ],
            SourceScan::sites($source, 'Fixture.php', self::siteAt(...)),
        );
    }

    /** @return array<string, bool> site => whether its body calls the clear after the move */
    private static function sites(): array
    {
        /** @var array<string, bool> $sites */
        $sites = SourceScan::sitesInApp(self::siteAt(...));

        return $sites;
    }

    /**
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @param  int  $scopeStart  the index of the enclosing `function` token (or where file scope resumed)
     */
    private static function siteAt(array $tokens, int $index, int $scopeStart): ?bool
    {
        if ($tokens[$index][0] !== T_STRING || $tokens[$index][1] !== self::MOVE_METHOD) {
            return null;
        }
        $arrow = $tokens[$index - 1][0] ?? null;
        if (($arrow !== T_OBJECT_OPERATOR && $arrow !== T_NULLSAFE_OBJECT_OPERATOR) || ($tokens[$index + 1][1] ?? null) !== '(') {
            return null;
        }

        $end = self::bodyEnd($tokens, $scopeStart);
        for ($i = $index + 1; $i < $end; $i++) {
            if (($tokens[$i][1] ?? null) === self::CLEAR_CLASS
                && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
                && ($tokens[$i + 2][1] ?? null) === self::CLEAR_METHOD
                && ($tokens[$i + 3][1] ?? null) === '(') {
                return true;
            }
        }

        return false;
    }

    /**
     * The index one past the `}` closing the body that opens at the first `{` after $scopeStart;
     * the end of the token list for a site outside any named function.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function bodyEnd(array $tokens, int $scopeStart): int
    {
        if (($tokens[$scopeStart][0] ?? null) !== T_FUNCTION) {
            return count($tokens);
        }
        $depth = 0;
        for ($i = $scopeStart, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i][1] === '{') {
                $depth++;
            } elseif ($tokens[$i][1] === '}' && --$depth === 0) {
                return $i + 1;
            }
        }

        return count($tokens);
    }
}
