<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\KanbanClient;
use PHPUnit\Framework\Assert;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * THE STRUCTURAL ENFORCER FOR THE CARD-WRITER ROSTER (card#10063).
 *
 * `docs/writeback.md` § *A least-privilege writeback token* is the ONE home of the list of
 * code paths that write cards — the `task.move` row is the mover roster and the rows beside
 * it carry the create and the archive. Two surfaces POINT at it rather than restating it:
 * the `program`-tag row of `docs/kanban-integration-contract.md` § 3 (the cross-repo surface)
 * and the parent-card bullet of this repo's own `docs/writeback.md` § *Pinned cards*. This
 * class is what makes that pointer worth following.
 *
 * ⭐ WHY IT EXISTS, and the incident is the argument. Four surfaces in this repo restated the
 * roster and NOTHING DERIVED IT: inside ONE pull request the cross-repo row said **two**
 * movers, the decision-log entry beside it said **five**, and `docs/writeback.md`'s row named
 * five. They disagreed with each other and no check noticed. The ruling was to point rather
 * than re-count, which leaves exactly one copy — and one copy that nothing derives is still a
 * snapshot: it is true until the next writer lands and silently false after it. Re-syncing
 * the count was refused for the same reason. So the population is DERIVED here, every run,
 * and the row is held against whatever the derivation returns.
 *
 * POPULATION: every call to one of the {@see ROSTERS} primitives in every `*.php`
 * under `app/`, asked of PHP's own tokenizer through {@see SourceScan::sitesInApp} so that a
 * docblock mention, a `{@see}` and the declaration itself are excluded by CONSTRUCTION rather
 * than by a regex the next spelling walks past. Sites are keyed `<path under app/>::<function>#<n>`
 * and reduced to the CLASS, because the roster names classes and not call sites.
 *
 * ⭐ AND THE PRIMITIVES ARE THEMSELVES HELD, not asserted. That they are the whole
 * of the card LIFECYCLE surface {@see KanbanClient} exposes is a CLAIM, and an unchecked claim
 * about a population is the defect this class exists to remove — one level up. It was measured
 * live: a fourth lifecycle method on the client, with a live caller in `app/`, reddened nothing
 * and every row stayed true. {@see CLIENT_SURFACE} closes that. ⛔ **`docs/writeback.md`
 * § *A least-privilege writeback token* owns the READER-facing statement of what the pair
 * reaches; the bounds below are that same scope addressed to the next author of this class,
 * and may come to say less than it but never more.**
 *
 * ⛔ THE TWO ROWS THIS CLASS DELIBERATELY DOES NOT COVER, disposed of rather than left to be
 * noticed as a gap:
 *  - **`task.update`** — its population is the `->patchCard(` census, which is NOT a
 *    one-to-one map onto that row: `KanbanClient` expresses the stage-only MOVE and the
 *    correlation STAMP through the same primitive, so the client would land in a row about
 *    field writes. That census has an owner already — {@see PinnedFieldWriteCoverageTest}
 *    derives it every run, and the `PATCH /api/v3/tasks/{id}.json` row of
 *    `docs/kanban-integration-contract.md` owns the population.
 *  - **`comment.create`** — a note is written ON a card, not a write OF one; nothing in the
 *    roster class's harm (a maintainer building against a short list of what can MOVE a card)
 *    reaches it.
 *
 * ⛔ STATED BOUNDS — WHAT A GREEN RUN SAYS. It says the set of classes calling each lifecycle
 * primitive is exactly the set of classes that row names, and that each of the clauses
 * {@see publishedClauses} lists names exactly the side of {@see ROSTERS} it speaks for — the
 * primitive clauses' names against its KEYS, the roster doc's row enumeration against its VALUES.
 * It does NOT say the prose beside each name describes that class correctly, and it does not
 * reach a mover in another repo.
 *  - **A FURTHER clause publishing this roster, in this or any other doc, is outside this
 *    check** — the population held is the clauses {@see publishedClauses} enumerates, and
 *    nothing scans prose for a roster-publishing sentence, so a new one is read by a reviewer
 *    or by nothing.
 *  - **The house spelling is load-bearing and is a convention, not a property this can
 *    enforce:** every writer in one of these rows is named by its CLASS in backticks.
 *    A class-shaped backticked token added to one of these rows for some other purpose reds
 *    this class — loudly, with an obvious remedy (put the aside outside the row), which is the
 *    direction a census instrument must fail in.
 *  - **A row naming the right classes with the wrong prose is green here.** A reviewer reads
 *    the prose; this reads the roster.
 *  - **`tests/` is not in the population.** A test double is not a writeback.
 *  - **The PRIMITIVE surface reaches {@see KanbanClient}; the call-site census does not stop
 *    there.** The predicate matches the method NAME on any receiver, so a `->moveCard(` call on
 *    some other client class IS in the census and reds the row that does not name its class. What
 *    is outside: a card written through a lifecycle method {@see KanbanClient} does not declare,
 *    and — the complementary gap — a call to one of these names not SPELLED `->name(` at all, a
 *    dynamic dispatch or a `call_user_func`, which no census here sees.
 *  - **A `task.move` written as `->patchCard($id, ['workflow_stage_id' => $s])` from outside
 *    the client is outside this census** — the predicate matches the method NAME, and kanban
 *    authorizes a PATCH whose SOLE key is `workflow_stage_id` as `task.move`. Not live at the
 *    time of writing (`command grep -rn "workflow_stage_id" app/` → the only PATCH-writing
 *    sites are inside {@see KanbanClient}); {@see PinnedFieldWriteCoverageTest} derives the
 *    `->patchCard(` population that would carry it.
 */
class CardWriteRosterCoverageTest extends TestCase
{
    /**
     * The card-lifecycle write primitives, each mapped to the permission whose row rosters it.
     *
     * @var array<string, string>
     */
    private const ROSTERS = [
        'moveCard' => 'task.move',
        'createCard' => 'task.create',
        'archiveCard' => 'task.archive',
    ];

    private const ROSTER_DOC = 'docs/writeback.md';

    private const CONTRACT_DOC = 'docs/kanban-integration-contract.md';

    /**
     * The dispositions in {@see CLIENT_SURFACE} that are NOT a card-lifecycle write — `read`,
     * and the two rows this class deliberately does not cover, each disposed of in the class
     * docblock above.
     *
     * ⛔ IT IS THE ANCHOR, and it is written down rather than derived from {@see ROSTERS} for
     * one reason: scoping "which client methods must be rostered" by ROSTERS' OWN values makes
     * a deleted roster row take its scope with it, so the check goes green on exactly the
     * mutation it exists to catch. The complement is the end a mutation of the roster does not
     * move. A permission appearing in CLIENT_SURFACE that is in neither list reds.
     *
     * @var list<string>
     */
    private const UNROSTERED = ['read', 'task.update', 'comment.create'];

    /**
     * Every public method {@see KanbanClient} declares, against what this roster does with it.
     *
     * ⛔ THIS IS WHAT KEEPS {@see ROSTERS} FROM BEING THE VERY DEFECT THIS CLASS EXISTS TO
     * REMOVE. Deriving the CALL SITES of the rostered primitives is only half a derivation:
     * they are themselves a hand-written claim about which primitives write a card, so a
     * FOURTH card-lifecycle method added to the client — with live callers in `app/` — was
     * invisible to the census, to the roster rows and to this class, and everything stayed
     * green. A roster that cannot go short at the call site, but can go short at the
     * PRIMITIVE, is a snapshot with extra steps.
     *
     * @var array<string, string>
     */
    private const CLIENT_SURFACE = [
        'addComment' => 'comment.create',
        'archiveCard' => 'task.archive',
        'boardCustomFieldKeys' => 'read',
        'boardCustomFields' => 'read',
        'boardStageIdsByName' => 'read',
        'boardStageNames' => 'read',
        'boardStageOrder' => 'read',
        'boardStructure' => 'read',
        'boardSwimlaneIds' => 'read',
        'byRefAvailable' => 'read',
        'cardRowsByTag' => 'read',
        'cardRowsOnBoard' => 'read',
        'cardsByTag' => 'read',
        'correlateDl' => 'read',
        'correlateIssue' => 'read',
        'correlatePr' => 'read',
        'createCard' => 'task.create',
        'findCardsByRef' => 'read',
        'getCard' => 'read',
        'moveCard' => 'task.move',
        'patchCard' => 'task.update',
        'readBoardCards' => 'read',
        'searchDisclosesFreeText' => 'read',
        'setBlockReason' => 'task.update',
        'stampCorrelationRefs' => 'task.update',
        'swimlaneCards' => 'read',
        'tagRowsRead' => 'read',
        'tagTotalInSwimlanes' => 'read',
        'tagTotalWithoutSwimlane' => 'read',
        'visibility' => 'read',
    ];

    /**
     * The REVERSE arm one level up from the call-site census: the three primitives this class
     * rosters are held against the client's OWN public surface, so a new card-lifecycle
     * method cannot ride along undispositioned — nor, dispositioned into a lifecycle
     * permission, unrostered.
     *
     * ⚠ What it asserts is that every public method is ACCOUNTED FOR, not that the
     * disposition beside it is correct — `read` is a human's reading of what that method
     * does, and a method mis-dispositioned as `read` is green here. That is the residual,
     * and it is a far smaller one than a method nobody looked at at all.
     */
    public function test_every_public_client_method_is_dispositioned_by_this_roster(): void
    {
        $declared = array_keys(self::CLIENT_SURFACE);
        sort($declared);

        $actual = [];
        foreach ((new \ReflectionClass(KanbanClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === KanbanClient::class && ! $method->isConstructor()) {
                $actual[] = $method->getName();
            }
        }
        sort($actual);

        $this->assertSame(
            $declared,
            $actual,
            KanbanClient::class.' has a public method this roster does not dispose of. '
            .'A new CARD-LIFECYCLE primitive is a new way to write a card, and the ones rostered here are a CLAIM about that surface, not a derivation of it — '
            .'so an undispositioned method is exactly how this roster goes short without any row in '.self::ROSTER_DOC.' being wrong. '
            .'Dispose of it: give it one of the roster permissions (and add it to ROSTERS, and name its callers in that row), or one of '.implode(' / ', self::UNROSTERED).' with the owner named in the class docblock.',
        );

        $rostered = self::ROSTERS;
        ksort($rostered);
        $lifecycle = array_filter(
            self::CLIENT_SURFACE,
            static fn (string $permission): bool => ! in_array($permission, self::UNROSTERED, true),
        );
        ksort($lifecycle);

        $this->assertSame(
            $rostered,
            $lifecycle,
            'ROSTERS and CLIENT_SURFACE disagree about which of '.KanbanClient::class.'\'s methods write a card\'s LIFECYCLE, so one of them is stale. '
            .'Compared as MAPS, in both directions: every method this roster names must carry that same permission in CLIENT_SURFACE, and every method CLIENT_SURFACE disposes of into anything but '.implode(' / ', self::UNROSTERED).' must be rostered here. '
            .'A method on the LEFT and not the right is a roster row nothing holds any more — deleting it from ROSTERS silently stops '.self::ROSTER_DOC.'\'s row for it being checked at all. '
            .'A method on the RIGHT and not the left is a FOURTH way to write a card that is dispositioned but unrostered — the shape that was measured green before card#10063. '
            .'Add it to ROSTERS and name its callers in the matching row of '.self::ROSTER_DOC.', or dispose of it as something that is not a card write — '
            .'which for a primitive the published clauses NAME reds the reach check in this class until both of them drop it too, because until then a reader is still being told it is held.',
        );
    }

    public function test_each_roster_row_names_exactly_the_classes_that_call_its_primitive(): void
    {
        $derived = self::classesByMethod();

        foreach (self::ROSTERS as $method => $permission) {
            $classes = $derived[$method] ?? [];

            // ⚠ THE PRESENCE WITNESS FIRST. An empty census passes every set compare below, so
            // without this the guard goes green the moment the derivation stops finding sites —
            // reporting where the scan stopped rather than the state of the tree.
            $this->assertNotSame([], $classes, "no `->{$method}(` call site was derived from app/ at all — the derivation, not the code, is what changed.");

            $this->assertSame(
                $classes,
                self::classesNamedIn(self::rosterRow($permission)),
                "the `{$permission}` row of ".self::ROSTER_DOC." § A least-privilege writeback token does not name exactly the classes that call `->{$method}(` in app/. "
                .'That row is the ONE home of this roster — the cross-repo `program` row of '.self::CONTRACT_DOC.' and this repo\'s own parent-card bullet both POINT at it — so a writer missing from it is a writer no auditor at either end can see. '
                .'Name the new writer by its CLASS in backticks, or delete a name whose class is gone.',
            );
        }
    }

    /**
     * ⭐ THE PUBLISHED REACH IS HELD TOO — the level above the roster and above the client.
     * {@see ROSTERS} is derived at its CALL SITES and held against the client's own SURFACE, but
     * the sentence telling a reader WHICH primitives are held is prose, on two LIVE surfaces, and
     * it was bound to nothing. Measured, not argued: dropping one primitive from ROSTERS and
     * re-dispositioning it as `read` in {@see CLIENT_SURFACE} is TWO LINES, passes every other
     * test in this directory, and leaves both documents telling their readers that a permission
     * is held which nothing holds any more — while the roster row for it stops being checked at
     * all. The remediation offered by the assertion above ("dispose of it as something that is
     * not a card write") is that very edit, which is why this is a check and not a convention.
     *
     * ⛔ SET EQUALITY, BOTH DIRECTIONS, and neither half is enough alone. A primitive dropped
     * from ROSTERS reds because the docs still name it; a primitive NAMED in a doc but absent
     * from ROSTERS reds too, because a document that names a fourth is publishing a guarantee
     * nothing derives.
     *
     * ⛔ AND BOTH SIDES OF THE MAP, because the KEYS alone leave a hole a field over: re-map an
     * existing primitive to a different permission and the keys do not move, so a check over
     * them passes, while the roster doc's enumeration of WHICH ROWS are held becomes false and
     * the row it named stops being checked by anything. Measured green at three lines before
     * this clause was bound. The doc's enumeration is kept rather than replaced by a pointer:
     * it is what tells an operator which rows "LIFECYCLE" means, so binding it is the
     * treatment and deleting it would cost the reader something real.
     *
     * ⛔ The cross-repo one is canon #7's *worse than silence*: the far end cannot read this
     * tree, so it audits its own join against a stated contract and gets confidence where it is
     * owed a question. And the doc's job is to scope a least-privilege API token — a roster that
     * goes short is how an operator grants a scope believing the list complete.
     */
    public function test_every_published_clause_names_exactly_what_this_roster_holds(): void
    {
        $published = self::publishedClauses();

        // ⚠ THE PRESENCE WITNESS FIRST, exactly as one method up. The loop below executes ZERO
        // assertions on an empty list, and narrowing the universal onto {@see publishedClauses}
        // made that list the POPULATION — so the list itself is now the artifact that can go
        // silently short, and the trigger is ordinary: an anchor assertion fires on a doc
        // reword, and the cheapest green is to delete the row that fired.
        // ⛔ SETS, never a count: what must not be lost is that BOTH documents are still read
        // and that both sides of {@see ROSTERS} — its keys and its values — are still published
        // by something. ⚠ It does not witness the roster doc's PRIMITIVES clause alone: dropping
        // that one leaves the contract clause publishing the keys and the row clause naming the
        // doc, and binding it would mean writing the clause list down a second time.
        $this->assertSame(
            [
                'documents read' => self::asSet([self::ROSTER_DOC, self::CONTRACT_DOC]),
                'sides of ROSTERS published' => self::asSet(array_merge(array_keys(self::ROSTERS), array_values(self::ROSTERS))),
            ],
            [
                'documents read' => self::asSet(array_column($published, 'doc')),
                'sides of ROSTERS published' => self::asSet(array_merge(...array_column($published, 'held'))),
            ],
            'the published-reach check has lost part of its own population: this test asserts NOTHING about a clause '
            .'publishedClauses() does not list, so a row deleted from it leaves a published sentence that nothing reads any more — green, and silently. '
            .'If the row was dropped because its anchor stopped matching, the DOC was reworded and the anchor is the fix, not the row; '
            .'if a document genuinely stopped publishing this roster, drop its const here and say so in the class docblock, where the bound on what a green run means is written.',
        );

        foreach ($published as $clause) {
            $this->assertSame(
                self::asSet($clause['held']),
                self::namesInClause((string) file_get_contents(base_path($clause['doc'])), $clause['anchor']),
                $clause['doc'].' publishes a different set of '.$clause['subject'].' from the one ROSTERS holds, so one of them is lying to its reader '
                .'(the clause anchored on "'.$clause['anchor'].'"). '
                .'Compared as SETS, in both directions: something this roster no longer holds is still being published as held — and if it is a permission, its row in '.self::ROSTER_DOC.' is no longer checked at all — '
                .'or the clause names one this roster does not hold, which is a guarantee nothing here derives. '
                .'Every clause here is load-bearing — '.self::ROSTER_DOC.' scopes the least-privilege token an operator grants, and '.self::CONTRACT_DOC.' is read by a far end that cannot read this tree at all. '
                .'Fix the one that is wrong; do not re-sync a count.',
            );
        }
    }

    /**
     * The clause reader's control, over EVERY spelling the three clauses use — the call-site
     * form, the bare method name, and the dotted permission — on fixtures whose answer is
     * known. Each ends the run somewhere different: at a word (` sites from`), at a consumed
     * ` and ` followed by prose, and at a word after ` and `. In all three the token written
     * past that end (`->patchCard(` / `patchCard` / `task.update`) is NOT a member, which is
     * the property that makes the reader an instrument rather than a line scan.
     */
    public function test_the_clause_reader_reads_one_run_and_stops_at_its_end(): void
    {
        $this->assertSame(
            ['archiveCard', 'createCard', 'moveCard'],
            self::namesInClause(
                'and `Whatever` derives the `->moveCard(` / `->createCard(` / `->archiveCard(` sites from the whole of `app/`, unlike the `->patchCard(` census',
                'derives the ',
            ),
        );

        $this->assertSame(
            ['archiveCard', 'createCard', 'moveCard'],
            self::namesInClause(
                "reds when a class calls `Whatever`'s `moveCard` / `createCard` / `archiveCard` and is absent from the list, unlike `patchCard`",
                "calls `Whatever`'s ",
            ),
        );

        $this->assertSame(
            ['task.archive', 'task.create', 'task.move'],
            self::namesInClause(
                'Every writer in the `task.move`, `task.create` and `task.archive` rows is named by its CLASS in backticks, unlike `task.update`',
                'Every writer in the ',
            ),
        );
    }

    /**
     * The instrument's own control, on a fixture whose answer is known: a mention in prose is
     * not a site, the declaration of the primitive is not a site, a nullsafe call is, and a
     * commented-out call is not.
     */
    public function test_the_scanner_tells_a_call_site_from_a_mention(): void
    {
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming ->moveCard( and ->archiveCard( and {@see KanbanClient::createCard}. */
            public function writes(): void
            {
                // $client->archiveCard($id);
                $client->moveCard($id, $stage);
                $client?->createCard($board, $stage, $title, $payload, $tags, null);
            }

            public function moveCard(int $id, int $stage): void
            {
                $this->patchCard($id, ['workflow_stage_id' => $stage]);
            }
        }
        PHP;

        $this->assertSame(
            [
                'Fixture.php::writes#1' => 'moveCard',
                'Fixture.php::writes#2' => 'createCard',
            ],
            SourceScan::sites($source, 'Fixture.php', self::siteAt(...)),
        );
    }

    /** The row reader's control: one row per permission, matched on the permission cell alone. */
    public function test_the_row_reader_selects_one_row_by_its_permission_cell(): void
    {
        $row = self::rosterRow('task.move');

        $this->assertStringStartsWith('| `task.move` | ', $row);
        $this->assertStringNotContainsString('`task.create`', $row);
    }

    /** The name reader's control: a class in backticks is a roster name; prose and a lower-case token are not. */
    public function test_the_name_reader_tells_a_class_from_prose(): void
    {
        $this->assertSame(
            ['CardCollapse', 'KanbanDependabotCardHandler'],
            self::classesNamedIn('| `task.archive` | `_action: archive` — the retire (DL-161, `KanbanDependabotCardHandler`), the collapse (`CardCollapse`), and KanbanMoveCardHandler unquoted, `board_create_card`, `KanbanDependabotCardHandler` again |'),
        );
    }

    /**
     * The classes calling each primitive in `app/`, sorted, deduplicated.
     *
     * @return array<string, list<string>>
     */
    private static function classesByMethod(): array
    {
        $byMethod = [];
        /** @var array<string, string> $sites */
        $sites = SourceScan::sitesInApp(self::siteAt(...));
        foreach ($sites as $site => $method) {
            $class = basename(strstr($site, '::', true) ?: $site, '.php');
            $byMethod[$method][$class] = true;
        }

        $out = [];
        foreach ($byMethod as $method => $classes) {
            $names = array_keys($classes);
            sort($names);
            $out[$method] = $names;
        }

        return $out;
    }

    /**
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function siteAt(array $tokens, int $index, int $scopeStart): ?string
    {
        return SourceScan::methodCallAt($tokens, $index, array_keys(self::ROSTERS));
    }

    /**
     * Every LIVE clause publishing part of what this roster holds: the doc, the text that
     * immediately precedes its run of backticked names, what that run is a set OF, and the
     * side of {@see ROSTERS} it must equal.
     *
     * ⛔ THE ANCHOR IS COMPOSED FROM A SYMBOL wherever one names the clause: renaming this
     * class or {@see KanbanClient} reds the lookup in {@see namesInClause}, where a
     * hand-written anchor would quietly match nothing and leave the clause unread — a check
     * that has ceased to read anything, reporting green. The third has no symbol to compose
     * from and is the one hand-written anchor here; presence and uniqueness are asserted for
     * it exactly as for the others, so a reword reds rather than silencing it.
     *
     * ⛔ THE THIRD CLAUSE IS THE VALUES, and it is a distinct hole from the other two. Both
     * key clauses pass a re-map of an existing primitive to a different permission
     * (`'archiveCard' => 'task.delete'`) — ROSTERS' KEYS do not move — and a doc that has
     * grown the new row satisfies {@see rosterRow} too, leaving the enumeration of which rows
     * are held false with the suite green. Measured at three lines before this clause existed.
     *
     * @return list<array{doc: string, anchor: string, subject: string, held: list<string>}>
     */
    private static function publishedClauses(): array
    {
        return [
            [
                'doc' => self::ROSTER_DOC,
                'anchor' => '`'.class_basename(self::class).'` derives the ',
                'subject' => 'card-write PRIMITIVES',
                'held' => array_keys(self::ROSTERS),
            ],
            [
                'doc' => self::CONTRACT_DOC,
                'anchor' => 'calls `'.class_basename(KanbanClient::class)."`'s ",
                'subject' => 'card-write PRIMITIVES',
                'held' => array_keys(self::ROSTERS),
            ],
            [
                'doc' => self::ROSTER_DOC,
                'anchor' => 'Every writer in the ',
                'subject' => 'PERMISSION rows',
                'held' => array_values(self::ROSTERS),
            ],
        ];
    }

    /**
     * $values as a SET — deduplicated and sorted, the shape {@see namesInClause} returns.
     *
     * The deduplication is what makes both sides of that comparison the same shape; it is inert
     * on {@see ROSTERS}' own values today, and not a blessing of the state that would exercise
     * it: {@see test_each_roster_row_names_exactly_the_classes_that_call_its_primitive} holds
     * one row against the callers of EACH primitive, so two primitives sharing one permission
     * row is unsatisfiable there unless their caller sets are identical.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function asSet(array $values): array
    {
        $set = array_values(array_unique($values));
        sort($set);

        return $set;
    }

    /**
     * The names of the ONE clause in $text that follows $anchor: the run of backticked tokens
     * joined by ` / `, `, ` or ` and `, in every spelling the docs use (`->name(` at a call
     * site, `name` in prose, `task.name` for a permission), ended by the first token that is
     * not one. Sorted, deduplicated.
     *
     * ⛔ A RUN, NOT A SCAN OF THE LINE. Every clause sits in prose that names other methods or
     * permissions — `->patchCard(` is in the same sentence as one and the same table row as
     * another, `task.update` a few words past the third — so a reader that swept the line would
     * hold this roster against a population the sentence is not about. The anchor is asserted
     * PRESENT and UNIQUE for the same reason: an anchor that matched nothing, or matched a
     * second clause, would report on where the reader stopped.
     *
     * ⚠ THE SEPARATOR SET IS WIDER THAN ANY ONE CLAUSE NEEDS, and the direction that costs in
     * is the safe one. A backticked token written immediately after one of them JOINS the run
     * — so prose gaining `` `x` and `y` `` at the end of a clause reds with a set one too big,
     * loudly, rather than passing with one too small. Consuming a separator that is not
     * followed by a backticked token (`and is absent from the list`) simply ends the run, which
     * is why the contract clause's trailing ` and ` is harmless.
     *
     * @return list<string>
     */
    private static function namesInClause(string $text, string $anchor): array
    {
        $at = strpos($text, $anchor);
        Assert::assertIsInt($at, "no clause anchored on \"{$anchor}\" — the sentence publishing which primitives are held has moved or been reworded, so nothing is reading it any more.");
        $tail = substr($text, $at + strlen($anchor));
        Assert::assertStringNotContainsString($anchor, $tail, "a SECOND clause is anchored on \"{$anchor}\" — one of them is unread, which is how two published rosters come to disagree.");

        $names = [];
        while (preg_match('/^`(?:->)?([A-Za-z][A-Za-z0-9.]*)\(?`( \/ |, | and )?/', $tail, $match) === 1) {
            $names[] = $match[1];
            $tail = substr($tail, strlen($match[0]));
            if (! isset($match[2])) {
                break;
            }
        }

        return self::asSet($names);
    }

    /** The one table row whose permission cell is $permission. */
    private static function rosterRow(string $permission): string
    {
        $prefix = '| `'.$permission.'` | ';
        $rows = [];
        foreach (explode("\n", (string) file_get_contents(base_path(self::ROSTER_DOC))) as $line) {
            if (str_starts_with($line, $prefix)) {
                $rows[] = $line;
            }
        }

        Assert::assertCount(1, $rows, "expected exactly ONE `{$permission}` row in ".self::ROSTER_DOC.' — a second copy of the permission table is the duplication this roster exists to prevent, and none at all means the table moved.');

        return $rows[0];
    }

    /**
     * The class-shaped backticked names in $row, sorted, deduplicated — the house spelling
     * every writer in a roster row is written in.
     *
     * @return list<string>
     */
    private static function classesNamedIn(string $row): array
    {
        preg_match_all('/`([A-Z][A-Za-z0-9]*)`/', $row, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
