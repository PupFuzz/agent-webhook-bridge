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
 * POPULATION: every `->moveCard(` / `->createCard(` / `->archiveCard(` call in every `*.php`
 * under `app/`, asked of PHP's own tokenizer through {@see SourceScan::sitesInApp} so that a
 * docblock mention, a `{@see}` and the declaration itself are excluded by CONSTRUCTION rather
 * than by a regex the next spelling walks past. Sites are keyed `<path under app/>::<function>#<n>`
 * and reduced to the CLASS, because the roster names classes and not call sites.
 *
 * ⭐ AND THE THREE PRIMITIVES ARE THEMSELVES HELD, not asserted. That those three are the whole
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
 * primitive is exactly the set of classes that row names. It does NOT say the prose beside
 * each name describes that class correctly, and it does not reach a mover in another repo.
 *  - **The house spelling is load-bearing and is a convention, not a property this can
 *    enforce:** every writer in one of these three rows is named by its CLASS in backticks.
 *    A class-shaped backticked token added to one of these rows for some other purpose reds
 *    this class — loudly, with an obvious remedy (put the aside outside the row), which is the
 *    direction a census instrument must fail in.
 *  - **A row naming the right classes with the wrong prose is green here.** A reviewer reads
 *    the prose; this reads the roster.
 *  - **`tests/` is not in the population.** A test double is not a writeback.
 *  - **It reaches {@see KanbanClient}.** A card written through some other client class is
 *    outside both the primitive surface and the call-site census.
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
     * REMOVE. Deriving the CALL SITES of three primitives is only half a derivation: the
     * three are themselves a hand-written claim about which primitives write a card, so a
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
            .'A new CARD-LIFECYCLE primitive is a new way to write a card, and the three rostered here are a CLAIM about that surface, not a derivation of it — '
            .'so an undispositioned method is exactly how this roster goes short without any row in '.self::ROSTER_DOC.' being wrong. '
            .'Dispose of it: give it one of the roster permissions (and add it to ROSTERS, and name its callers in that row), or `task.update` / `comment.create` / `read` with the owner named in the class docblock.',
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
            .'Add it to ROSTERS and name its callers in the matching row of '.self::ROSTER_DOC.', or dispose of it as something that is not a card write.',
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
