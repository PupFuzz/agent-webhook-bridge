<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\MappedBoardGuard;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * The class guard for card#7212 (rt#327 R4) — the SUCCESS side of the board record.
 *
 * THE DEFECT: the writeback's success path logged the board it INTENDED to write to
 * (`$mapping->boardId`, straight from config), while only the REFUSAL path logged the
 * board the card was actually on. So a write that LANDED on an out-of-mapping card was
 * byte-identical in the log to a correct one, retention is 14 days, and no audit table
 * records a card or board id — which made "has a cross-board write ever landed here?"
 * not merely unanswered but UNANSWERABLE. It is the blocking reason card#7211's blast
 * radius cannot be measured retrospectively.
 *
 * ⭐ GENERALISED, and the reason this deserves a class guard rather than N fixed sites:
 * any check whose evidence is emitted ONLY on the refusal path can answer *"did we ever
 * stop it?"* — never *"did this ever happen?"*. An absence of record is not a record of
 * absence. It is the same shape as a detector that fires only on the rare path: the
 * common path emits nothing, so it reads as though it never ran.
 *
 * ⛔ THE ONE RULE, because this class polices consistency and cannot do that against two
 * rules. The pair records where a write LANDED, so it goes on every record reporting the
 * outcome of a write kanban ACCEPTED (2xx) against a card whose id was resolved from
 * kanban — INCLUDING the arm that reports an accepted write whose effect did not take
 * (the `archive returned 200 but the card is not archived` contract break), because there
 * the request did reach that card and a 200-not-archived on a FOREIGN card is exactly the
 * cross-board touch this record exists to make visible. A 4xx arm is the other side of
 * that line: kanban REJECTED the request, nothing landed on any board, so there is no
 * landed board to name. Every write-refusal arm is therefore unchanged by card#7212 and
 * names no `card_board` — `kanban_move_card`'s `kanban refused the move (4xx)` keeps its
 * pre-existing config-sourced `board` key, and the `blockreason` / `stamp` / `cardnote` /
 * `promote_movecard` arms keep logging no board at all — while the two archive-contract
 * `Log::error`s carry the pair. (The flat 4xx catches in the two create-capable handlers
 * span correlation READS as well as the write, so the pair would be wrong-but-specific
 * there even if the rule ran the other way.)
 *
 * WHAT THIS CLASS DOES AND DOES NOT COVER. The per-arm BEHAVIOURAL legs — a successful
 * write emits `card_board` + `mapped_board`, equal on the happy path, and DIVERGENT when
 * the resolved card is on another board — live beside the handlers they exercise, in
 * `tests/Feature/Handlers/Kanban*HandlerTest`. Each divergence leg was seen to fail
 * against a mutant rendering `mapped_board` into both slots, which is the defect wearing
 * the new field's name. This class carries the three STRUCTURAL legs those cannot be:
 *
 *  1. {@see test_every_kanban_write_in_the_population_is_accounted_for} — the write
 *     census. Fixing N sites without a guard leaves the N+1th to mint the bug again
 *     (canon #7), and the N+1th here is a NEW write, which no behavioural test can
 *     anticipate. Keyed `<file>:<verb>` with a COUNT rather than by line, so an unrelated
 *     edit above a call site does not red and a genuinely new call does.
 *  2. {@see test_the_board_pair_has_exactly_one_rendering} — nobody spells the pair by
 *     hand. Two renderings of one record is how the refusal and success arms came to
 *     disagree in the first place (canon #5); `MappedBoardGuard::boardContext()` is the
 *     single owner and both arms route through it.
 *  3. {@see test_every_collapse_call_in_the_population_passes_its_mapping} — the shared
 *     collapse primitive renders the pair only when its caller hands it a mapping (the
 *     board-tools caller has none — see the bound below), so a writeback caller that
 *     omitted it would silently drop the record for the archives it fires. That omission
 *     is invisible to leg 1, which counts call sites and not their arguments.
 *
 * ⛔ THE DENOMINATOR, and how each pass RE-DERIVES it rather than quoting a figure
 * (canon #19). Three axes, all three now derived from the sources:
 *   • FILES — `glob()` over `app/Bridge/Handlers/Kanban*Handler.php`,
 *     `app/Bridge/Writeback/*.php` AND `app/Console/Commands/Bridge/*.php`. The second was
 *     added by the card#7212 review: the shared write primitives are exactly where canon #5
 *     pushes duplicated writes, so a population of handlers alone made the one directory most
 *     likely to hold a shared write into a blind spot — and it did: `CardCollapse`'s archive
 *     recorded NO board. The third was added by the DL-301 review, for the same reason one
 *     directory further out: `bridge:reconcile --fix` is a kanban write inside the DL-009
 *     mapped-board regime that no glob here reached, so it was carried as a stated residue
 *     instead of being policed. An event handler is not the only thing that writes to a card.
 *   • VERBS — {@see writeMethodsOf} reads `KanbanClient` and keeps every method that reaches
 *     a mutating HTTP verb (`->patch(` / `->post(` / `->delete(`) — directly, OR through a
 *     `$this->` call to a sibling that does, to a fixed point. A hand-written const would not
 *     red when a new write method shipped; this does. ⛔ The transitive leg is load-bearing,
 *     not tidiness: when card#8378 hoisted the client's three flat-PATCH writes behind one
 *     `patchCard` primitive, the direct-issuer rule stopped seeing `moveCard`,
 *     `setBlockReason` and `stampCorrelationRefs`, and NINE accounted call sites left the
 *     census with every behavioural test still green. A correct consolidation had silently
 *     narrowed the population this guard derives over.
 *   • RECEIVERS — {@see writeSites} matches `->verb(` on ANY receiver, subtracting only
 *     `$this->`. The earlier form allow-listed `$client` / `$kanban`, so
 *     `$this->client->moveCard(` or an aliased local was invisible to the census.
 *
 * STATED BOUNDS, and they are real ones.
 *   • Leg 1 proves each write site was CONSIDERED, not that its record is correct — that
 *     is leg 2's and the behavioural legs' job.
 *   • A write minted in `bin/` or in `app/Bridge/Tools/` is outside the file population, and
 *     the reason is the REGIME, not the directory. The board-tools surface is not under the
 *     DL-009 mapped-board rule at all: its board is FORCED from `BoardToolsConfig`, not from a
 *     per-repo `WritebackMapping`, which is why `CardCollapse::toSurvivor()` takes its
 *     mapping as `?WritebackMapping` and why `BoardCreateCardTool`'s collapse records no
 *     pair. Whether that surface wants its own board record is filed, not answered here.
 *     ⛔ A Console command is NO LONGER outside it. `bridge:reconcile --fix` is inside the
 *     regime (DL-301, card#7211) — it reads a mapped board, refuses a row naming another
 *     through `MappedBoardGuard::refuses()`, and records the pair on its applied move — so
 *     the DL-301 review put `app/Console/Commands/Bridge/*.php` in the glob rather than
 *     leaving it as a stated residue. A second kanban write added to that command, or a first
 *     one added to any other `bridge:*` command, now reds here until it is accounted for.
 *     Its behavioural cover is
 *     `ReconcileCommandTest::test_an_applied_move_records_the_cards_own_board_durably`.
 *     ⚑ What the widening costs, stated because it is the reason it was ever deferred: leg 2
 *     forbids spelling `card_board` / `mapped_board` by hand anywhere in the population, and a
 *     command that wants to PRINT the card's own board would trip it. `ReconcileCommand` had
 *     such a helper for one commit; it was deleted rather than exempted, because after the
 *     guard the two values are equal on every row that reaches a report line, so it rendered a
 *     divergence that cannot occur and an `unknown` arm for a row that cannot get there. The
 *     command's report names the mapped board; the pair lives on the durable record, which is
 *     the half that answers *"did this ever happen?"*. A command with a real need to render the
 *     card's own value must extend the primitive, not re-spell the keys.
 *   • A CREATE has no resolved card to read a board from, so it keeps its lone `board`
 *     key. That a created card's ACTUAL placement is never read back is a sibling shape,
 *     filed as card#7225.
 */
class WritebackSuccessBoardRecordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every kanban WRITE reachable from a writeback handler, a shared writeback write
     * primitive, or a `bridge:*` console command, keyed `<file>:<verb>` with the number of
     * call sites, and the reason its success record does or does not carry the (card board,
     * mapped board) pair. Set equality in both directions AND on the counts: a new write
     * reds, and so does an entry whose call site has gone.
     *
     * @var array<string, array{sites: int, record: string}>
     */
    private const ACCOUNTED = [
        'CardCollapse.php:archiveCard' => [
            'sites' => 1,
            'record' => 'PAIRED — the shared duplicate-collapse kernel (DL-198). Its ids come from a '
                .'board-scoped correlate/search, so this is a Group-B write (card#7211) and the archived '
                .'row is the only reading of where it landed. DL-298 put a refuses() re-check in front of '
                .'every caller inside the mapped-board regime, which does not retire this record: a gate '
                .'emits evidence only when it REFUSES. Both the '
                .'archived line and the 200-but-not-archived Log::error carry the pair, per the one rule '
                .'above. The pair renders only when the caller passes a mapping — leg 3 is what makes '
                .'that a requirement rather than a hope for every caller in this population.',
        ],
        'ReconcileCommand.php:moveCard' => [
            'sites' => 1,
            'record' => 'PAIRED — `bridge:reconcile --fix` (DL-301, card#7211). A GROUP-B site reached from a CLI '
                .'rather than a handler: the id comes from the same board-scoped `readBoardCards` search, '
                .'reconcileCard() re-checks each row through refuses() before the run decides anything about '
                .'it, and the applied move records the pair on `Log::info(\'bridge_reconcile: moved\', …)`. '
                .'The console MOVED/DRIFT lines are not the record — they go wherever the operator\'s cron '
                .'redirects stdout.',
        ],
        'KanbanBlockReasonHandler.php:setBlockReason' => [
            'sites' => 1,
            'record' => 'PAIRED — `kanban_block_reason: <action>` carries boardContext($card, $mapping)',
        ],
        'KanbanCoordCardHandler.php:createCard' => [
            'sites' => 1,
            'record' => 'CREATE — no resolved card exists to read a board from; the board is the POST target, '
                .'so `board` there is outcome and intent at once. The create RESPONSE returns an id only.',
        ],
        'KanbanCoordCardMoveHandler.php:moveCard' => [
            'sites' => 3,
            'record' => 'PAIRED — terminal / revived / re-laned each carry boardContext($card, $mapping). '
                .'These logged NEITHER board before card#7212.',
        ],
        'KanbanDependabotCardHandler.php:archiveCard' => [
            'sites' => 1,
            'record' => 'PAIRED — a GROUP-B site (card#7211): the id came from a board-scoped search, so the '
                .'card row is the only reading of where the write landed. cardsForRepo() re-checks that row '
                .'against the mapped board (DL-298) — a gate on the write, not a record of it.',
        ],
        'KanbanDependabotCardHandler.php:moveCard' => [
            'sites' => 1,
            'record' => 'PAIRED — Group-B, as the archive arm; the survivor row carries its own board.',
        ],
        'KanbanDependabotCardHandler.php:createCard' => [
            'sites' => 1,
            'record' => 'CREATE — see the coord-card create above.',
        ],
        'KanbanMoveCardHandler.php:moveCard' => [
            'sites' => 1,
            'record' => 'PAIRED — `kanban_move_card: moved`; replaced the single config-sourced `board` key.',
        ],
        'KanbanMoveCardHandler.php:stampCorrelationRefs' => [
            'sites' => 1,
            'record' => 'PAIRED — and load-bearing: on the already-in-stage self-heal this is the ONLY success '
                .'record the delivery emits, since the `moved` line never fires there.',
        ],
        'KanbanMoveCardHandler.php:addComment' => [
            'sites' => 1,
            'record' => 'PAIRED — the card note is a comment ROW written to the resolved card.',
        ],
        'KanbanPromoteReleasedHandler.php:moveCard' => [
            'sites' => 1,
            'record' => 'PAIRED — Group-B: the row is read at scan time and its board CARRIED to the promote '
                .'two calls later, where the row is out of scope. Carried, never re-read. ONE capture serves '
                .'the DL-298 gate at candidacy and this record alike.',
        ],
    ];

    /**
     * The scanned population: the writeback handlers plus the shared writeback primitives
     * they push writes into. Re-derived per pass — never a list — and floored in both
     * directions so a glob that stopped matching reds as a glob failure rather than
     * reporting a repo with no writes in it.
     *
     * @return list<string>
     */
    private function population(): array
    {
        $handlers = glob(base_path('app/Bridge/Handlers/Kanban*Handler.php')) ?: [];
        $writeback = glob(base_path('app/Bridge/Writeback/*.php')) ?: [];
        $commands = glob(base_path('app/Console/Commands/Bridge/*.php')) ?: [];
        $this->assertGreaterThanOrEqual(6, count($handlers), 'the handler population came back short — the glob, not the code, is what changed');
        $this->assertGreaterThanOrEqual(20, count($writeback), 'the writeback population came back short — the glob, not the code, is what changed');
        $this->assertGreaterThanOrEqual(8, count($commands), 'the bridge-command population came back short — the glob, not the code, is what changed');

        return array_merge($handlers, $writeback, $commands);
    }

    public function test_every_kanban_write_in_the_population_is_accounted_for(): void
    {
        $verbs = self::writeVerbs();
        $this->assertNotSame([], $verbs, 'the write-verb derivation came back EMPTY — KanbanClient parsed to no writes at all, which is the parser failing, not the client');

        $found = [];
        foreach ($this->population() as $file) {
            foreach (self::writeSites((string) file_get_contents($file), basename($file), $verbs) as $key => $count) {
                $found[$key] = ($found[$key] ?? 0) + $count;
            }
        }

        $expected = array_map(fn (array $e): int => $e['sites'], self::ACCOUNTED);
        ksort($expected);
        ksort($found);

        $this->assertSame(
            $expected,
            $found,
            'a kanban WRITE in a writeback handler, a shared writeback primitive or a bridge '
            .'command is not accounted for. Every write to a card whose id was RESOLVED from kanban must log the pair '
            .'MappedBoardGuard::boardContext() renders, so a write that lands on an out-of-mapping card is '
            .'distinguishable from a correct one after the fact (card#7212). Add the site to ACCOUNTED with '
            .'what its success record carries — or with the reason it carries no card board, which for a '
            .'CREATE is that no resolved card exists.',
        );
    }

    public function test_the_write_verb_derivation_discriminates_a_write_from_a_read(): void
    {
        // The derivation's own control, and the reason WRITE_VERBS is no longer a const:
        // a hand-written list is a figure the loop quotes, so it cannot red when the client
        // grows a seventh write (card#7212 review, canon #19). A derivation can only be
        // trusted if it is seen to SEPARATE — a parser that returned every method, or none,
        // would look just as green against the real client.
        $source = <<<'PHP'
        <?php
        /** A docblock naming $this->http()->patch( in prose, above a method that only reads. */
        public function readsOnly(int $id): array
        {
            return $this->http()->get("/tasks/{$id}.json")->throw()->json('data');
        }

        public function writesByPatch(int $id): void
        {
            // A comment naming ->post( inside a method that patches.
            $this->http()->patch("/tasks/{$id}.json", [])->throw();
        }

        public function writesByPost(int $id): void
        {
            $this->http()->post("/tasks/{$id}/comments.json", [])->throw();
        }

        private function writesByDelete(int $id): void
        {
            $this->http()->delete("/tasks/{$id}.json")->throw();
        }

        public function alsoReadsOnly(int $id): bool
        {
            return $this->http()->get('/x.json')->ok();
        }
        PHP;

        $this->assertSame(
            ['writesByDelete', 'writesByPatch', 'writesByPost'],
            self::writeMethodsOf($source),
        );
    }

    public function test_the_write_verb_derivation_follows_a_delegating_write(): void
    {
        // card#8378's control, and it is a POSITIVE and a NEGATIVE in one fixture, because
        // the transitive rule is only trustworthy if it also declines: `delegatesToARead`
        // calls a sibling exactly as `delegatesToTheWriter` does, and must NOT be a writer.
        // Without this leg the client could consolidate its writes behind one primitive and
        // the census would go quiet — which is what happened, and what the fixed point fixes.
        $source = <<<'PHP'
        <?php
        public function issuesTheVerb(int $id, array $fields): void
        {
            $this->http()->patch("/tasks/{$id}.json", $fields)->throw();
        }

        public function delegatesToTheWriter(int $id, int $stage): void
        {
            $this->issuesTheVerb($id, ['workflow_stage_id' => $stage]);
        }

        public function delegatesTwoDeep(int $id): void
        {
            $this->delegatesToTheWriter($id, 1);
        }

        public function readsOnly(int $id): array
        {
            return $this->http()->get("/tasks/{$id}.json")->throw()->json('data');
        }

        public function delegatesToARead(int $id): array
        {
            return $this->readsOnly($id);
        }
        PHP;

        $this->assertSame(
            ['delegatesToTheWriter', 'delegatesTwoDeep', 'issuesTheVerb'],
            self::writeMethodsOf($source),
            'a method that reaches a mutating verb through a sibling is a WRITE; one that reaches only a read is not'
        );
    }

    public function test_the_write_scanner_discriminates_a_real_call_from_a_comment(): void
    {
        // The census expects an exact map, so a scanner that had stopped matching would red
        // loudly rather than silently — but one that matched PROSE would report writes that
        // are not there, and force a bogus ACCOUNTED entry. Both directions, known answer.
        //
        // ⭐ The RECEIVER legs (`$kb`, `$this->client`) are the card#7212 review's reproduced
        // bypass: the earlier allow-list of `$client`/`$kanban` let an aliased local or a
        // promoted property carry a brand-new write straight past the census.
        $source = <<<'PHP'
        <?php
        // A comment mentioning $client->moveCard( and $client->archiveCard(.
        /** A docblock naming $kanban->createCard( too. */
        $client->moveCard($id, $stage);
        $kanban->moveCard($cardId, $released);
                $client->archiveCard($cardId);
        $kb = $client;
        $kb->moveCard($cardId, $stage);
        $this->client->moveCard($cardId, $stage);
        $this->stampCorrelationRefs($card, $mapping, $payload, $cardId, $client, $repo, $outcome);
        $card = $client->getCard($id);
        $ids = $client->cardsByTag($boardId, $tag);
        PHP;

        $this->assertSame(
            ['Fixture.php:archiveCard' => 1, 'Fixture.php:moveCard' => 4],
            self::writeSites($source, 'Fixture.php', ['moveCard', 'stampCorrelationRefs', 'archiveCard', 'createCard']),
        );
    }

    /**
     * The KanbanClient methods that WRITE, re-derived from the client's own source every
     * run. A read cannot land on the wrong board, so only the mutating verbs are policed.
     *
     * @return list<string>
     */
    private static function writeVerbs(): array
    {
        return self::writeMethodsOf((string) file_get_contents(base_path('app/Bridge/Writeback/KanbanClient.php')));
    }

    /**
     * Every method in $source that writes — one that issues a mutating HTTP verb ITSELF, or
     * one that reaches a write by calling another method of the same class, to a fixed point.
     *
     * ⛔ THE TRANSITIVE LEG IS NOT A REFINEMENT, IT IS WHAT KEEPS THE CENSUS FROM EMPTYING
     * ITSELF (card#8378). The direct-issuer rule was exact only while every write verb spelled
     * `->patch(` in its own body. The moment the client grew ONE shared PATCH primitive and the
     * narrow verbs delegated to it — `moveCard` / `setBlockReason` / `stampCorrelationRefs` now
     * read `$this->patchCard(…)` — those three stopped matching, the derivation lost them, and
     * **nine accounted call sites vanished from the census while every behavioural test stayed
     * green**. That is the failure this class exists to prevent, arriving through its own
     * instrument: a consolidation that is correct in the code silently narrows the population
     * the guard is derived over. Following `$this-><method>(` to a fixed point makes the
     * derivation describe what a WRITE is (a call that ends in a mutating verb) rather than how
     * one happens to be spelled today.
     *
     * ⚑ Visibility is deliberately NOT filtered. A private writer cannot be called from a
     * handler, so including it can only WIDEN the set of names the census looks for — and a
     * name that appears nowhere contributes nothing. Filtering on `public` would instead
     * make the derivation fail SILENTLY the day a write moved behind a helper, which is the
     * wrong direction for an instrument whose whole job is to red on a new write.
     *
     * @return list<string>
     */
    private static function writeMethodsOf(string $source): array
    {
        $writers = [];
        $selfCalls = [];
        $current = null;
        foreach (SourceScan::codeLines($source) as $line) {
            if (preg_match('/\bfunction\s+(\w+)\s*\(/', $line, $m) === 1) {
                $current = $m[1];
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/->(?:patch|post|delete)\(/', $line) === 1) {
                $writers[$current] = true;
            }
            if (preg_match_all('/\$this->(\w+)\(/', $line, $calls) > 0) {
                foreach ($calls[1] as $callee) {
                    $selfCalls[$current][] = $callee;
                }
            }
        }

        // Fixed point: a method calling a writer is a writer. Terminates because each pass
        // either adds a name from a finite set or stops.
        do {
            $grew = false;
            foreach ($selfCalls as $method => $callees) {
                if (isset($writers[$method])) {
                    continue;
                }
                foreach ($callees as $callee) {
                    if (isset($writers[$callee])) {
                        $writers[$method] = true;
                        $grew = true;

                        break;
                    }
                }
            }
        } while ($grew);

        $names = array_keys($writers);
        sort($names);

        return $names;
    }

    /**
     * Every kanban write call in $source, keyed `<file>:<verb>` => count. Comment lines are
     * skipped (the handlers discuss their own writes at length in prose).
     *
     * ⛔ THE PREDICATE, stated so it equals its scope: `->verb(` on ANY receiver, minus
     * `$this->verb(`. The exclusion is not a receiver allow-list in disguise — it removes
     * exactly the handler's OWN private helper (`$this->stampCorrelationRefs(` wraps the
     * client write it delegates to, and counting both would double-count one site). Every
     * other receiver — `$client`, `$kanban`, an aliased local, `$this->client` — counts.
     *
     * @param  list<string>  $verbs
     * @return array<string, int>
     */
    private static function writeSites(string $source, string $file, array $verbs): array
    {
        $sites = [];
        foreach (SourceScan::codeLines($source) as $line) {
            foreach ($verbs as $verb) {
                $sites[$file.':'.$verb] = ($sites[$file.':'.$verb] ?? 0)
                    + preg_match_all('/(?<!\$this)->'.$verb.'\(/', $line);
            }
        }

        ksort($sites);

        return array_filter($sites);
    }

    public function test_the_board_pair_has_exactly_one_rendering(): void
    {
        // Canon #5, and the direct cause of the defect: the refusal arm spelled the pair
        // out inline, so when the success arm was written it grew its own — a single
        // config-sourced `board` key — and the two answered different questions. The pair
        // now has ONE owner; a handler spelling `card_board` itself is a second rendering.
        $found = [];
        foreach ($this->population() as $file) {
            if (basename($file) === 'MappedBoardGuard.php') {
                continue;   // the OWNER of the rendering; the assertions below pin its output
            }
            foreach (self::handRolledPairSites((string) file_get_contents($file), basename($file)) as $site) {
                $found[] = $site;
            }
        }

        $this->assertSame(
            [],
            $found,
            'a writeback handler, primitive or bridge command renders `card_board` / `mapped_board` '
            .'itself instead of calling '
            .'MappedBoardGuard::boardContext(). One record, one rendering — two is how the refusal arm and '
            .'the success arm came to disagree about which board they were naming (card#7212).',
        );

        // The other direction. Without this the empty set above would be equally consistent
        // with NOBODY owning the rendering — and with the pair having quietly stopped being
        // emitted at all, which is the exact state this class exists to make impossible.
        $mapping = new WritebackMapping(8, ['merged' => 52]);
        $this->assertSame(
            ['card_board' => 12, 'mapped_board' => 8],
            MappedBoardGuard::boardContext(['id' => 7, 'board_id' => 12], $mapping),
            'MappedBoardGuard::boardContext() no longer reads the CARD for card_board. A rendering that echoes '
            .'the mapped board into both slots is the original defect wearing the new field\'s name.',
        );
        $this->assertSame(
            ['card_board' => null, 'mapped_board' => 8],
            MappedBoardGuard::boardContext(['id' => 7], $mapping),
            'a card kanban returned without a board_id must record that ABSENCE, never fall back to the mapped '
            .'board — a read-time fallback would manufacture the very agreement this record exists to test.',
        );
    }

    public function test_the_hand_rolled_pair_scanner_discriminates_a_real_render_from_a_comment(): void
    {
        $source = <<<'PHP'
        <?php
        // A comment mentioning 'card_board' and 'mapped_board'.
        /** A docblock naming `card_board` too. */
        Log::info('moved', ['card_id' => $id] + MappedBoardGuard::boardContext($card, $mapping));
        Log::info('moved', ['card_board' => $card['board_id'] ?? null, 'mapped_board' => $mapping->boardId]);
                $ctx['mapped_board'] = $mapping->boardId;
        PHP;

        $this->assertSame(
            ['Fixture.php:5', 'Fixture.php:6'],
            self::handRolledPairSites($source, 'Fixture.php'),
        );
    }

    /**
     * Every non-comment line in $source spelling `card_board` or `mapped_board` as a literal,
     * as `<file>:<line>`. A line routing through the primitive names neither — that is what
     * makes the expected set empty and the scan meaningful.
     *
     * @return list<string>
     */
    private static function handRolledPairSites(string $source, string $file): array
    {
        $sites = [];
        foreach (SourceScan::codeLines($source) as $n => $line) {
            if (preg_match('/[\'"](?:card_board|mapped_board)[\'"]/', $line) === 1) {
                $sites[] = $file.':'.$n;
            }
        }

        return $sites;
    }

    public function test_every_collapse_call_in_the_population_passes_its_mapping(): void
    {
        // `CardCollapse::toSurvivor()` renders the pair for the archives it fires, but only
        // from a mapping its caller hands it — the parameter is `?WritebackMapping` because
        // the board-tools caller is outside the DL-009 mapped-board regime entirely (see the
        // class docblock's bounds). Inside THIS population there is no such caller, and a
        // writeback caller that omitted the mapping would silently drop the board record for
        // every duplicate it archives while the census above still counted the site as
        // present. Leg 1 counts call sites; this leg reads their arguments.
        $found = [];
        foreach ($this->population() as $file) {
            foreach (self::collapseCallsWithoutMapping((string) file_get_contents($file), basename($file)) as $site) {
                $found[] = $site;
            }
        }

        $this->assertSame(
            [],
            $found,
            'a CardCollapse::toSurvivor() call in the writeback population does not spell `$mapping` on its '
            .'call line. Every writeback caller has the repo mapping in scope, and without it the collapse '
            .'archives log no board at all — the exact Group-B blind spot card#7212 exists to close.',
        );
    }

    public function test_the_collapse_call_scanner_discriminates_a_passed_mapping_from_an_omitted_one(): void
    {
        // The leg above expects an EMPTY set, so it needs a fixture with a known answer or a
        // scanner that had stopped matching would report the repo clean. Both directions.
        $source = <<<'PHP'
        <?php
        // Prose: CardCollapse::toSurvivor($client, $cards, 'x', '', []) with no mapping.
        CardCollapse::toSurvivor($client, $cards, 'kanban_dependabot_card', $repo, ['repo' => $repo], $mapping);
        CardCollapse::toSurvivor($client, $live, 'board_create_card', '', ['agent' => $a]);
        PHP;

        $this->assertSame(['Fixture.php:4'], self::collapseCallsWithoutMapping($source, 'Fixture.php'));
    }

    /**
     * Every non-comment `CardCollapse::toSurvivor(` call line in $source that does NOT spell
     * the literal `$mapping`, as `<file>:<line>`.
     *
     * ⚑ The predicate is exactly that — the literal token `$mapping` somewhere on the call
     * line, not a type check the test cannot perform on source text. Every writeback caller
     * names the mapping `$mapping` (it arrives from `WritebackConfig::mappingFor()` under
     * that name in all four handlers), so the stated scope and the actual predicate agree:
     * this reds on an OMITTED mapping and on a differently-named one, and the failure
     * message says which fix each needs.
     *
     * @return list<string>
     */
    private static function collapseCallsWithoutMapping(string $source, string $file): array
    {
        $sites = [];
        foreach (SourceScan::codeLines($source) as $n => $line) {
            if (str_contains($line, 'CardCollapse::toSurvivor(') && ! str_contains($line, '$mapping')) {
                $sites[] = $file.':'.$n;
            }
        }

        return $sites;
    }
}
