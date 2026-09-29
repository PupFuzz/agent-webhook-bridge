<?php

namespace App\Bridge\Tools;

use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\RequestException;

/**
 * Whether the writeback token's user can READ one board through kanban's search — the membership
 * control every tool on this door asks before it reports an answer that an unreadable board would
 * also produce (DL-435 Decision 3, DL-437). One instance per call: the control is asked at most once.
 *
 * ⛔ kanban's search floors to the caller's own boards and answers a NON-MEMBER zero rows at 200, not
 * an error, so "nothing on this board matched" and "this token cannot see this board" are one answer
 * to the search. Anything a search of this board returned, in the same call, IS the proof
 * ({@see proven}); only without one is the board asked ({@see KanbanClient::visibility}, `limit=1`).
 * The control cannot tell an EMPTY board from an unreadable one either — both read back zero, as does
 * a board whose every card is archived (it counts live cards) — so a caller treats `false` as
 * "cannot say", never as "not a member".
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

    /** @throws RequestException the control's own read failed; the caller maps it like any other read */
    public function readable(): bool
    {
        return $this->readable ??= $this->client->visibility($this->boardId)['total'] > 0;
    }
}
