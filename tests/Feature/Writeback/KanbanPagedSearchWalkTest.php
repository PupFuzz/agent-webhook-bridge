<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\BoardReadRefused;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Support\Facades\Log;
use Tests\Support\KanbanSearchSim;
use Tests\TestCase;

/**
 * card#10653: `KanbanClient::pagedSearch` against a board that is WRITTEN TO during the walk. The
 * property every case asserts is the one the walk promises: a read reported complete
 * (`truncated: false`) holds every card that was on the board for the whole walk. A card created,
 * restored or archived during the walk may or may not be in it; that is not asserted.
 *
 * Each case is built so the pre-card#10653 `page=N` walk answered it wrongly; the controls
 * (`…_control`) pin that the keyed walk does not narrow what a quiet board reads as.
 */
class KanbanPagedSearchWalkTest extends TestCase
{
    private const LIMIT = KanbanClient::SEARCH_LIMIT;

    private function client(): KanbanClient
    {
        return new KanbanClient('https://kanban.example.com/api/v3', 'wb-token', 'scan');
    }

    /**
     * @param  array{cards: list<array<string, mixed>>, truncated: bool}  $read
     * @param  list<int>  $wholeWalk
     */
    private function assertCompleteOver(array $read, array $wholeWalk): void
    {
        $this->assertFalse($read['truncated'], 'the walk reported a ceiling it did not reach');
        $got = array_map(fn (array $row) => $row['id'], $read['cards']);
        $this->assertSame([], array_values(array_diff($wholeWalk, $got)), 'a read reported complete is missing a card that was on the board for the whole walk');
        $this->assertSame(count($got), count(array_unique($got)), 'a card was delivered twice');
    }

    /**
     * Three pages. Before request 2 a card already read (450) is archived, which under `page=N`
     * moves every unread card back one place, so the first card of page 2 (300) is on no page.
     * Before request 3 a card is created at the head (501), which moves them forward again, so
     * page 3 repeats a card. The count comes back even, and nothing the old walk looked at moved.
     * ⚑ RED on the `page=N` walk: card 300 missing.
     */
    public function test_dup_and_loss_across_three_pages_loses_no_card_live_for_the_whole_walk(): void
    {
        $sim = (new KanbanSearchSim(range(1, 500)))
            ->before(2, fn (KanbanSearchSim $s) => $s->archive(450))
            ->before(3, fn (KanbanSearchSim $s) => $s->restore(501))
            ->install();

        $read = $this->client()->readBoardCards(8);

        $this->assertCompleteOver($read, array_values(array_diff(range(1, 500), [450])));
    }

    /**
     * A restored card keeps its old id, so it lands MID-list, not at the head. Here it lands in the
     * part not read yet while a card already read is archived: the `page=N` walk loses the first
     * card of page 2 (300) and the restored card makes the count whole again, so a census alone
     * could not see it. ⚑ RED on the `page=N` walk: card 300 missing.
     */
    public function test_a_restore_landing_mid_list_does_not_cover_for_a_lost_card(): void
    {
        $sim = (new KanbanSearchSim(array_diff(range(1, 500), [150])))
            ->before(2, function (KanbanSearchSim $s) {
                $s->archive(450);
                $s->restore(150);
            })
            ->install();

        $read = $this->client()->readBoardCards(8);

        $this->assertCompleteOver($read, array_values(array_diff(range(1, 500), [150, 450])));
    }

    /**
     * The server's COUNT and SELECT are two queries. Request 2's window holds exactly 200 cards at
     * COUNT time, so `links.next` says it is the last page; a restore below the cursor lands before
     * the SELECT, which then returns 200 rows and drops the LOWEST — card 1, on the board all along.
     * Only a walk that never ends on a full page asks again. ⚑ RED on the `page=N` walk (it
     * trusts `links.next`): card 1 missing.
     */
    public function test_a_full_page_is_never_trusted_as_the_last_so_a_count_select_race_loses_nothing(): void
    {
        $sim = (new KanbanSearchSim(array_diff(range(1, 401), [150])))
            ->race(2, fn (KanbanSearchSim $s) => $s->restore(150))
            ->install();

        $read = $this->client()->readBoardCards(8);

        $this->assertCompleteOver($read, array_values(array_diff(range(1, 401), [150])));
        $this->assertSame(3, $sim->requests, 'the full second page costs one more request, and only one');
    }

