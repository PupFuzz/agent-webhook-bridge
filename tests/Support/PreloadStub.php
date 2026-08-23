<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * The `GET /boards/{id}/preload.json` stub body the writeback tests share.
 *
 * Extracted at the second real caller (canon #5, card#7300 review). `KanbanMoveCardHandlerTest`
 * and `BridgeCommandsTest` had each grown a private `fakePreload()` around the same shape, and
 * the two DIVERGED on the one field whose absence means something: `swimlanes`. That is not a
 * formatting difference — `WritebackBoardStateCheck` reads a missing `data.swimlanes` as
 * "the board carried no swimlane collection at all" (card#5698), a different verdict from an
 * empty one — so the axis is a PARAMETER here and never a default. `null` OMITS the key.
 *
 * ⚠ WHAT THIS IS NOT. It is not a model of any real board, and no caller may read it as one:
 * it carries exactly the stage ids its caller needs present for the stage-existence leg to run
 * its healthy arm. A case that needs an id to be MISSING stubs its own preload and registers
 * it AHEAD of this one — first match wins (`CLAUDE_GOTCHAS.md` G-020).
 *
 * The other `preload.json` stubs in `tests/` are deliberately shaped fixtures for what their
 * own test asserts (an empty stage list, a 500, a swimlane-only body, a float position) and are
 * not instances of this shape; collapsing them onto this builder would change what they
 * observe, which is the opposite of the point.
 */
final class PreloadStub
{
    /**
     * @param  array<int, int>  $stages  stage id => position, in the order the board reports them
     * @param  list<array<string, mixed>>|null  $swimlanes  null omits `data.swimlanes` entirely
     * @return array<string, mixed> an `Http::fake()` stub set of exactly one url
     */
    public static function stub(int $boardId, array $stages, ?array $swimlanes = null): array
    {
        $body = ['workflows' => [['stages' => array_map(
            static fn (int $id, int $position): array => ['id' => $id, 'position' => $position],
            array_keys($stages),
            array_values($stages),
        )]]];

        if ($swimlanes !== null) {
            $body['swimlanes'] = $swimlanes;
        }

        return ["*/boards/{$boardId}/preload.json" => Http::response(['data' => $body])];
    }
}
