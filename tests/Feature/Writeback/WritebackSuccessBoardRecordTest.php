<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\MappedBoardGuard;
use App\Bridge\Writeback\WritebackMapping;
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
 * WHAT THIS CLASS DOES AND DOES NOT COVER. The per-arm BEHAVIOURAL legs — a successful
 * write emits `card_board` + `mapped_board`, equal on the happy path, and DIVERGENT when
 * the resolved card is on another board — live beside the handlers they exercise, in
 * `tests/Feature/Handlers/Kanban*HandlerTest`. Each divergence leg was seen to fail
 * against a mutant rendering `mapped_board` into both slots, which is the defect wearing
 * the new field's name. This class carries the two STRUCTURAL legs those cannot be:
 *
 *  1. {@see test_every_kanban_write_in_a_writeback_handler_is_accounted_for} — the write
 *     census. Fixing N sites without a guard leaves the N+1th to mint the bug again
 *     (canon #7), and the N+1th here is a NEW write, which no behavioural test can
 *     anticipate. Keyed `<file>:<verb>` with a COUNT rather than by line, so an unrelated
 *     edit above a call site does not red and a genuinely new call does.
 *  2. {@see test_the_board_pair_has_exactly_one_rendering} — nobody spells the pair by
 *     hand. Two renderings of one record is how the refusal and success arms came to
 *     disagree in the first place (canon #5); `MappedBoardGuard::boardContext()` is the
 *     single owner and both arms route through it.
 *
 * STATED BOUND, and it is a real one: leg 1 proves each write site was CONSIDERED, not
 * that its record is correct — that is leg 2's and the behavioural legs' job. And the
 * population is the handler glob, so a write minted in a Console command or a Tool
 * (`bin/`, `app/Console/`, `app/Bridge/Tools/`) is outside it; `CardCollapse` archives
 * from a shared primitive with no mapping in scope and is recorded as a remainder on
 * card#7212 rather than silently included or silently dropped.
 */
class WritebackSuccessBoardRecordTest extends TestCase
{
    /**
     * Every kanban WRITE reachable from a writeback handler, keyed `<file>:<verb>` with the
     * number of call sites, and the reason its success record does or does not carry the
     * (card board, mapped board) pair. Set equality in both directions AND on the counts:
     * a new write reds, and so does an entry whose call site has gone.
     *
     * @var array<string, array{sites: int, record: string}>
     */
    private const ACCOUNTED = [
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
            'record' => 'PAIRED — a GROUP-B site (card#7211): the id came from a board-scoped search with no '
                .'membership compare, so the card row is the only reading of where the write landed.',
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
                .'two calls later, where the row is out of scope. Carried, never re-read.',
        ],
    ];

    /** The KanbanClient methods that WRITE. A read cannot land on the wrong board. */
    private const WRITE_VERBS = ['moveCard', 'stampCorrelationRefs', 'setBlockReason', 'addComment', 'archiveCard', 'createCard'];

    public function test_every_kanban_write_in_a_writeback_handler_is_accounted_for(): void
    {
        $files = glob(base_path('app/Bridge/Handlers/Kanban*Handler.php')) ?: [];
        $this->assertGreaterThanOrEqual(6, count($files), 'the handler population came back short — the glob, not the code, is what changed');

        $found = [];
        foreach ($files as $file) {
            foreach (self::writeSites((string) file_get_contents($file), basename($file)) as $key => $count) {
                $found[$key] = ($found[$key] ?? 0) + $count;
            }
        }

        $expected = array_map(fn (array $e): int => $e['sites'], self::ACCOUNTED);
        ksort($expected);
        ksort($found);

        $this->assertSame(
            $expected,
            $found,
            'a kanban WRITE in a writeback handler is not accounted for. Every write to a card whose id was '
            .'RESOLVED from kanban must log the pair MappedBoardGuard::boardContext() renders, so a write that '
            .'lands on an out-of-mapping card is distinguishable from a correct one after the fact (card#7212). '
            .'Add the site to ACCOUNTED with what its success record carries — or with the reason it carries no '
            .'card board, which for a CREATE is that no resolved card exists.',
        );
    }

    public function test_the_write_scanner_discriminates_a_real_call_from_a_comment(): void
    {
        // The census expects an exact map, so a scanner that had stopped matching would red
        // loudly rather than silently — but one that matched PROSE would report writes that
        // are not there, and force a bogus ACCOUNTED entry. Both directions, known answer.
        $source = <<<'PHP'
        <?php
        // A comment mentioning $client->moveCard( and $client->archiveCard(.
        /** A docblock naming $kanban->createCard( too. */
        $client->moveCard($id, $stage);
        $kanban->moveCard($cardId, $released);
                $client->archiveCard($cardId);
        $card = $client->getCard($id);
        $ids = $client->cardsByTag($boardId, $tag);
        PHP;

        $this->assertSame(
            ['Fixture.php:archiveCard' => 1, 'Fixture.php:moveCard' => 2],
            self::writeSites($source, 'Fixture.php'),
        );
    }

    /**
     * Every kanban write call in $source, keyed `<file>:<verb>` => count. Comment lines are
     * skipped (the handlers discuss their own writes at length in prose).
     *
     * @return array<string, int>
     */
    private static function writeSites(string $source, string $file): array
    {
        $sites = [];
        foreach (explode("\n", $source) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            foreach (self::WRITE_VERBS as $verb) {
                // `->` only: `$this->stampCorrelationRefs(` is the handler's own private
                // helper, not the client write it wraps, and would double-count it.
                $sites[$file.':'.$verb] = ($sites[$file.':'.$verb] ?? 0)
                    + preg_match_all('/\$(?:client|kanban)->'.$verb.'\(/', $line);
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
        $files = glob(base_path('app/Bridge/Handlers/Kanban*Handler.php')) ?: [];
        $this->assertGreaterThanOrEqual(6, count($files), 'the handler population came back short — the glob, not the code, is what changed');

        $found = [];
        foreach ($files as $file) {
            foreach (self::handRolledPairSites((string) file_get_contents($file), basename($file)) as $site) {
                $found[] = $site;
            }
        }

        $this->assertSame(
            [],
            $found,
            'a writeback handler renders `card_board` / `mapped_board` itself instead of calling '
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
        foreach (explode("\n", $source) as $i => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            if (preg_match('/[\'"](?:card_board|mapped_board)[\'"]/', $line) === 1) {
                $sites[] = $file.':'.($i + 1);
            }
        }

        return $sites;
    }
}
