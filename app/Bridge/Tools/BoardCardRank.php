<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Writeback\BoardStructure;
use App\Bridge\Writeback\FinishedStages;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Support\Facades\Log;

/**
 * THE ORDER A SEAT'S CARDS ARE LISTED IN (card#11267, rt#595): stage rank, then the board's
 * `position`, then card id — ACROSS LANES. Once a seat's cards span lanes, grouping by lane first
 * would put every home-lane card above every assigned card in another lane, whatever the PM
 * ranked; this order puts each where the PM's reorder put it.
 *
 * ⭐ STAGE RANK IS WHAT THE BRIDGE ALREADY DECLARES ABOUT THE BOARD'S COLUMNS, never a guess from
 * a column's name: the In Progress column, then the columns work is pulled from (both the
 * writeback.json mappings', through {@see StartColumns} — the same reading `board_take_card`'s
 * start form uses), then every other column, then the FINISHED ones ({@see FinishedStages}: the
 * board's terminal declaration united with the mappings' terminal floor). Columns of one rank keep
 * the board's column order. Where no mapping names one In Progress column, nothing is ranked
 * first and {@see $unmappedReason} says why.
 *
 * ⛔ NOT kanban's per-stage `lane_type`. kanban creates every stage `in_progress` unless told
 * otherwise, and the reference board types "Shipped to dev" `waiting` — a rank read off it would
 * list a shipped card above the backlog.
 *
 * ⭐ `position` IS ONE ORDER PER STAGE ACROSS EVERY SWIMLANE on kanban: its reorder places a card
 * stage-wide, not per (stage, lane) cell (kanban DL-284, `BoardPositionService::placeInStage`), and
 * kanban reads a stage in `(position, id)` order. Per the operator's ruling (rt#552) that order IS
 * the priority order — {@see BoardCardProjection::withPosition}. `id` breaks ties because two cards
 * can share a position.
 */
final class BoardCardRank
{
    private const IN_PROGRESS = 0;

    private const PULL = 1;

    private const OTHER = 2;

    private const FINISHED = 3;

    /**
     * @param  list<int>  $pull
     * @param  list<WritebackMapping>  $mappings
     */
    private function __construct(
        private readonly BoardStructure $structure,
        public readonly ?int $inProgress,
        public readonly array $pull,
        private readonly array $mappings,
        public readonly ?string $unmappedReason,
    ) {}

    /**
     * The rank for `$boardId`, from this install's writeback.json. Never refuses: a config that
     * will not parse ranks by column order alone and says so, because ordering a read is not a
     * reason to fail it.
     */
    public static function forBoard(BoardStructure $structure, int $boardId, string $agentName): self
    {
        try {
            $mappings = WritebackConfig::loadDefault()?->mappingsOnBoard($boardId) ?? [];
        } catch (ConfigException $e) {
            Log::warning('board_my_cards: writeback.json will not parse, so the In Progress and pull columns are unknown and the cards are ranked by column order', [
                'agent' => $agentName, 'board_id' => $boardId, 'error' => $e->getMessage(),
            ]);

            return new self($structure, null, [], [], 'writeback_config_unreadable');
        }

        $start = StartColumns::of($mappings);
        $reason = match (true) {
            $mappings === [] => 'no_mapping_on_board',
            $start->stages === [] => 'start_unmapped',
            count($start->stages) > 1 => 'start_ambiguous',
            default => null,
        };

        return new self($structure, $start->inProgress(), $start->from, $mappings, $reason);
    }

    /**
     * What the `stage_rank` block reports, so a caller can see which columns were ranked first.
     *
     * @return array{in_progress_stage_id: ?int, pull_stage_ids: list<int>, unmapped_reason: ?string}
     */
    public function block(): array
    {
        return [
            'in_progress_stage_id' => $this->inProgress,
            'pull_stage_ids' => $this->pull,
            'unmapped_reason' => $this->unmappedReason,
        ];
    }

    /**
     * Every stage the board read carried, in rank order — the order `cards_by_stage` is emitted in.
     *
     * @return list<int>
     */
    public function stageOrder(): array
    {
        $stages = array_values(array_unique([...array_keys($this->structure->stageNames), ...$this->structure->stageIds]));
        $column = array_flip($stages);
        usort($stages, fn (int $a, int $b): int => [$this->rank($a), $column[$a]] <=> [$this->rank($b), $column[$b]]);

        return $stages;
    }

    /**
     * The rows in (stage rank, position, id) order. A row on a stage the board read did not carry
     * sorts after every stage, grouped by its stage — in rank-tier order, then stage id, because the
     * tier needs only the id and the mappings, so a degraded structure read still lists In Progress
     * first — and a row with no numeric stage after those (card#11268: every reader of this order
     * sees one column order, the triage cut included). A row with no numeric `position` sorts after
     * every positioned row of its stage, and a row with no numeric id after the rows that share its
     * stage and position — none is dropped.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function sort(array $rows): array
    {
        $stageRank = array_flip($this->stageOrder());
        $after = count($stageRank);
        $key = function (array $row) use ($stageRank, $after): array {
            $stage = is_numeric($row['workflow_stage_id'] ?? null) ? (int) $row['workflow_stage_id'] : null;
            $carried = $stage !== null && isset($stageRank[$stage]);
            $position = $row['position'] ?? null;
            $positioned = is_int($position) || is_float($position);
            $id = $row['id'] ?? null;

            return [
                $carried ? $stageRank[$stage] : $after,
                $carried ? 0 : ($stage === null ? PHP_INT_MAX : $this->rank($stage)),
                $carried ? 0 : ($stage ?? PHP_INT_MAX),
                $positioned ? 0 : 1,
                $positioned ? (float) $position : 0.0,
                is_numeric($id) ? 0 : 1,
                is_numeric($id) ? (int) $id : 0,
            ];
        };

        usort($rows, fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $rows;
    }

    /**
     * Whether `$stageId` is a finished column — the one finished predicate the order, the triage
     * top tier and its cut all ask ({@see SeatCardTriage}). Asked of any stage id, carried by the
     * board read or not.
     */
    public function isFinished(int $stageId): bool
    {
        return $this->rank($stageId) === self::FINISHED;
    }

    private function rank(int $stageId): int
    {
        return match (true) {
            $stageId === $this->inProgress => self::IN_PROGRESS,
            FinishedStages::isFinished($stageId, $this->mappings, $this->structure, $this->structure->stagePositions) => self::FINISHED,
            in_array($stageId, $this->pull, true) => self::PULL,
            default => self::OTHER,
        };
    }
}
