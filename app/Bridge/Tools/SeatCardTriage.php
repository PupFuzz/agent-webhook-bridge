<?php

namespace App\Bridge\Tools;

/**
 * THE SEAT'S TRIAGE VIEW (card#11268, DL-464): the seat's cards in ONE order, and the cut that
 * bounds them. `board_my_cards` builds it over the seat's own cards ({@see SeatCardScope}) and
 * nothing else — the shared lane, `tag_cards` and the coord cards keep DL-365's newest-id cut.
 *
 * ⭐ THE ORDER: top tier, then every other card in {@see BoardCardRank}'s order (In Progress, the
 * pull columns, the rest, the finished columns; within a column `position`, then id).
 *
 * ⭐ TOP TIER = kanban `priority === 1` (High) in a column that is not finished. kanban's field is
 * -1 Low / 0 Normal / 1 High, so it is compared strictly: a truthiness test would put every LOW card
 * on top. A High card already in a finished column is not live work, and leaving it in would also
 * make the never-cut set grow with every shipped High card (DL-464 records the basis). A row whose
 * `priority` is not an integer is read as not top tier and COUNTED in `priorityUnread`, so "no High
 * cards" and "the board sent no priority" do not read alike.
 *
 * ⭐ THE CUT, only when the cards number more than `limit` (the operator's decision, 2026-10-05):
 *  1. every top-tier card is kept, and counts against `limit`;
 *  2. round 1 gives each non-empty column one slot, in column order, while the budget lasts;
 *  3. later rounds deal one slot at a time to the UNFINISHED columns only — so a finished column
 *     shows at most one card, and budget they cannot use is not spent;
 *  4. an unfinished column keeps its HEAD by the board's `(position, id)` — the PM's priority order —
 *     and a finished column its TAIL: kanban appends a card moved in without an index, and the
 *     writeback's move sends none, so the tail is what was just finished and the head is the
 *     oldest-shipped card, forever (DL-365 r1's defect). The kept cards are emitted in board order.
 * A list narrowed to one column by `stage` is exempt from rule 3's finished cap: the caller asked for
 * that column by name, and there is no live work in the list for it to crowd out.
 *
 * ⚠ So `returned` can exceed `limit` (top-tier cards are never cut) or fall short of it with cards
 * hidden (a finished column's one slot). `truncated` is therefore `returned < total`, never
 * `total > limit`.
 */
final class SeatCardTriage
{
    /**
     * @param  list<array<string, mixed>>  $kept  the cards returned, in triage order
     * @param  list<mixed>  $topTier  the ids of the kept top-tier cards, in triage order
     * @param  list<array{stage_id: ?int, total: int, returned: int}>  $perStage  every column the cards sit in, in column order
     */
    private function __construct(
        public readonly array $kept,
        public readonly array $topTier,
        public readonly int $priorityUnread,
        public readonly array $perStage,
        public readonly int $total,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  the seat's cards, after `stage`
     * @param  bool  $narrowed  whether `stage` narrowed the list to one column
     */
    public static function cut(array $rows, BoardCardRank $rank, int $limit, bool $narrowed): self
    {
        $top = [];
        $priorityUnread = 0;
        /** @var array<int|string, array{stage_id: ?int, finished: bool, top: int, rows: list<array<string, mixed>>}> $columns keyed by stage id, `none` for no stage */
        $columns = [];
        foreach ($rank->sort($rows) as $row) {
            $stage = is_numeric($row['workflow_stage_id'] ?? null) ? (int) $row['workflow_stage_id'] : null;
            $key = $stage ?? 'none';
            $columns[$key] ??= ['stage_id' => $stage, 'finished' => $stage !== null && $rank->isFinished($stage), 'top' => 0, 'rows' => []];
            $priority = $row['priority'] ?? null;
            if (! is_int($priority)) {
                $priorityUnread++;
            }
            if ($priority === 1 && ! $columns[$key]['finished']) {
                $top[] = $row;
                $columns[$key]['top']++;
            } else {
                $columns[$key]['rows'][] = $row;
            }
        }

        $total = count($rows);
        $take = $total <= $limit
            ? array_map(static fn (array $column): int => count($column['rows']), $columns)
            : self::deal($columns, max(0, $limit - count($top)), ! $narrowed);

        $kept = $top;
        $perStage = [];
        foreach ($columns as $key => $column) {
            array_push($kept, ...($column['finished']
                ? array_slice($column['rows'], count($column['rows']) - $take[$key])
                : array_slice($column['rows'], 0, $take[$key])));
            $perStage[] = ['stage_id' => $column['stage_id'], 'total' => $column['top'] + count($column['rows']), 'returned' => $column['top'] + $take[$key]];
        }

        return new self($kept, array_map(static fn (array $row): mixed => $row['id'] ?? null, $top), $priorityUnread, $perStage, $total);
    }

    /**
     * How many of each column's non-top-tier cards survive: rules 2 and 3 of the class docblock.
     *
     * @param  array<int|string, array{stage_id: ?int, finished: bool, top: int, rows: list<array<string, mixed>>}>  $columns
     * @return array<int|string, int>
     */
    private static function deal(array $columns, int $budget, bool $capFinished): array
    {
        $take = array_fill_keys(array_keys($columns), 0);
        foreach ($columns as $key => $column) {
            if ($budget === 0) {
                return $take;
            }
            if ($column['rows'] !== []) {
                $take[$key] = 1;
                $budget--;
            }
        }

        do {
            $dealt = false;
            foreach ($columns as $key => $column) {
                if ($budget === 0) {
                    return $take;
                }
                if (($capFinished && $column['finished']) || $take[$key] >= count($column['rows'])) {
                    continue;
                }
                $take[$key]++;
                $budget--;
                $dealt = true;
            }
        } while ($dealt);

        return $take;
    }

    /**
     * The `triage` block: the returned cards' ids in triage order, the top-tier subset, and the
     * count of cards whose `priority` could not be read.
     *
     * @return array{order: list<mixed>, top_tier: list<mixed>, priority_unread: int}
     */
    public function block(): array
    {
        return [
            'order' => array_map(static fn (array $row): mixed => $row['id'] ?? null, $this->kept),
            'top_tier' => $this->topTier,
            'priority_unread' => $this->priorityUnread,
        ];
    }

    public function truncated(): bool
    {
        return count($this->kept) < $this->total;
    }
}
