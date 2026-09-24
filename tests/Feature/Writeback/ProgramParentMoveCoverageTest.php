<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Handlers\KanbanPromoteReleasedHandler;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\ProgramCardGuard;
use App\Console\Commands\Bridge\ReconcileCommand;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Every `->moveCard(` call site in `app/` carries a RULING on the `program`-parent predicate
 * ({@see ProgramCardGuard}): either the site consults the guard before its
 * move, or its entry below says — in one line a reviewer can check — what it moves and that it
 * does not.
 *
 * ⛔ THIS CHECK CHANGES WHAT NOTHING REFUSES, AND THAT IS DELIBERATE. Whether an unguarded mover
 * should refuse, warn, or move-and-report is an ACCEPTANCE question, open on bridge card#10068
 * and reserved to the operator. What is closed here is the quieter half: today the coverage gap
 * is a property nobody measures, so the NEXT mover joins it in silence. The rulings below are
 * therefore a record of an OPEN gap, never a disposition that it is fine — read a string entry as
 * *"this writer does not consult the predicate"*, and nothing more.
 *
 * ⭐ THE POPULATION IS DERIVED, NOT LISTED — the same reason and the same instrument
 * {@see TerminalMoverOwnerClearCoverageTest} uses over this identical population
 * ({@see SourceScan::sitesInApp}, which the two coverage classes share precisely so a site cannot
 * fall outside both). A new `->moveCard(` site reds until somebody rules on it, which is how a
 * new writer of a card's stage arrives as a REVIEW EVENT rather than as a silence; a ruling whose
 * site is gone reds as stale.
 *
 * ⚠ WHAT IS VERIFIED AND WHAT IS NOT.
 *  - The scanner requires the consult to precede the move IN THE SAME FUNCTION BODY. A consult
 *    placed one frame up — where {@see KanbanPromoteReleasedHandler} decides
 *    its CANDIDATES and {@see ReconcileCommand} builds its PLAN, both
 *    of which hold the whole card row while the move site holds only an id — is invisible here and
 *    reds until its entry says so. That red is the intended one: it is a review event, not a
 *    false alarm, because "the guard is consulted somewhere upstream" is exactly the claim a
 *    reviewer must read rather than a grep believe.
 *  - It does NOT check that the consult's arguments are right, nor that a string ruling is true.
 *  - It matches the guard by its SHORT name, the spelling `vendor/bin/pint`'s own
 *    `fully_qualified_strict_types` fixer produces here; a consult written as a single
 *    fully-qualified token would read as no consult. That direction is the safe one — it reds a
 *    guarded site rather than greening an unguarded one — and it is stated rather than left to be
 *    met in a failure message.
 *  - ARCHIVE IS OUT OF THIS POPULATION, named rather than silently dropped: `archiveCard(` is in
 *    the PIN's census ({@see PinGuard}'s docblock owns that recipe and why),
 *    and card#10068's ruling is about writers of a TERMINAL STAGE. A parent card's archive is a
 *    live question and it is not this check's.
 */
class ProgramParentMoveCoverageTest extends TestCase
{
    private const MOVE_METHOD = 'moveCard';

    private const GUARD_CLASS = 'ProgramCardGuard';

    /** The ruling for a site whose body consults the guard before its move. */
    private const CONSULTS = true;

    /**
     * EVERY DERIVED `->moveCard(` SITE → `self::CONSULTS`, or what the site moves and the fact
     * that it reaches no consult. ⛔ A string is a GAP RECORD, not an approval (see the class
     * docblock); card#10068 holds the open decision on what each should do instead.
     *
     * @var array<string, true|string>
     */
    private const RULINGS = [
        // Coordination-card close, into `coord_card_terminal_stage_id` — a TERMINAL move. The
        // handler holds the full card row here (it consults PinGuard and MappedBoardGuard on it),
        // so the `program` tag is readable at this site for no extra request.
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::moveOne#1' => 'TERMINAL: the coord-card close; the row is in hand and the tag is readable here, and no consult is made',
        // The revive leg moves a card OUT of the terminal; the relane leg moves lane to lane.
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::moveOne#2' => 'NON-TERMINAL: the revive leg moves out of the terminal, and no consult is made',
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::relaneOne#1' => 'NON-TERMINAL: the relane leg moves lane to lane, and no consult is made',
        // Dependabot survivor move; reaches the merged_to_main stage.
        'Bridge/Handlers/KanbanDependabotCardHandler.php::handle#1' => 'TERMINAL-CAPABLE: the dependabot survivor move, whose stage comes from the outcome map; the row is in hand and no consult is made',
        // The GitHub-PR event path — the ONE consult that exists today (card#9929).
        'Bridge/Handlers/KanbanMoveCardHandler.php::handle#1' => self::CONSULTS,
        // Shipped → Released. ⚠ THE WRITER THAT ACTUALLY FIRES on this install's releases.
        'Bridge/Handlers/KanbanPromoteReleasedHandler.php::promoteIfReleased#1' => 'TERMINAL: Shipped→Released; this body holds only the card id, and the row (with its tags) is one frame up in the candidate scan, where PinGuard is already consulted — no `program` consult is made at either',
        // `bridge:reconcile --fix`, whose plan rows can carry a terminal target.
        'Console/Commands/Bridge/ReconcileCommand.php::finish#1' => 'TERMINAL-CAPABLE: the reconcile fix-up move; this body holds only the plan row, and the card row (with its tags) is in the plan-building body, where PinGuard is already consulted — no `program` consult is made at either',
    ];