    /**
     * Every request after the first is page 1 of `id<C`, C the lowest id read so far; page 1 is the
     * request the walk has always sent, byte for byte.
     */
    public function test_every_request_after_the_first_is_keyed_below_the_lowest_id_read(): void
    {
        $sim = (new KanbanSearchSim(range(1, 450)))->install();

        $this->client()->tagRowsRead(8, 'lane:A');

        $this->assertSame(['board_id=8 tags:"lane:A"', 'board_id=8 id<251 tags:"lane:A"', 'board_id=8 id<51 tags:"lane:A"'], array_column($sim->queries, 'q'));
        $this->assertSame(['1', '1', '1'], array_column($sim->queries, 'page'));
        $this->assertSame([(string) self::LIMIT, (string) self::LIMIT, (string) self::LIMIT], array_column($sim->queries, 'limit'));
    }

    /**
     * A board of EXACTLY MAX_PAGES × SEARCH_LIMIT cards ends on a full page at the ceiling. The walk
     * makes one confirming request past it; an empty answer means the board ended there, and the
     * read is complete, as it was before card#10653.
     */
    public function test_exact_max_pages_boundary_on_a_quiet_board_is_complete_control(): void
    {
        $sim = (new KanbanSearchSim(range(1, KanbanClient::MAX_PAGES * self::LIMIT)))->install();

        $read = $this->client()->readBoardCards(8);

        $this->assertCompleteOver($read, range(1, KanbanClient::MAX_PAGES * self::LIMIT));
        $this->assertSame(KanbanClient::MAX_PAGES + 1, $sim->requests);
    }

    /**
     * The same boundary with the COUNT/SELECT race on the LAST page: its COUNT says 200 (last page),
     * a restore below the cursor lands before the SELECT, and card 1 falls off it. The board now
     * holds more than the ceiling, so the only honest answers are "truncated" or a read holding
     * card 1. ⚑ RED on the `page=N` walk: `truncated: false` without card 1.
     */
    public function test_exact_max_pages_boundary_with_a_race_on_the_last_page_is_not_reported_complete(): void
    {
        $sim = (new KanbanSearchSim(array_diff(range(1, KanbanClient::MAX_PAGES * self::LIMIT + 1), [100])))
            ->race(KanbanClient::MAX_PAGES, fn (KanbanSearchSim $s) => $s->restore(100))
            ->install();

        $read = $this->client()->readBoardCards(8);

        $this->assertTrue($read['truncated']);
        $this->assertCount(KanbanClient::MAX_PAGES * self::LIMIT, $read['cards'], 'the confirming page is evidence, not rows');
    }

    /** One card past the ceiling on a quiet board: truncated, and the rows are the first MAX_PAGES pages. */
    public function test_max_pages_plus_one_on_a_quiet_board_is_truncated_control(): void
    {
        $sim = (new KanbanSearchSim(range(1, KanbanClient::MAX_PAGES * self::LIMIT + 1)))->install();

        $read = $this->client()->readBoardCards(8);

        $this->assertTrue($read['truncated']);
        $this->assertCount(KanbanClient::MAX_PAGES * self::LIMIT, $read['cards']);
        $this->assertSame(KanbanClient::MAX_PAGES + 1, $sim->requests);
    }

