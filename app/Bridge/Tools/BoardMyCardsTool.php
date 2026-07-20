<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Support\Facades\Log;

/**
 * board_my_cards (DL-217) — a READ-PROXY returning the calling agent's own cards
 * without ever handing out the kanban token: the agent's own product swimlane
 * (grouped by stage name), the shared cross-system swimlane when configured, and
 * (when coord_board_id + address_tags are set) coordination cards addressed to it.
 *
 * Read isolation is 100% BRIDGE-ENFORCED. All agents share the one writeback
 * user, and kanban scopes reads by that user's BOARD membership, never by
 * swimlane — so the boundary keeping agent A out of agent B's lane is the
 * config's swimlane_id plus the fail-closed row filter here: the `swimlane_id=`
 * search term is efficiency + defense-in-depth against an un-upgraded/misbehaving
 * kanban, NOT the boundary. Every returned row is re-checked against the
 * configured swimlane and a non-matching one is DROPPED + logged (a misbehaving
 * upstream must never leak a foreign lane's card into a caller's window).
 */
final class BoardMyCardsTool implements Tool
{
    public function name(): string
    {
        return 'board_my_cards';
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        $boardId = (int) $cfg->boardId;
        $swimlaneId = (int) $cfg->swimlaneId;
        $stageNames = $client->boardStageNames($boardId);

        $ownRows = $this->filterSwimlane($client->swimlaneCards($boardId, $swimlaneId), $swimlaneId, $agentName, 'own');
        $result = [
            'board_id' => $boardId,
            'swimlane_id' => $swimlaneId,
            'cards_by_stage' => $this->groupByStage($ownRows, $stageNames),
        ];

        if ($cfg->sharedSwimlaneId !== null) {
            $sharedRows = $this->filterSwimlane($client->swimlaneCards($boardId, $cfg->sharedSwimlaneId), $cfg->sharedSwimlaneId, $agentName, 'shared');
            $result['shared_swimlane'] = [
                'swimlane_id' => $cfg->sharedSwimlaneId,
                'cards_by_stage' => $this->groupByStage($sharedRows, $stageNames),
            ];
        }

        if ($cfg->coordBoardId !== null && $cfg->addressTags !== []) {
            $result['coord_cards'] = $this->coordCards($client, $cfg);
        }

        return $result;
    }

    /**
     * Drop (and log) any row whose own `swimlane_id` field does not match the
     * scope — the fail-closed read-isolation filter. A row missing the field is
     * also dropped (fail-closed: we cannot prove it belongs to this lane).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterSwimlane(array $rows, int $swimlaneId, string $agentName, string $leg): array
    {
        $kept = [];
        foreach ($rows as $row) {
            $rowSwimlane = $row['swimlane_id'] ?? null;
            if (is_numeric($rowSwimlane) && (int) $rowSwimlane === $swimlaneId) {
                $kept[] = $row;

                continue;
            }
            Log::warning('board_my_cards: dropped a row whose swimlane_id does not match the configured scope — the upstream search returned an out-of-scope card; read isolation is bridge-enforced', [
                'agent' => $agentName,
                'leg' => $leg,
                'expected_swimlane' => $swimlaneId,
                'row_swimlane' => is_scalar($rowSwimlane) ? $rowSwimlane : null,
                'card_id' => is_scalar($row['id'] ?? null) ? $row['id'] : null,
            ]);
        }

        return $kept;
    }

    /**
     * Coordination cards addressed to this agent (Q1): the union of cards on the
     * coord board carrying ANY of the agent's address_tags. De-duplicated by id
     * (a card can carry several address tags). Not swimlane-filtered — the
     * addressing IS the scope here.
     *
     * @return list<array<string, mixed>>
     */
    private function coordCards(KanbanClient $client, BoardToolsConfig $cfg): array
    {
        $coordBoardId = (int) $cfg->coordBoardId;
        $byId = [];
        foreach ($cfg->addressTags as $tag) {
            foreach ($client->cardRowsByTag($coordBoardId, $tag) as $row) {
                $id = $row['id'] ?? null;
                if (is_numeric($id)) {
                    $byId[(int) $id] = $row;
                }
            }
        }
        ksort($byId);
        $coordStageNames = $client->boardStageNames($coordBoardId);

        return array_map(fn (array $row): array => $this->projectCard($row, $coordStageNames), array_values($byId));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, string>  $stageNames
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByStage(array $rows, array $stageNames): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $stageId = is_numeric($row['workflow_stage_id'] ?? null) ? (int) $row['workflow_stage_id'] : null;
            $stageName = $stageId !== null && isset($stageNames[$stageId]) ? $stageNames[$stageId] : ('stage:'.($stageId ?? '?'));
            $grouped[$stageName][] = $this->projectCard($row, $stageNames);
        }

        return $grouped;
    }

    /**
     * Project a raw kanban card row to the tool's card shape (DL-217): id, name,
     * stage, tags, dl_number, pr_number, updated_at — nothing else leaves the
     * bridge.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $stageNames
     * @return array<string, mixed>
     */
    private function projectCard(array $row, array $stageNames): array
    {
        $stageId = is_numeric($row['workflow_stage_id'] ?? null) ? (int) $row['workflow_stage_id'] : null;
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $tags = [];
        foreach (is_array($row['tags'] ?? null) ? $row['tags'] : [] as $tag) {
            if (is_string($tag)) {
                $tags[] = $tag;
            }
        }

        return [
            'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
            'name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : null,
            'stage' => $stageId !== null && isset($stageNames[$stageId]) ? $stageNames[$stageId] : null,
            'tags' => $tags,
            'dl_number' => is_scalar($payload['dl_number'] ?? null) ? $payload['dl_number'] : null,
            'pr_number' => is_scalar($payload['pr_number'] ?? null) ? $payload['pr_number'] : null,
            'updated_at' => is_scalar($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null,
        ];
    }
}
