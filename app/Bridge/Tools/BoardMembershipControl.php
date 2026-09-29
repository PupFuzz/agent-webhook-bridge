<?php

namespace App\Bridge\Tools;

use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\RequestException;

/**
 * Whether the writeback token's user may READ one board — the membership control `board_get_cards`
 * (DL-435 Decision 3), `board_search` (DL-437 Decision 12) and `board_my_cards` (card#10856, on its
 * own board and on its coordination board) ask before they report an answer an unreadable board would
 * also produce. One instance per board per call: the control is asked at most once for each.
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
