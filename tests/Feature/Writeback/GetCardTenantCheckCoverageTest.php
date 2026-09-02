<?php

namespace Tests\Feature\Writeback;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * THE STRUCTURAL ENFORCER FOR THE PRE-READ TENANT CHECK (card#8440, DL-330).
 *
 * `KanbanClient::getCard()` is the only per-id read the client exposes THAT NAMES NO BOARD
 * (`cardRowsOnBoard()` is per-id too, and is the board-scoped lookup the guard uses):
 * `GET /tasks/{id}.json` against a kanban id space that is GLOBAL across every board on the
 * instance. So whether a call site is safe is a property of WHERE ITS ID CAME FROM,
 * and there are exactly two ways to establish that:
 *
 *   1. **The id was resolved by a board-scoped read** — `cardsByTag`, `cardRowsByTag`,
 *      `correlatePr`, `correlateIssue`, or the tool's own `createCard` — so it was never
 *      author-supplied and there is nothing to establish.
 *   2. **`MappedBoardGuard::refusesCardIdOutsideMappedBoard()` ran first** — the DL-323 /
 *      DL-330 check, which resolves the id through a board-scoped lookup BEFORE any unscoped
 *      read, so an id outside the mapping is never resolved at all.
 *
 * ⭐ WHY THIS CLASS EXISTS RATHER THAN A THIRD DOCBLOCK. Until it landed, that rule was a
 * DECLARATION WITH NO CHECK — carried by prose in `KanbanClient`, `MappedBoardGuard` and
 * `KanbanBlockReasonHandler`, and landed BY HAND at one call site on card#8375 and again at
 * one call site on card#8415. Two instances of one rule with no guard is how the N+1th copy
 * mints the bug again (canon #7), and the bug here is a CROSS-TENANT READ of a foreign card
 * id before the 403 wall (card#7846's shape). The post-read compare already has its
 * structural enforcer — {@see WritebackRefusalSignalCoverageTest::test_every_board_id_read_in_app_is_dispositioned}
 * reds on a read of a card's `board_id` outside the primitive that is not dispositioned. This is the
 * pre-read twin: it reds on a call site that reads a card by id with NEITHER mechanism
 * declared. DL-330's own bound named it as the thing that would hold the enumeration, so
 * that a new unscoped caller reds instead of waiting for the next auditor to repeat it.
 *
 * POPULATION, re-derived every run rather than counted once: every `->getCard(` call in
 * every `*.php` under `app/`, found on PHP's own tokenizer so that a docblock `{@see
 * KanbanClient::getCard()}`, a line comment and the declaration itself are excluded by
 * construction rather than by a regex that the next spelling walks past. Each site is keyed
 * `<path under app/>::<enclosing function>#<ordinal within that function>` — never by LINE
 * NUMBER, which would red on an unrelated docblock edit whose only remediation ("re-derive
 * the offsets") is the same action that absorbs a real new call site without a second
 * thought. The ordinal is what stops a SECOND call in an already-declared method inheriting
 * the first one's declaration.
 *
 * ⭐ THE WALK ITSELF IS NOT THIS CLASS'S — it is {@see SourceScan::sitesInApp}, shared with
 * the post-read enforcer (card#8530). This class owns only the PREDICATE ({@see readSiteAt})
 * and the two allow-lists; the tree it is asked about is a property of the repo, not of
 * either class, and while the two ends of one boundary derived two different populations a
 * site could be — and was — outside both.
 *
 * ⭐ THE TWO ARMS ARE NOT EQUALLY CHECKABLE, AND THE DIFFERENCE IS STATED RATHER THAN BLURRED:
 *  - The GUARDED arm is VERIFIED FOR CONTROL FLOW. {@see ID_GUARDED_BY_PRE_READ_CHECK} is
 *    not taken on its word — the scanner requires an HONOURED
 *    `MappedBoardGuard::refusesCardIdOutsideMappedBoard()` earlier in the same function body,
 *    where *honoured* means inside an `if` whose body exits at its TOP LEVEL
 *    (`return`/`throw`/`continue`/`break` — see {@see EXITS}). A guard whose result is
 *    dropped on the floor is a guard that does nothing, and it would otherwise read exactly
 *    like a guard that works. ID CORRESPONDENCE IS NOT CHECKED — see the bounds.
 *  - The BOARD-SCOPED arm is DECLARED. {@see ID_RESOLVED_BOARD_SCOPED} carries, per entry,
 *    the read that produced the id; establishing that by machine is a dataflow question this
 *    class does not answer. What IS machine-checked is the PARTITION: a site declared
 *    board-scoped must NOT carry an honoured pre-read guard, so the two lists cannot quietly
 *    both claim a site, and adding the guard to a board-scoped site reds until it is
 *    re-declared on the arm that is verified.
 *
 * STATED BOUNDS — a green run says "every by-id read in `app/` declares a mechanism, and
 * every site claiming the guard has an honoured one", never "no cross-tenant read is
 * possible":
 *  - **`tests/` is not in the population.** A test double is not a tenant boundary.
 *  - **Only the shipped `if (…) { <exit>; }` shape counts as honoured.** A negated call, an
 *    assignment, an `if` body that merely logs, an exit nested one level deeper, or a guard
 *    reached through a local wrapper all red as UNGUARDED. That is deliberate on a security
 *    check: a new spelling is a review event, not a thing the scanner should be widened for
 *    by whoever hits the red.
 *  - **Control flow BETWEEN the guard and the read is not modelled.** The guard must precede
 *    the read textually in the same function body; a read reachable AROUND an honoured guard
 *    (a second path into the same body, a `goto`) is not caught here.
 *  - **Id correspondence between the guard and the read is NOT checked.** The guarded arm
 *    verifies that an honoured guard precedes the read, not that the guard was asked about
 *    the SAME id the read then resolves: a guard on `$other` followed by `getCard($attacker)`
 *    is green here. Both shipped arms guard and read one `$cardId`; the reviewer of a new
 *    guarded entry owns reading that the two ids are the same variable.
 *  - **A call outside any named function is keyed `<file>::(file scope)#n`, never skipped.**
 *    A top-level closure or a bare statement in a file has no enclosing method to attribute
 *    to, and an unattributable read must surface as an UNDECLARED site rather than fall out
 *    of the population — the one shape that failed open in the first draft, found by a
 *    review control.
 *  - **The population is `app/` only, and it assumes `getCard` names the client's method.**
 *    Both are pinned below rather than assumed: a second `getCard` declaration anywhere in
 *    `app/` reds, because every `->getCard(` would then be ambiguous.
 */
class GetCardTenantCheckCoverageTest extends TestCase
{
    /**
     * The pre-read tenant check. Spelled once, here, and used by the scanner, the
     * assertions and the messages alike.
     */
    private const PRE_READ_CHECK = 'refusesCardIdOutsideMappedBoard';

    /** The primitive that owns it — a call through anything else is not this check. */
    private const GUARD_CLASS = 'MappedBoardGuard';

    /** The by-id read whose call sites are the population. */
    private const READ_METHOD = 'getCard';

    /**
     * What makes a refusal HONOURED: the token kinds that leave the read unreached.
     *
     * `continue` and `break` are members because two of the six call sites sit inside a
     * `foreach` over correlated ids, where `continue` — not `return` — is the correct way
     * to skip one refused card. Omitting them would red a correctly-guarded loop arm and
     * push its author onto the board-scoped list, which is the arm this class only TAKES
     * ON ITS WORD: a false red here does not merely annoy, it launders an unverified
     * declaration. (Found by staging exactly that arm as a control.)
     *
     * @var list<int>
     */
    private const EXITS = [T_RETURN, T_THROW, T_CONTINUE, T_BREAK];

    /**
     * Call sites whose card id was produced by a BOARD-SCOPED read, so it was never
     * author-supplied and there is nothing to establish before the read.
     *
     * The value names the read that produced the id — the claim a reviewer checks. It is
     * not machine-verified (see the class docblock); what IS verified is that none of these
     * carries an honoured pre-read guard, because a site that has one belongs on the other
     * list where the declaration is checked.
     *
     * @var array<string, string>
     */
    private const ID_RESOLVED_BOARD_SCOPED = [
        // The read-back for the response's placement fields. Three producers, all scoped to
        // the agent's configured board: the idempotency HIT arm passes the lowest id
        // `cardsByTag($cfg->boardId, $idemTag)` answered, the create arm passes the id its
        // own `createCard($cfg->boardId, …)` returned, and the DL-198 post-create collapse
        // reassigns that to `min()` of the same board-scoped read. None of the three can
        // carry an id out of author-controlled text (DL-330 corrected an earlier claim that
        // named only the create).
        'Bridge/Tools/BoardCreateCardTool.php::placement#1' => 'cardsByTag($cfg->boardId, …) on the idempotency-hit arm; createCard($cfg->boardId, …) or min(cardsByTag(…)) on the create arm',
        // The per-card disposition of a coord card move. `$id` comes from the union of
        // `cardsByTag($mapping->boardId, "id:{$sid}")` and
        // `correlateIssue($mapping->boardId, …)`, and the row is re-checked through
        // `MappedBoardGuard::refuses()` immediately after the read (DL-009).
        'Bridge/Handlers/KanbanCoordCardMoveHandler.php::moveOne#1' => 'cardsByTag($mapping->boardId, "id:{$sid}") ∪ correlateIssue($mapping->boardId, …)',
        // The by-ref create RACE only: one read per correlated id, where the ids came from
        // `correlateIssue($mapping->boardId, …)` — board in the URL path in `ref` mode, in
        // `q=board_id=` in `scan` mode — and the rows are put through `onMappedBoard()`.
        'Bridge/Handlers/KanbanCoordCardHandler.php::handle#1' => 'correlateIssue($mapping->boardId, $issueNumber, $repo)',
        // The dependabot card set. Ids come from `correlatePr($mapping->boardId, …)` and
        // every row is put through `MappedBoardGuard::refuses()` before the repo gate
        // (DL-298) — the board gate first, so a foreign-board card is never a quiet drop.
        'Bridge/Handlers/KanbanDependabotCardHandler.php::cardsForRepo#1' => 'correlatePr($mapping->boardId, $prNumber, $sourceRepo)',
    ];

    /**
     * Call sites whose card id comes from AUTHOR-CONTROLLED text — the `card#NNNN` / DL
     * token grammar — and which therefore establish the id on the mapped board BEFORE
     * reading it.
     *
     * Every entry here is VERIFIED FOR CONTROL FLOW, not merely declared: the scanner
     * requires an honoured `MappedBoardGuard::refusesCardIdOutsideMappedBoard()` earlier in
     * the same function body. It does NOT check that the guard and the read name the same id
     * (class docblock, bounds) — that is the reviewer's to read on each entry. The value records which card landed the arm, so a deletion is legible as a
     * reversal of a specific decision.
     *
     * @var array<string, string>
     */
    private const ID_GUARDED_BY_PRE_READ_CHECK = [
        'Bridge/Handlers/KanbanMoveCardHandler.php::handle#1' => 'card#8375 / DL-323 — the `payload.card_id` token arm',
        'Bridge/Handlers/KanbanBlockReasonHandler.php::handle#1' => 'card#8415 / DL-330 — the draft-overlay arm, the same token grammar',
    ];

    public function test_every_getcard_call_site_in_app_declares_its_tenant_check_mechanism(): void
    {
        $declared = array_merge(
            array_keys(self::ID_RESOLVED_BOARD_SCOPED),
            array_keys(self::ID_GUARDED_BY_PRE_READ_CHECK),
        );
        sort($declared);

        $derived = array_keys(self::callSitesInApp());
        sort($derived);

        $this->assertSame(
            $declared,
            $derived,
            'the set of `'.self::READ_METHOD.'()` call sites in app/ is not the set this class declares. '
            .'A call site here reads ONE card by id and NAMES NO BOARD, against a kanban id space that is '
            .'GLOBAL across every board on the instance — so an UNDECLARED site is an unanswered question, '
            .'not a style lapse. Answer it on one of the two lists in this class: '
            .'`ID_RESOLVED_BOARD_SCOPED` if the id came out of a board-scoped read (name that read in the '
            .'value), or `ID_GUARDED_BY_PRE_READ_CHECK` if the id comes from author-controlled text, in '
            .'which case call `'.self::GUARD_CLASS.'::'.self::PRE_READ_CHECK.'()` first and the entry is '
            .'then VERIFIED FOR CONTROL FLOW rather than taken (card#8440, DL-323/DL-330). An entry with no call site is the '
            .'other direction of the same red: a stale exemption outlives the code it exempted — delete it.',
        );
    }

    public function test_every_call_site_declaring_the_pre_read_check_actually_has_an_honoured_one(): void
    {
        $derived = self::callSitesInApp();

        foreach (self::ID_GUARDED_BY_PRE_READ_CHECK as $site => $provenance) {
            $this->assertArrayHasKey($site, $derived, "the declared call site `{$site}` no longer exists — the assertion below would be vacuous.");
            $this->assertTrue(
                $derived[$site],
                "`{$site}` is declared as GUARDED ({$provenance}) but no HONOURED "
                .self::GUARD_CLASS.'::'.self::PRE_READ_CHECK.'() precedes it in the same function body. '
                .'Honoured means `if ('.self::GUARD_CLASS.'::'.self::PRE_READ_CHECK.'(…)) { <exit>; }`, '
                .'where <exit> is return/throw/continue/break at the top of that body — a call whose '
                .'result is DROPPED reads exactly like a guard that works and '
                .'establishes nothing, which is the failure this leg exists to catch. If the guard was '
                .'deliberately removed, this id is author-supplied and the read is now an unscoped '
                .'cross-tenant read: that is a gated security change (DL-323), not a test to update.',
            );
        }

        // The other direction, and the reason the two lists are a PARTITION rather than two
        // piles: a site declared board-scoped that HAS grown a guard is not wrong, it is
        // MIS-DECLARED — and mis-declared onto the arm that is merely taken on its word,
        // which is exactly where a real check would stop being read.
        foreach (self::ID_RESOLVED_BOARD_SCOPED as $site => $producer) {
            $this->assertArrayHasKey($site, $derived, "the declared call site `{$site}` no longer exists — the assertion below would be vacuous.");
            $this->assertFalse(
                $derived[$site],
                "`{$site}` is declared as board-scoped by its id resolution ({$producer}), but it now also "
                .'carries an honoured '.self::GUARD_CLASS.'::'.self::PRE_READ_CHECK.'(). That is a stronger '
                .'position, not a weaker one — move the entry to `ID_GUARDED_BY_PRE_READ_CHECK`, where the '
                .'control-flow claim is machine-verified instead of taken on its word.',
            );
        }
    }

    /**
     * The premise of the whole population: the by-id read is declared in exactly one class,
     * so every call site the scanner finds is a call to THAT read.
     *
     * A second class declaring the same method name would put unrelated call sites into the
     * population (noise a future reader would silence by widening the allowlist) and, worse,
     * would make an allowlisted key ambiguous about which read it declares.
     */
    public function test_the_by_id_read_is_declared_in_exactly_one_place(): void
    {
        $declarations = [];
        foreach (SourceScan::appFiles() as $path) {
            $tokens = SourceScan::significantTokens((string) file_get_contents($path));
            foreach ($tokens as $i => $token) {
                if ($token[0] !== T_FUNCTION) {
                    continue;
                }
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next[0] === T_STRING && $next[1] === self::READ_METHOD) {
                    $declarations[] = SourceScan::relativeToApp($path);
                }
            }
        }

        $this->assertSame(
            ['Bridge/Writeback/KanbanClient.php'],
            $declarations,
            'the by-id read `'.self::READ_METHOD.'()` is declared somewhere other than (or as well as) '
            .'KanbanClient. This class scans for `->'.self::READ_METHOD.'(` call sites and attributes every '
            .'one of them to the kanban client — a second declaration makes that attribution false in both '
            .'directions, and an empty result means the read this class exists to police has been renamed '
            .'or removed while the scanner reported the repo clean.',
        );
    }

    /**
     * The guard primitive still exists, and exists THERE.
     *
     * Without this, the GUARDED arm would be asserting that two call sites precede
     * themselves with a method nothing in the tree declares — a green over a deleted
     * security check is indistinguishable from a green over a working one.
     */
    public function test_the_pre_read_check_still_lives_on_the_guard_primitive(): void
    {
        $primitive = (string) file_get_contents(base_path('app/Bridge/Writeback/'.self::GUARD_CLASS.'.php'));

        $this->assertStringContainsString(
            'public static function '.self::PRE_READ_CHECK.'(',
            $primitive,
            self::GUARD_CLASS.' no longer declares '.self::PRE_READ_CHECK.'(). If it MOVED, point this '
            .'class and the handlers at its new home; if it was REMOVED, the two guarded arms of this '
            .'class are asserting the presence of a check that does not exist and the tenant boundary '
            .'is back to being whatever the writeback token\'s scope happens to be (DL-323).',
        );
    }

    public function test_the_scanner_finds_a_real_call_and_skips_a_comment_a_docblock_and_the_declaration(): void
    {
        // The scanner's own control. Its population is EXPECTED to be non-empty, but a
        // scanner that had stopped matching would still satisfy every per-entry assertion
        // above by reporting a repo with no by-id reads at all — and one that matched prose
        // would report docblocks as defects. Both directions, on a fixture whose answer is
        // known: the two `getCard` mentions in comments, the string literal and the
        // declaration itself are out, the three real calls are in, and the SECOND call in a
        // method gets its own ordinal rather than inheriting the first one's declaration.
        // The top-level closure after the class has no enclosing method and must still land
        // in the population, keyed to the file scope — the shape that failed open once.
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** @param array $card as returned by {@see KanbanClient::getCard()} */
            public function getCard(int $id): array
            {
                return [];
            }

            public function reads(): void
            {
                // A line comment naming $client->getCard($id).
                $a = $client->getCard($first);
                $b = $client->getCard($second);
                $c = 'getCard(';
            }

            public function readsOnce(): void
            {
                $d = $client?->getCard($id);
            }
        }

        $loose = function ($client, $id) {
            return $client->getCard($id);
        };
        PHP;

        $this->assertSame(
            [
                'Fixture.php::reads#1' => false,
                'Fixture.php::reads#2' => false,
                'Fixture.php::readsOnce#1' => false,
                'Fixture.php::(file scope)#1' => false,
            ],
            self::callSites($source, 'Fixture.php'),
        );
    }

    public function test_the_shared_walk_never_offers_a_structural_token_to_a_predicate(): void
    {
        // {@see SourceScan::sites} STATES this exclusion; without this arm it is a
        // declaration with no check, on the primitive minted to retire that shape. The scope
        // tracking consumes four tokens before the predicate is asked — `{`, `}`, a `;`
        // closing a bodiless declaration, and the `function` keyword — so a predicate whose
        // subject is a DECLARATION comes back EMPTY. That is a census failing OPEN, the one
        // direction these instruments must not fail in, and neither shipped predicate would
        // notice: both answer `null` for all four already.
        //
        // ⛔ The fixture is chosen so that every token the structural predicate matches is one
        // the walk consumes: its ONE `;` is the bodiless declaration's, which is the only `;`
        // the contract covers. Adding a statement to `named()` would put an unconsumed `;` in
        // the source and red this arm for a reason that is not a defect.
        $source = <<<'PHP'
        <?php
        abstract class Fixture
        {
            abstract public function bodiless(): void;

            public function named(): void
            {
            }
        }
        PHP;

        $structural = static fn (array $tokens, int $index): ?string => in_array($tokens[$index][1], ['{', '}', ';'], true) || $tokens[$index][0] === T_FUNCTION
            ? $tokens[$index][1]
            : null;

        // The PRESENCE witness, first and on the SAME fixture: an absence assertion whose
        // walk never ran is satisfied by a walk that does nothing at all. This predicate
        // matches the return type — a token sitting BETWEEN the consumed ones — so a
        // non-empty result here is proof the fixture was tokenized and both declarations
        // were reached.
        $returnType = static fn (array $tokens, int $index): ?string => $tokens[$index][0] === T_STRING && $tokens[$index][1] === 'void'
            ? $tokens[$index][1]
            : null;

        $this->assertSame(
            [
                'Fixture.php::bodiless#1' => 'void',
                'Fixture.php::named#1' => 'void',
            ],
            SourceScan::sites($source, 'Fixture.php', $returnType),
            'the control fixture is no longer being walked, so the absence assertion below proves nothing',
        );

        $this->assertSame(
            [],
            SourceScan::sites($source, 'Fixture.php', $structural),
            'a STRUCTURAL token reached the predicate. SourceScan::sites\'s docblock says the four '
            .'scope-tracking tokens are consumed before $siteAt is called; if that changed, the docblock '
            .'is now false and a declaration-subject census that reads as clean may simply never have '
            .'been asked. Either restore the dispatch or move the contract.',
        );
    }

    public function test_the_scanner_tells_an_honoured_guard_from_one_whose_refusal_is_dropped(): void
    {
        // The leg that makes the GUARDED arm a check rather than a second declaration. A
        // guard call is not evidence: what makes it a guard is that the refusal EXITS. Every
        // negative shape below would read as "the guard is there" to any scanner that greps
        // for the method name, and every one of them leaves the unscoped read reachable on a
        // foreign id.
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            public function honouredWithReturn(): void
            {
                if (MappedBoardGuard::refusesCardIdOutsideMappedBoard($a, $b, $c, 'arm', $id, $r, $o)) {
                    return;
                }
                $card = $client->getCard($id);
            }

            public function honouredWithThrow(): void
            {
                if (MappedBoardGuard::refusesCardIdOutsideMappedBoard($a, $b, $c, 'arm', $id, $r, $o)) {
                    throw new RuntimeException('refused');
                }
                $card = $client->getCard($id);
            }

            public function refusalDropped(): void
            {
                MappedBoardGuard::refusesCardIdOutsideMappedBoard($a, $b, $c, 'arm', $id, $r, $o);
                $card = $client->getCard($id);
            }

            public function refusalOnlyLogged(): void
            {
                if (MappedBoardGuard::refusesCardIdOutsideMappedBoard($a, $b, $c, 'arm', $id, $r, $o)) {
                    Log::warning('refused');
                }
                $card = $client->getCard($id);
            }

            public function guardNegated(): void
            {
                if (! MappedBoardGuard::refusesCardIdOutsideMappedBoard($a, $b, $c, 'arm', $id, $r, $o)) {
                    return;
                }
                $card = $client->getCard($id);
            }

            public function guardAfterTheRead(): void
            {
                $card = $client->getCard($id);
                if (MappedBoardGuard::refusesCardIdOutsideMappedBoard($a, $b, $c, 'arm', $id, $r, $o)) {
                    return;
                }
            }

            public function honouredWithContinueInALoop(): void
            {
                foreach ($ids as $id) {
                    if (MappedBoardGuard::refusesCardIdOutsideMappedBoard($a, $b, $c, 'arm', $id, $r, $o)) {
                        continue;
                    }
                    $card = $client->getCard($id);
                }
            }

            public function exitNestedOneLevelDeeper(): void
            {
                if (MappedBoardGuard::refusesCardIdOutsideMappedBoard($a, $b, $c, 'arm', $id, $r, $o)) {
                    if ($strict) {
                        return;
                    }
                }
                $card = $client->getCard($id);
            }

            public function guardInAnotherMethod(): void
            {
                $card = $client->getCard($id);
            }
        }
        PHP;

        $this->assertSame(
            [
                'Fixture.php::honouredWithReturn#1' => true,
                'Fixture.php::honouredWithThrow#1' => true,
                'Fixture.php::refusalDropped#1' => false,
                'Fixture.php::refusalOnlyLogged#1' => false,
                'Fixture.php::guardNegated#1' => false,
                'Fixture.php::guardAfterTheRead#1' => false,
                'Fixture.php::honouredWithContinueInALoop#1' => true,
                'Fixture.php::exitNestedOneLevelDeeper#1' => false,
                'Fixture.php::guardInAnotherMethod#1' => false,
            ],
            self::callSites($source, 'Fixture.php'),
        );
    }

    /**
     * Every `->getCard(` call site under `app/`, keyed as
     * `<path under app/>::<enclosing function>#<ordinal>` → whether an HONOURED pre-read
     * guard precedes it in the same function body.
     *
     * @return array<string, bool>
     */
    private static function callSitesInApp(): array
    {
        return SourceScan::sitesInApp(self::readSiteAt(...));
    }

    /**
     * The same predicate over one source string, for the fixture controls.
     *
     * @return array<string, bool>
     */
    private static function callSites(string $source, string $file): array
    {
        return SourceScan::sites($source, $file, self::readSiteAt(...));
    }

    /**
     * The PREDICATE this class owns: is the token at $index a call to the by-id read, and
     * if so does an HONOURED pre-read guard precede it inside the same body?
     *
     * A regex over the raw text would find a call only in the spelling it was written to
     * match, and would find every mention of that spelling inside a comment, a docblock or
     * a string — both errors wearing the same green. Asked of TOKENS, the question is what
     * a call IS: the method name reached through `->` or `?->`, with an argument list.
     *
     * ⛔ `false` IS AN ANSWER, not an absence: an unguarded call site is in the population
     * and keyed `=> false`. Only `null` means "this token is not a call site" — the
     * contract {@see SourceScan::sites} states.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @param  int  $scopeStart  the index at which the enclosing body began
     */
    private static function readSiteAt(array $tokens, int $index, int $scopeStart): ?bool
    {
        if ($tokens[$index][0] !== T_STRING || $tokens[$index][1] !== self::READ_METHOD) {
            return null;
        }

        $arrow = $tokens[$index - 1][0] ?? null;
        $isCall = ($arrow === T_OBJECT_OPERATOR || $arrow === T_NULLSAFE_OBJECT_OPERATOR)
            && ($tokens[$index + 1][1] ?? null) === '(';

        if (! $isCall) {
            return null;
        }

        return self::honouredGuardPrecedes($tokens, $scopeStart, $index);
    }

    /**
     * Whether an honoured pre-read guard stands between $from (where the enclosing body
     * began) and $before (the read).
     *
     * Looked up rather than accumulated in a flag: a flag has to be RESET on every scope
     * change, and the reset is what a shared walk would have to know about this predicate.
     * The answer is identical — the walk hands over the body's start index.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function honouredGuardPrecedes(array $tokens, int $from, int $before): bool
    {
        for ($i = $from; $i < $before; $i++) {
            if ($tokens[$i][0] === T_STRING
                && $tokens[$i][1] === self::PRE_READ_CHECK
                && self::isHonouredGuard($tokens, $i)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the pre-read check at $index is called through the guard primitive AND its
     * refusal is HONOURED — `if (…) { return; }` / `if (…) { throw …; }`, or the braceless
     * form of either.
     *
     * ⛔ The recognised shape is deliberately narrow (see the class docblock's bounds). A
     * negated call, an assignment, or an `if` body that only logs answers FALSE, which reds
     * as an undeclared or mis-declared site rather than passing quietly — on a security
     * check, an unrecognised spelling must be a review event, not a scanner to widen.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function isHonouredGuard(array $tokens, int $index): bool
    {
        // `if` `(` `MappedBoardGuard` `::` `<check>` — the negation `!` sits between the two
        // parens and breaks this match, which is the point: `if (! refuses(…)) { return; }`
        // returns on the ACCEPT side and reads the card on the refuse side.
        $shape = [
            $tokens[$index - 1][0] ?? null,
            $tokens[$index - 2][1] ?? null,
            $tokens[$index - 3][1] ?? null,
            $tokens[$index - 4][0] ?? null,
        ];
        if ($shape !== [T_DOUBLE_COLON, self::GUARD_CLASS, '(', T_IF]) {
            return false;
        }

        // Past the check's own argument list, then past the `if` condition's closing paren.
        $after = self::afterBalancedParens($tokens, $index + 1);
        if (($tokens[$after][1] ?? null) !== ')') {
            return false;
        }
        $body = $after + 1;

        if (($tokens[$body][1] ?? null) !== '{') {
            return in_array($tokens[$body][0] ?? null, self::EXITS, true);
        }

        // Only an exit at the TOP of the `if` body counts. One nested a level deeper is
        // conditional on something this scanner does not read, so the read below it is
        // reachable on a refused id — the very state the guard exists to prevent.
        for ($i = $body + 1, $depth = 1; $i < count($tokens) && $depth > 0; $i++) {
            if ($tokens[$i][1] === '{') {
                $depth++;

                continue;
            }
            if ($tokens[$i][1] === '}') {
                $depth--;

                continue;
            }
            if ($depth === 1 && in_array($tokens[$i][0], self::EXITS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The index just past the parenthesised group that STARTS at $open.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function afterBalancedParens(array $tokens, int $open): int
    {
        for ($i = $open, $depth = 0; $i < count($tokens); $i++) {
            $depth += match ($tokens[$i][1]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $i + 1;
            }
        }

        return count($tokens);
    }
}
