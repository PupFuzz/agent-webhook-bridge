<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A kanban board for `board_my_cards`' `tag` read (card#9260), answering each search the way
 * kanban's own parser would — including the parser that predates `swimlane_id=none`.
 *
 * Board 10: Backlog (50), In Review (51), Shipped (52, lane type `done`); lanes 4 (the seat's)
 * and 9. The lane read answers `$laneRows`; the tag read answers `$tagRows`; a one-row count
 * search answers the number of `$tagRows` its own `swimlane_id=` / `workflow_stage_id=` terms
 * select, so a count the tool reports is one a real server would have given for that query.
 *
 * Options:
 *  - `parser` — `current` (kanban v0.45.0+); `pre-none` (v0.43.0–v0.44.x): `swimlane_id=none`
 *    falls through to free text, discloses `match_mode`, and answers `pre_none_total`; or
 *    `pre-disclosure` (v0.36.0–v0.42.x): the same fall-through, and NO search ever discloses
 *    `match_mode`. A `q` of the board scope and one bare word is free text to every parser.
 *  - `swimlanes` — the board's lane ids, or null for a preload body with no swimlane collection.
 *  - `count_meta_total` — false for a count response whose `meta` carries no `total`.
 *  - `none_total` — an int a server that honours `swimlane_id=none` answers for it instead of the
 *    rows' own count (a card moved between the requests, say).
 *  - `refuse` — `[read => status]`: the board answers that status, with a body the seat must never
 *    see, on `preload`, the `tag` row read, the `other` count, the free-text `probe` or the `none`
 *    count.
 */
trait TaggedBoardFake
{
    /**
     * @param  list<array<string, mixed>>  $laneRows
     * @param  list<array<string, mixed>>  $tagRows
     * @param  array<string, mixed>  $options
     */
    private function fakeTaggedBoard(array $laneRows, array $tagRows, array $options = []): void
    {
        $options += ['parser' => 'current', 'pre_none_total' => 0, 'swimlanes' => [4, 9], 'count_meta_total' => true, 'none_total' => null, 'refuse' => []];
        $refused = static fn (string $read) => isset($options['refuse'][$read]) ? Http::response('the board said something', $options['refuse'][$read]) : null;

        $preload = ['workflows' => [['stages' => [
            ['id' => 50, 'name' => 'Backlog', 'position' => 1, 'lane_type' => 'backlog_inventory'],
            ['id' => 51, 'name' => 'In Review', 'position' => 2, 'lane_type' => 'in_progress'],
            ['id' => 52, 'name' => 'Shipped', 'position' => 3, 'lane_type' => 'done'],
        ]]]];
        if ($options['swimlanes'] !== null) {
            $preload['swimlanes'] = array_map(static fn (int $id): array => ['id' => $id, 'name' => "lane {$id}"], $options['swimlanes']);
        }

        Http::fake([
            '*/boards/10/preload.json' => $refused('preload') ?? Http::response(['data' => $preload]),
            '*/tasks/search.json*' => function (Request $request) use ($laneRows, $tagRows, $options, $refused) {
                $query = self::searchQuery($request);
                $q = is_string($query['q'] ?? null) ? $query['q'] : '';
                if (preg_match('/^board_id=\d+ [^=:"]+$/', $q) === 1) {
                    $disclosure = $options['parser'] === 'pre-disclosure' ? [] : ['match_mode' => 'fulltext_prefix_and', 'fallback_tokens' => [], 'dropped_stopwords' => [], 'dropped_oversize_tokens' => []];

                    return $refused('probe') ?? Http::response(['data' => [], 'links' => ['next' => null], 'meta' => ['total' => 0] + $disclosure]);
                }
                if (! str_contains($q, 'tags:"')) {
                    return Http::response(['data' => $laneRows, 'links' => ['next' => null], 'meta' => ['total' => count($laneRows)]]);
                }
                if (($query['limit'] ?? null) !== '1') {
                    return $refused('tag') ?? Http::response(['data' => $tagRows, 'links' => ['next' => null], 'meta' => ['total' => count($tagRows)]]);
                }

                return $refused(str_contains($q, 'swimlane_id=none') ? 'none' : 'other')
                    ?? Http::response(['data' => [], 'links' => ['next' => null], 'meta' => self::countMeta($q, $tagRows, $options)]);
            },
        ]);
    }

    /** @return array<string, mixed> */
    private static function searchQuery(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @param  list<array<string, mixed>>  $tagRows
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function countMeta(string $q, array $tagRows, array $options): array
    {
        preg_match('/(?:^| )swimlane_id=(\S+)/', $q, $lane);
        $lanes = explode(',', $lane[1] ?? '');
        preg_match('/(?:^| )workflow_stage_id=(\S+)/', $q, $stage);
        $stages = isset($stage[1]) ? array_map('intval', explode(',', $stage[1])) : null;

        if (in_array('none', $lanes, true) && $options['parser'] === 'pre-none') {
            return ['total' => $options['pre_none_total'], 'match_mode' => 'substring_and', 'fallback_tokens' => ['id'], 'dropped_stopwords' => [], 'dropped_oversize_tokens' => []];
        }
        if (in_array('none', $lanes, true) && $options['parser'] === 'pre-disclosure') {
            return ['total' => $options['pre_none_total']];
        }
        if (in_array('none', $lanes, true) && $options['none_total'] !== null) {
            return ['total' => $options['none_total']];
        }

        $total = 0;
        foreach ($tagRows as $row) {
            $inLane = array_key_exists('swimlane_id', $row) && (
                ($row['swimlane_id'] === null && in_array('none', $lanes, true))
                || (is_int($row['swimlane_id']) && in_array((string) $row['swimlane_id'], $lanes, true))
            );
            $onStage = $stages === null || in_array($row['workflow_stage_id'] ?? null, $stages, true);
            if ($inLane && $onStage) {
                $total++;
            }
        }

        return $options['count_meta_total'] ? ['total' => $total] : ['per_page' => 1];
    }

    /**
     * One live card row on board 10 carrying `lane:A`. A `$swimlane` of false leaves the row's
     * `swimlane_id` key OUT, which is a different answer from null.
     *
     * @return array<string, mixed>
     */
    private static function taggedRow(int $id, int $stage, int|null|false $swimlane, array $tags = ['lane:A']): array
    {
        $row = ['id' => $id, 'name' => "card {$id}", 'workflow_stage_id' => $stage, 'board_id' => 10,
            'tags' => $tags, 'payload' => [], 'updated_at' => '2026-09-10', 'assigned_user_id' => null];
        if ($swimlane !== false) {
            $row['swimlane_id'] = $swimlane;
        }

        return $row;
    }

    /** @return list<string> the `q` of every search this test sent, in order */
    private static function sentSearches(): array
    {
        $qs = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/tasks/search.json')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $qs[] = (is_string($query['q'] ?? null) ? $query['q'] : '').(($query['limit'] ?? null) === '1' ? ' [count]' : '');
            }
        }

        return $qs;
    }
}
