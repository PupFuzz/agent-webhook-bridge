<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * kanban's `GET /api/v3/boards/{id}/status.json` — the read `App\Bridge\Tools\BoardMembershipControl`
 * asks (`KanbanClient::boardReadable`). kanban authorizes it on the board (`BoardsController::status`,
 * the `view` policy): 200 to a member, whatever the board holds, and 403 to a non-member.
 */
final class KanbanBoardStatus
{
    /** A member's answer: the board is readable, empty or not. */
    public static function readable(int $boardId = 10): PromiseInterface
    {
        return Http::response(['data' => [
            'id' => $boardId, 'status' => 'active', 'archived_at' => null,
            'trashed_at' => null, 'auto_delete_at' => null, 'auto_delete_in_days' => null,
        ]]);
    }

    /** A non-member's answer. */
    public static function forbidden(): PromiseInterface
    {
        return Http::response(['message' => 'This action is unauthorized.'], 403);
    }

    /** @return list<int> the board id of every status read sent so far, in order */
    public static function asked(): array
    {
        $ids = [];
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (preg_match('#/boards/(\d+)/status\.json$#', $request->url(), $m) === 1) {
                $ids[] = (int) $m[1];
            }
        }

        return $ids;
    }
}