    /**
     * MAX_PAGES + 1 cards, and a card already read is archived before request 2. Under `page=N` the
     * COUNT drops to exactly the ceiling, `links.next` is null on page MAX_PAGES, and the walk calls
     * itself complete while the card that shifted across the first boundary is on no page.
     * ⚑ RED on the `page=N` walk: `truncated: false`.
     */
    public function test_max_pages_plus_one_with_an_archive_mid_walk_is_not_reported_complete(): void
    {
        $top = KanbanClient::MAX_PAGES * self::LIMIT + 1;
        (new KanbanSearchSim(range(1, $top)))
            ->before(2, fn (KanbanSearchSim $s) => $s->archive($top - 5))
            ->install();

        $read = $this->client()->readBoardCards(8);

        $this->assertTrue($read['truncated']);
        $this->assertCount(KanbanClient::MAX_PAGES * self::LIMIT, $read['cards']);
    }

    /**
     * The cursor is a page's last row only if the page is in descending id order. A server answering
     * ascending would make `id<last` skip every row between — refused, never read as complete.
     * ⚑ RED on the `page=N` walk: it answers complete.
     */
    public function test_a_page_out_of_descending_id_order_is_refused(): void
    {
        (new KanbanSearchSim(range(1, 450), order: 'asc'))->install();
        Log::spy();

        try {
            $this->client()->readBoardCards(8);
            $this->fail('a page the walk cannot key from was read as complete');
        } catch (BoardReadRefused $e) {
            $this->assertStringContainsString('descending id order', $e->getMessage());
        }
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => ($c['catalog_id'] ?? null) === 'kanban_client.board_read_refused');
    }

    /**
     * A full page whose LAST row carries no integer id cannot key the next request: the cursor would
     * come from an earlier row, and `id<` it would deliver the trailing rows again. Refused, like the
     * order and window breaks. ⚑ RED before the guard: the walk answered complete, delivering row 251
     * twice.
     */
    public function test_a_full_page_whose_last_row_cannot_key_the_walk_is_refused(): void
    {
        (new KanbanSearchSim(range(1, 450)))->put(['id' => '251', 'name' => 'id is a string'], 251)->install();

        $this->expectException(BoardReadRefused::class);
        $this->expectExceptionMessage('last row carries no integer id');

        $this->client()->readBoardCards(8);
    }

    /** A keyed page carrying a row at or above its cursor: the server did not apply `id<`. ⚑ RED on the `page=N` walk. */
    public function test_a_keyed_page_outside_its_id_window_is_refused(): void
    {
        (new KanbanSearchSim(range(1, 450), idFilter: 'ignore'))->install();

        $this->expectException(BoardReadRefused::class);
        $this->expectExceptionMessage('id<251');

        $this->client()->readBoardCards(8);
    }

    /**
     * A server that free-texts `id<N` answers a keyed request with nothing, which the window check
     * cannot see and a short page would end on. The total is the cross-check: page 1 declared more
     * than the walk delivered, twice, so the read is refused. ⚑ RED on the `page=N` walk, which
     * never sends the token and so reads this board completely — the refusal is new, and is listed
     * as one.
     */
    public function test_a_walk_short_of_page_ones_total_twice_is_refused(): void
    {
        $sim = (new KanbanSearchSim(range(1, 450), idFilter: 'free_text'))->install();

        try {
            $this->client()->readBoardCards(8);
            $this->fail('a walk that delivered fewer cards than page 1 declared was read as complete');
        } catch (BoardReadRefused $e) {
            $this->assertStringContainsString('450', $e->getMessage());
        }
        $this->assertSame(4, $sim->requests, 'two whole walks, each ended by an empty keyed page');
    }

    /**
     * An archive in the part NOT yet read leaves the walk one short of page 1's total — the read is
     * right, and the count cannot tell it from a lost card. One re-walk settles it: the second walk's
     * page 1 counts the board as it now is.
     */
    public function test_a_benign_shortfall_is_settled_by_one_re_walk(): void
    {
        $sim = (new KanbanSearchSim(range(1, 450)))
            ->before(2, fn (KanbanSearchSim $s) => $s->archive(10))
            ->install();

        $read = $this->client()->readBoardCards(8);

        $this->assertCompleteOver($read, array_values(array_diff(range(1, 450), [10])));
        $this->assertSame(6, $sim->requests);
    }
}
