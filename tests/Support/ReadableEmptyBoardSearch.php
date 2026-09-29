<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A `GET /tasks/search.json` stub for a board the writeback token CAN read and whose searched cards
 * are none: every search answers zero rows, except the membership control
 * (`KanbanClient::visibility` — `q=board_id=<N>`, `limit=1`), which reads back `$liveCards`.
 *
 * kanban's search answers a board the token's user is not a member of zero rows too, so a tool that
 * holds "no cards" to `App\Bridge\Tools\BoardMembershipControl` refuses a bare `['data' => []]` stub
 * (card#10856). This is the stub for a test whose subject is the empty answer on a readable board.
 */
final class ReadableEmptyBoardSearch
{
    /** @return \Closure(Request): PromiseInterface|Response */
    public static function stub(int $liveCards = 3): \Closure
    {
        return static fn (Request $request) => preg_match('#q=board_id=\d+&limit=1$#', urldecode($request->url())) === 1
            ? Http::response(['data' => [], 'meta' => ['total' => $liveCards]])
            : Http::response(['data' => []]);
    }
}
