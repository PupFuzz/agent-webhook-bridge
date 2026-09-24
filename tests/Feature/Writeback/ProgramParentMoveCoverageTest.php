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
 * ⭐ THE OPEN GAP IS CLOSED, AND THIS CLASS IS WHAT KEEPS IT CLOSED. It shipped one release
 * earlier as a pure MEASUREMENT: whether an unguarded mover should refuse, warn, or
 * move-and-report was an ACCEPTANCE question reserved to the operator, so every entry below was
 * a record of an open gap and none of them was a disposition that the gap was fine. The operator
 * ruled on 2026-09-24 (card#10068) — the predicate is enforced at EVERY writer of a terminal
 * stage — and the three terminal writers that did not consult it now do. What a string entry
 * means therefore MOVED with that ruling: it no longer says *"nobody has decided"*, it says
 * *"this site reaches no consult IN ITS OWN BODY, and here is why that is right"* — either the
 * move is not a terminal one, or the consult is one frame up (and then {@see UPSTREAM_CONSULTS}
 * checks that claim rather than leaving it as prose), or the card being moved cannot be a
 * program parent at all.
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
 *    of which hold the whole card row while the move site holds only an id — is invisible to it,
 *    and BOTH of the real ones are now there. {@see UPSTREAM_CONSULTS} is what keeps that from
 *    being a sentence a reviewer has to believe: it names the body each such site's consult
 *    lives in, and {@see test_a_site_ruled_guarded_one_frame_up_has_a_consult_in_the_body_it_names}
 *    derives the consult scopes from the same tree and reds when the named body stops carrying
 *    one. ⛔ ITS BOUND: a NEW string ruling whose prose claims an upstream consult, with no
 *    `UPSTREAM_CONSULTS` entry beside it, is checked by nothing — the claim is in prose and only
 *    an entry makes it a claim the tree can falsify.
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
     * EVERY DERIVED `->moveCard(` SITE → `self::CONSULTS`, or what the site moves and why its own
     * body reaches no consult. ⛔ A string is NOT an approval of a gap and is not a shrug: since
     * the 2026-09-24 ruling each one carries the REASON the site is right as it stands, and the
     * two that rest on a consult one frame up name that body in {@see UPSTREAM_CONSULTS}, where
     * it is checked.
     *
     * @var array<string, true|string>
     */
    private const RULINGS = [
        // Coordination-card close, into `coord_card_terminal_stage_id` — a TERMINAL move, and
        // since card#10068 the third consult. The handler holds the full card row here (it
        // consults PinGuard and MappedBoardGuard on it), so the tag costs no extra request.
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::moveOne#1' => self::CONSULTS,
        // The revive leg moves a card OUT of the terminal; the relane leg moves lane to lane.
        // ⛔ RULED UNGUARDED, not overlooked (card#10068): the predicate is about writers of a
        // TERMINAL stage, and a refusal here would strand a parent an operator is recovering —
        // refusing the revive is the one direction that does not self-correct.
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::moveOne#2' => 'NON-TERMINAL: the revive leg moves a card OUT of the terminal, so it makes no completion claim about a parent and is deliberately not guarded',
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::relaneOne#1' => 'NON-TERMINAL: the relane leg moves lane to lane within the live columns, so it makes no completion claim about a parent and is deliberately not guarded',
        // ⛔ RULED NO-REFUSAL ON THE SUBJECT, not on the write (card#10068, operator ruling).
        // This is the one terminal-capable mover that gets no consult, and the reason is what
        // it moves: its whole population is cards CORRELATED TO ONE DEPENDABOT PULL REQUEST
        // (`correlatePr` by that PR's ref, then re-attributed by the card's own `pr_url`), and a
        // single dependency bump names one deliverable — the shape the `program` tag exists to
        // say a card is NOT. ⚠ THE HONEST BOUND, since the population is derived by CORRELATION
        // and not by what this handler minted: an operator who hand-stamped a parent card with a
        // dependabot PR's ref would reach this move. That is accepted rather than unnoticed —
        // it takes a deliberate mis-stamp, and the same hand could as easily drop the tag.
        'Bridge/Handlers/KanbanDependabotCardHandler.php::handle#1' => 'TERMINAL-CAPABLE, NO REFUSAL BY OPERATOR RULING: the dependabot survivor move; its population is cards correlated to ONE dependabot pull request, i.e. one dependency bump, which is not a parent naming several legs',
        // The GitHub-PR event path — the FIRST consult (card#9929).
        'Bridge/Handlers/KanbanMoveCardHandler.php::handle#1' => self::CONSULTS,
        // Shipped → Released. ⚠ THE WRITER THAT ACTUALLY FIRES on this install's releases.
        'Bridge/Handlers/KanbanPromoteReleasedHandler.php::promoteIfReleased#1' => 'TERMINAL: Shipped→Released; this body holds only the card id, and the consult is one frame up in the candidate scan where the ROW is — see UPSTREAM_CONSULTS, which checks that',
        // `bridge:reconcile --fix`, whose plan rows can carry a terminal target.
        'Console/Commands/Bridge/ReconcileCommand.php::finish#1' => 'TERMINAL-CAPABLE: the reconcile fix-up move; this body holds only the plan row, and the consult is one frame up in the plan builder where the card row is — see UPSTREAM_CONSULTS, which checks that',
    ];

    /**
     * The sites whose consult is ONE FRAME UP → the `<file>::<function>` body that must carry it.
     *
     * ⭐ IT EXISTS SO THE PROSE IS NOT THE CHECK. Two of the four consults cannot sit beside their
     * own move, because the move site holds an id or a plan row and the `tags` are only readable
     * where the ROW is; the scanner above is deliberately blind to that, so those two entries
     * would otherwise be a claim a reviewer has to believe. Named here, the claim is falsifiable
     * against the same tree: delete either consult, or move it to another method, and this reds.
     *
     * ⛔ IT DOES NOT CHECK THE DIRECTION. The in-body scanner insists a consult PRECEDES its move;
     * nothing here can, the two being in different bodies. What an upstream entry establishes is
     * that the named body still consults the guard at all — which is the half that silently
     * disappears in a refactor.
     *
     * @var array<string, string>
     */
    private const UPSTREAM_CONSULTS = [
        'Bridge/Handlers/KanbanPromoteReleasedHandler.php::promoteIfReleased#1' => 'Bridge/Handlers/KanbanPromoteReleasedHandler.php::handle',
        'Console/Commands/Bridge/ReconcileCommand.php::finish#1' => 'Console/Commands/Bridge/ReconcileCommand.php::reconcileCard',
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

    public function test_a_site_ruled_guarded_one_frame_up_has_a_consult_in_the_body_it_names(): void
    {
        $scopes = self::consultScopes();

        foreach (self::UPSTREAM_CONSULTS as $site => $scope) {
            $this->assertArrayHasKey($site, self::RULINGS, "{$site} claims an upstream consult but has no ruling at all — RULINGS is the set this class holds, and an entry here is an ANNOTATION on one of its string rulings, never a second way to be covered.");
            $this->assertNotSame(self::CONSULTS, self::RULINGS[$site], "{$site} is ruled to consult IN ITS OWN BODY, so an upstream entry for it is stale: the in-body scanner already checks it. Delete this entry.");
            $this->assertContains(
                $scope,
                $scopes,
                "{$site} is ruled unguarded-here-because-it-is-guarded-in-{$scope}, and {$scope} makes no ".self::GUARD_CLASS.':: consult. Either the consult moved (point this entry at the body that now carries it) or it is GONE, and the parent-card refusal on that writer went with it — card#10068 is what that costs.',
            );
        }
    }

    /**
     * The consult scanner's own control, on a fixture whose answer is known: a static call is a
     * consult wherever in the body it sits, the same name in a docblock or a line comment is not
     * (the tokenizer drops both), the `use` import is not (a qualified name is ONE token, so the
     * short name never appears), and another guard's identically-shaped call is not.
     *
     * ⚠ WHAT THE IMPORT ARM IS AND IS NOT, stated because a control nobody can fail is a
     * decoration. Under the predicate as written it CANNOT fail: PHP 8 tokenizes `use A\B\Guard;`
     * as ONE `T_NAME_QUALIFIED` whose text is the whole path, so the short name never appears
     * there. It is a pin against the SPELLING a future edit reaches for — a suffix or substring
     * match — and it discriminates that one: driven with `str_ends_with(...)` in place of the
     * equality, the import mints a `(file scope)` consult and this control reds (measured
     * 2026-09-24). That matters because every file carrying a consult also carries the import, so
     * a scanner counting it would report a consult in EVERY such file, and the leg above — which
     * asks only whether a named scope is in the set — would pass on a file that had lost its last
     * real consult.
     */
    public function test_the_consult_scanner_sees_a_static_call_and_not_a_name_in_prose_or_an_import(): void
    {
        $source = <<<'PHP'
        <?php
        use App\Bridge\Writeback\ProgramCardGuard;
        class Fixture
        {
            /** A docblock naming ProgramCardGuard::refuses( before any code. */
            public function consults(): void
            {
                // ProgramCardGuard::refuses($a, $card, 'arm', 'w', $id, $repo, $o);
                if (ProgramCardGuard::refuses($a, $card, 'arm', 'w', $id, $repo, $o)) {
                    return;
                }
                $client->moveCard($id, $stage);
                ProgramCardGuard::isProgramParent($card);
            }

            public function consultsAnotherGuard(): void
            {
                PinGuard::refuses($a, $card, 'arm', 'w', $id, $repo, $o);
            }
        }
        PHP;

        $this->assertSame(
            ['Fixture.php::consults#1' => true, 'Fixture.php::consults#2' => true],
            SourceScan::sites($source, 'Fixture.php', self::consultAt(...)),
        );
    }

    /**
     * The instrument's own control, on a fixture whose answer is known: a mention in prose is not
     * a consult, a consult AFTER the move does not guard it, a consult on a SIBLING BRANCH of the
     * same body does not guard it either, a consult in another body is not seen, and a nullsafe
     * move is still a move.
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

            public function consultOnASiblingBranch(): void
            {
                if ($disposition === 'terminal') {
                    if (ProgramCardGuard::refuses($a, $card, 'arm', 'w', $id, $repo, $o)) {
                        return;
                    }
                    $client->moveCard($id, $terminal);

                    return;
                }
                $client->moveCard($id, $elsewhere);
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
                // ⭐ THE ARM THAT MADE THE DEPTH TRACKING NECESSARY, and the shape is the live
                // one: a guarded TERMINAL branch, then an unguarded move below it. The consult
                // precedes both moves in the token stream and dominates only the first.
                'Fixture.php::consultOnASiblingBranch#1' => true,
                'Fixture.php::consultOnASiblingBranch#2' => false,
                'Fixture.php::consultInAnotherMethod#1' => false,
            ],
            SourceScan::sites($source, 'Fixture.php', self::siteAt(...)),
        );
    }

    /**
     * Every `<file>::<function>` in `app/` whose body makes a guard consult — the SAME tree and
     * the SAME walk the move-site population comes from, so the two halves of one boundary
     * cannot be derived from different trees (the reason {@see SourceScan::sitesInApp} exists).
     * Ordinals are dropped: the question an upstream ruling asks is whether the named body
     * consults the guard AT ALL, and a body making two consults is not two answers.
     *
     * @return list<string>
     */
    private static function consultScopes(): array
    {
        $keys = array_keys(SourceScan::sitesInApp(self::consultAt(...)));

        return array_values(array_unique(array_map(
            static fn (string $key): string => (string) preg_replace('/#\d+\z/', '', $key),
            $keys,
        )));
    }

    /**
     * `true` at a `ProgramCardGuard::<name>(` static call, `null` at every other token — the
     * absence {@see SourceScan::sites} reads. Matched by SHORT name for the same reason (and with
     * the same direction of failure) the move-site predicate is: see the class docblock.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function consultAt(array $tokens, int $index, int $scopeStart): ?bool
    {
        return ($tokens[$index][1] ?? null) === self::GUARD_CLASS
            && ($tokens[$index + 1][0] ?? null) === T_DOUBLE_COLON
            && ($tokens[$index + 3][1] ?? null) === '('
            ? true
            : null;
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
        if (SourceScan::methodCallAt($tokens, $index, [self::MOVE_METHOD]) === null) {
            return null;
        }

        // BACKWARD from the move to the start of the enclosing body: a guard consulted AFTER the
        // write guards nothing, which is the direction this predicate inverts from the owner-tag
        // clear's.
        //
        // ⛔ AND IT SKIPS NESTED BLOCKS THAT HAVE ALREADY CLOSED — a consult on a SIBLING BRANCH
        // precedes the move in the token stream and guards nothing. Walking backward, a `}` opens
        // a region the move is not in, so nothing inside it counts until the matching `{`; an
        // enclosing block's own opener leaves the depth at 0, because statements before it DO
        // reach the move. Without this the check green-lit a real unguarded write the moment a
        // sibling branch in the same method gained a consult (card#10068: the coordination
        // handler's `moveOne` guards its TERMINAL leg and deliberately does not guard the revive
        // leg below it). It fails in the safe direction — a consult nested in a block the move is
        // not in reads as absent, which reds a site ruled `CONSULTS` rather than greening one
        // that is not.
        $depth = 0;
        for ($i = $index; $i > $scopeStart; $i--) {
            $text = $tokens[$i][1] ?? null;
            if ($text === '}') {
                $depth++;

                continue;
            }
            if ($text === '{') {
                $depth = max(0, $depth - 1);

                continue;
            }
            if ($depth === 0
                && $text === self::GUARD_CLASS
                && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
                && ($tokens[$i + 3][1] ?? null) === '(') {
                return true;
            }
        }

        return false;
    }
}
