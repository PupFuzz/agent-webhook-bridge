<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The `GET /tasks/search.json?q=board_id=<b> id=<n>` stub for the board-scoped tenant check
 * every token-resolved writeback move now makes BEFORE it reads the card (card#8375).
 *
 * ⚑ IT IS A FALLBACK, AND THE REGISTRATION ORDER IS WHAT MAKES IT ONE. `Http::fake()` merges
 * stubs and the FIRST matching callback wins (`CLAUDE_GOTCHAS.md` G-020), so a test that
 * stubs the search itself — the refusal cases, whose whole subject is what this lookup
 * answers — registers ahead of this and this never runs. What it covers is the ~130 legs
 * whose subject is something else entirely (the move, the pin, the stamp, the note) and for
 * which "the card is on the mapped board" is a fixture, not an assertion.
 *
 * ⛔ IT IS NOT A CATCH-ALL, deliberately: it answers this one endpoint and nothing else, so
 * the stray-request guard keeps working on every other url a leg reaches. And it is
 * registered from the dispatch helper rather than from `setUp()` for the same
 * ordering reason — from `setUp()` it would be registered FIRST and would then shadow the
 * per-test stubs the refusal legs depend on.
 *
 * ⚠ THE BODY IS A CONSISTENT FIXTURE, NOT AN ASSERTION. It says the card id asked for is on
 * the board asked for, which is what an ordinary leg means. A leg whose card is deliberately
 * on ANOTHER board (the belongs-to-mapped-board refusal) still gets this answer for the
 * scoped read and its own `/tasks/{id}.json` body for the card — the two disagree on purpose,
 * because that leg's subject is the POST-read compare, which is the one this cannot exercise.
 */
final class ScopeLookupStub
{
    /**
     * "Whatever card id you asked about is on the mapped board."
     *
     * ⚑ The answer is DERIVED FROM THE REQUEST rather than pinned to one id, because a leg
     * that moves two cards in one delivery (a bundled DL) asks about each in turn — a fixed
     * body would answer the second lookup with the FIRST card's row, which the guard reads
     * (correctly) as an answer about some other card and refuses. The derivation reads the
     * `id=` term out of the `q` string, with a lookbehind so `board_id=` cannot supply it.
     *
     * @return array<string, mixed> an `Http::fake()` stub set of exactly one endpoint
     */
    public static function onMappedBoard(int $boardId): array
    {
        return ['*/tasks/search.json*' => function (Request $request) use ($boardId) {
            $matched = preg_match('/(?<![a-z_])id=(\\d+)/', urldecode($request->url()), $m) === 1;

            return Http::response(['data' => $matched ? [['id' => (int) $m[1], 'board_id' => $boardId]] : []]);
        }];
    }

    /**
     * The other answer: the board-scoped lookup finds nothing. Both sides of the archive
     * switch answer empty, and so does the `visibility()` control — which makes this the
     * "board unreadable / empty" verdict unless the caller stubs the control ahead of it.
     *
     * @return array<string, mixed>
     */
    public static function notOnMappedBoard(): array
    {
        return ['*/tasks/search.json*' => Http::response(['data' => [], 'meta' => ['total' => 0]])];
    }
}
