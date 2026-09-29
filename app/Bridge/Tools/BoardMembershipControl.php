<?php

namespace App\Bridge\Tools;

use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\RequestException;

/**
 * Whether the writeback token's user may READ one board — the membership control `board_get_cards`
 * (DL-435 Decision 3) and `board_search` (DL-437 Decision 12) ask before they report an answer an
 * unreadable board would also produce. One instance per board per call: the control is asked at
 * most once for each.
 *
 * ⛔ `board_my_cards` does NOT ask this (card#10856, considered and declined). It reads its OWN
 * board's structure (`boards/{id}/preload.json`) unconditionally, before any search — and the
 * COORDINATION board's structure too, whenever one is configured, though there only after that
 * board's own tag searches, still before any answer about it is formed. Both reads are
 * `view`-authorized the same way `status.json` is, so a non-member is already refused there.
 * Adding this control would be a second guard behind one that already fires first, paying an
 * extra request for no reachable case; see the class docblock's condition below, which is what
 * makes the guard reliable.
 *
 * ⛔ kanban's search floors to the caller's own boards and answers a NON-MEMBER zero rows at 200, not
 * an error, so "nothing on this board matched" and "this token cannot see this board" are one answer
 * to the search. Anything a search of this board returned, in the same call, IS the proof
 * ({@see proven}); only without one is the board asked, through its status read
 * ({@see KanbanClient::boardReadable}), which kanban authorizes on the board itself: `true` is a
 * member — an EMPTY board included, and one whose every card is archived — and `false` is kanban's
 * 403 to a non-member (or, on a trashed board, to anyone but its owner).
 */
final class BoardMembershipControl
{
    private ?bool $readable = null;

    public function __construct(private readonly KanbanClient $client, private readonly int $boardId) {}

    /** A search of this board in this call answered something: membership is shown, nothing is asked. */
    public function proven(): void
    {
        $this->readable = true;
    }

    /**
     * @throws RequestException the status read failed with anything but 403; the caller maps it as a
     *                          board-scoped read ({@see BoardReadRoute::BoardScoped})
     */
    public function readable(): bool
    {
        return $this->readable ??= $this->client->boardReadable($this->boardId);
    }
}