    public function test_every_move_card_site_in_app_carries_a_program_parent_ruling(): void
    {
        $derived = self::sites();
        $ruled = self::RULINGS;
        ksort($derived);
        ksort($ruled);

        $this->assertSame(
            array_keys($ruled),
            array_keys($derived),
            'the set of `->moveCard(` call sites in app/ is not the set this class has a ruling for. A NEW site '
            .'writes a card\'s stage, so it either consults '.self::GUARD_CLASS.' before its move — a card carrying '
            .'the `program` tag names SEVERAL legs, and no one event may speak for it — or its entry says what it '
            .'moves and that it does not. ⛔ Adding a REFUSAL to a writer that moves today is an acceptance change '
            .'and is card#10068\'s open operator decision; adding the RULING is not. A ruling whose site is gone is '
            .'stale: delete it.',
        );
    }

    public function test_each_ruling_matches_what_its_site_does(): void
    {
        foreach (self::sites() as $site => $consults) {
            $ruling = self::RULINGS[$site] ?? null;
            if ($ruling === null) {
                continue;   // the set leg above owns an unruled site
            }
            if ($ruling === self::CONSULTS) {
                $this->assertTrue($consults, "{$site} is ruled to consult the parent-card guard, but no ".self::GUARD_CLASS.':: call precedes its move in the same body.');

                continue;
            }
            $this->assertNotSame('', trim($ruling), "{$site} is ruled unguarded with no reason.");
            $this->assertFalse($consults, "{$site} is ruled unguarded, yet a ".self::GUARD_CLASS.':: consult now precedes its move — rule it self::CONSULTS instead, and say so on card#10068: the coverage this class records has CHANGED.');
        }
    }

    /**
     * The instrument's own control, on a fixture whose answer is known: a mention in prose is not
     * a consult, a consult AFTER the move does not guard it, a consult in another body is not
     * seen, and a nullsafe move is still a move.
     */
    public function test_the_scanner_tells_a_guarded_move_from_an_unguarded_one(): void
    {
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming ProgramCardGuard::refuses( before ->moveCard(. */
            public function guarded(): void
            {
                // ProgramCardGuard::refuses($a, $card, 'arm', 'w', $id, $repo, $o);
                if (ProgramCardGuard::refuses($a, $card, 'arm', 'w', $id, $repo, $o)) {
                    return;
                }
                $client->moveCard($id, $stage);
            }

            public function guardedAfterOnly(): void
            {
                $client?->moveCard($id, $stage);
                ProgramCardGuard::refuses($a, $card, 'arm', 'w', $id, $repo, $o);
            }

            public function twoMovesOneConsultBetween(): void
            {
                $client->moveCard($id, $lane);
                ProgramCardGuard::refuses($a, $card, 'arm', 'w', $id, $repo, $o);
                $client->moveCard($id, $terminal);
            }

            public function consultInAnotherMethod(): void
            {
                $client->moveCard($id, $stage);
            }

            public function moveCard(int $id, int $stage): void
            {
                ProgramCardGuard::isProgramParent($card);
            }
        }
        PHP;

        $this->assertSame(
            [
                'Fixture.php::guarded#1' => true,
                'Fixture.php::guardedAfterOnly#1' => false,
                'Fixture.php::twoMovesOneConsultBetween#1' => false,
                'Fixture.php::twoMovesOneConsultBetween#2' => true,
                'Fixture.php::consultInAnotherMethod#1' => false,
            ],
            SourceScan::sites($source, 'Fixture.php', self::siteAt(...)),
        );
    }

    /** @return array<string, bool> site => whether a guard consult precedes the move in the same body */
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

        // BACKWARD from the move to the start of the enclosing body: a guard consulted AFTER the
        // write guards nothing, which is the direction this predicate inverts from the owner-tag
        // clear's.
        for ($i = $index; $i > $scopeStart; $i--) {
            if (($tokens[$i][1] ?? null) === self::GUARD_CLASS
                && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
                && ($tokens[$i + 3][1] ?? null) === '(') {
                return true;
            }
        }

        return false;
    }
}
