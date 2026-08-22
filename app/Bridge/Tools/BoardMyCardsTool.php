<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
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
 *
 * The BOARD axis is the other half of that sentence, and until DL-302 it was the
 * odd one out in this file: the response stated the CONFIGURED board for a row set
 * whose own `board_id` nothing had read. {@see observedBoard} now reports the board
 * the returned rows actually carry, `board_observed` says whether the bridge read
 * one at all, and `configured_board_id` keeps the scope this agent is configured
 * for as a separately named value — never one dressed as the other.
 *
 * `include_description` (DL-245) is the tool's only argument: OPT-IN, because a
 * card body is ~2 KB and the projection has no bound on how many cards a lane
 * holds. Absent ⇒ the projected CARD is byte-identical to the DL-217 shape (the
 * two description keys are ABSENT, not null-valued; the enclosing response grew
 * the DL-302 board keys). The field costs no extra API call —
 * `KanbanClient::swimlaneCards()` already fetches `description` and the
 * projection discarded it.
 */
final class BoardMyCardsTool implements Tool
{
    public function name(): string
    {
        return 'board_my_cards';
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        $descriptionCap = $this->descriptionCap($args, $cfg);
        $boardId = (int) $cfg->boardId;
        $swimlaneId = (int) $cfg->swimlaneId;
        $stageNames = $client->boardStageNames($boardId);

        $ownRows = $this->filterSwimlane($client->swimlaneCards($boardId, $swimlaneId), $swimlaneId, $agentName, 'own');
        $sharedRows = $cfg->sharedSwimlaneId === null
            ? null
            : $this->filterSwimlane($client->swimlaneCards($boardId, $cfg->sharedSwimlaneId), $cfg->sharedSwimlaneId, $agentName, 'shared');

        [$observedBoard, $boardObserved] = $this->observedBoard(array_merge($ownRows, $sharedRows ?? []), $boardId, $agentName, 'own+shared');
        $result = [
            'board_id' => $observedBoard,
            'board_observed' => $boardObserved,
            'configured_board_id' => $boardId,
            'swimlane_id' => $swimlaneId,
            'cards_by_stage' => $this->groupByStage($ownRows, $stageNames, $descriptionCap),
        ];

        if ($sharedRows !== null) {
            $result['shared_swimlane'] = [
                'swimlane_id' => (int) $cfg->sharedSwimlaneId,
                'cards_by_stage' => $this->groupByStage($sharedRows, $stageNames, $descriptionCap),
            ];
        }

        if ($cfg->coordBoardId !== null && $cfg->addressTags !== []) {
            $result += $this->coordBlock($client, $cfg, $descriptionCap, $agentName);
        }

        return $result;
    }

    /**
     * The per-card description byte cap for THIS call, or null when the caller did
     * not opt in. Null is what keeps the default response byte-identical: it makes
     * the projection omit both description keys rather than emit them null-valued.
     * A non-bool is REFUSED rather than coerced — a truthy string would silently
     * turn on the expensive projection the opt-in exists to gate.
     *
     * @param  array<string, mixed>  $args
     */
    private function descriptionCap(array $args, BoardToolsConfig $cfg): ?int
    {
        if (! array_key_exists('include_description', $args) || $args['include_description'] === null) {
            return null;
        }
        $include = $args['include_description'];
        if (! is_bool($include)) {
            throw new ToolRefusalException('board_my_cards: `include_description` must be a boolean when provided');
        }

        return $include ? $cfg->descriptionMaxBytes : null;
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
     * WHICH BOARD THE ROWS SAY THEY ARE ON — the board axis's answer to what
     * {@see filterSwimlane} is for the lane axis (card#7295, DL-302). Returns
     * `[observed board, observed?]`.
     *
     * The two axes are NOT symmetrical, and this is why they get different
     * treatment. Read isolation is a LANE boundary, so the lane filter must be
     * fail-closed and DROPS a non-matching row. The board is not a boundary here
     * (kanban already scopes every read to the token user's board membership) —
     * it is a CLAIM the response makes, and the claim was restated from `$cfg`
     * for a row set nothing had re-checked. So this reports; it does not drop and
     * it does not refuse. What a foreign row should make the tool DO is a
     * separate, ask-gated question from what the tool SAYS, and only the saying
     * changed here.
     *
     * ⚑ It costs NO extra request. Kanban's task resource carries `board_id` on
     * every row `/tasks/search.json` returns, so the rows this tool already holds
     * are the reading. (The same correction on `BoardCreateCardTool` — card#7225,
     * a SEPARATE change — has to pay a `GET /tasks/{id}.json`, because a POST hands
     * back an id and nothing else. Do not read that cost across to here.)
     *
     * ⛔ NO key-absent/value-null discrimination on this axis, deliberately, and
     * that is the one place this must NOT copy its sibling. `tasks.board_id` is a
     * non-nullable foreign key in kanban: a card is always on exactly one board,
     * so there is no such thing as a row legitimately reporting "no board". An
     * absent key, a present null and a non-numeric value therefore all mean the
     * same thing — the row answered nothing about its board — and all three are
     * UNOBSERVED. The lane axis is the opposite (a card really can be in no lane),
     * which is why its sibling has to tell a present null from a missing key.
     *
     * Unobserved on ANY row unobserves the SET: the response states one board for
     * a row set, so a set that does not unanimously answer has no honest single
     * value, and `[null, false]` is the answer — never a majority, never the
     * configured board. An EMPTY set is unobserved too and is NOT anomalous (an
     * agent with no cards read no board), so it is the one arm that logs nothing.
     *
     * The accepted set of the compare is `is_numeric` + `(int)`, deliberately the
     * same one `MappedBoardGuard::belongs()` applies to a card's `board_id`, so
     * the two surfaces cannot disagree about which spellings name board N. It is
     * NOT routed through that primitive: `belongs()` takes a `WritebackMapping`
     * (a repo→board write mapping this read path does not have and must not
     * invent), it answers a membership DECISION that ends in a refusal, and its
     * predicate is pinned verbatim by `WritebackRefusalSignalCoverageTest` to
     * DL-292's minuted vector table — reshaping it to serve a read projection is
     * a gated change to a security guard, not a refactor.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: ?int, 1: bool}
     */
    private function observedBoard(array $rows, int $configuredBoardId, string $agentName, string $leg): array
    {
        if ($rows === []) {
            return [null, false];
        }

        $boards = [];
        foreach ($rows as $row) {
            $rowBoard = $row['board_id'] ?? null;
            if (! is_numeric($rowBoard)) {
                Log::warning('board_my_cards: a returned row carried no readable board, so the response reports NO board for this row set rather than the configured one', [
                    'agent' => $agentName,
                    'leg' => $leg,
                    'configured_board' => $configuredBoardId,
                    'row_board' => is_scalar($rowBoard) ? $rowBoard : null,
                    'card_id' => is_scalar($row['id'] ?? null) ? $row['id'] : null,
                ]);

                return [null, false];
            }
            $boards[(int) $rowBoard] = true;
        }

        if (count($boards) > 1) {
            Log::warning('board_my_cards: the returned rows are spread across more than one board, so the response reports NO board for this row set', [
                'agent' => $agentName,
                'leg' => $leg,
                'configured_board' => $configuredBoardId,
                'observed_boards' => array_keys($boards),
            ]);

            return [null, false];
        }

        $observed = (int) array_key_first($boards);
        if ($observed !== $configuredBoardId) {
            Log::warning('board_my_cards: the returned rows are NOT on the board this agent is configured to read — the response reports where they actually are, and nothing was dropped', [
                'agent' => $agentName,
                'leg' => $leg,
                'configured_board' => $configuredBoardId,
                'observed_board' => $observed,
                'rows' => count($rows),
            ]);
        }

        return [$observed, true];
    }

    /**
     * Coordination cards addressed to this agent (Q1): the union of cards on the
     * coord board carrying ANY of the agent's address_tags. De-duplicated by id
     * (a card can carry several address tags). Not swimlane-filtered — the
     * addressing IS the scope here.
     *
     * The block carries the SAME board pair as the top level (card#7295 comment
     * append, DL-302). It used to carry no board key at all, which is the
     * missing-key sibling of the wrong-value defect this card names: a caller was
     * handed a second card list, from a DIFFERENT board, with nothing saying so —
     * and the top-level `board_id` sitting above it is the obvious thing for a
     * reader to assume covers it. Stating the coord board explicitly is what stops
     * the top-level reading from being inherited by rows it does not describe; it
     * is the same standard applied to both windows in one file, which is the whole
     * complaint on this card.
     *
     * @return array{coord_board_id: ?int, coord_board_observed: bool, configured_coord_board_id: int, coord_cards: list<array<string, mixed>>}
     */
    private function coordBlock(KanbanClient $client, BoardToolsConfig $cfg, ?int $descriptionCap, string $agentName): array
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
        $rows = array_values($byId);
        $coordStageNames = $client->boardStageNames($coordBoardId);
        [$observedBoard, $boardObserved] = $this->observedBoard($rows, $coordBoardId, $agentName, 'coord');

        return [
            'coord_board_id' => $observedBoard,
            'coord_board_observed' => $boardObserved,
            'configured_coord_board_id' => $coordBoardId,
            'coord_cards' => array_map(fn (array $row): array => $this->projectCard($row, $coordStageNames, $descriptionCap), $rows),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, string>  $stageNames
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByStage(array $rows, array $stageNames, ?int $descriptionCap): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $stageId = is_numeric($row['workflow_stage_id'] ?? null) ? (int) $row['workflow_stage_id'] : null;
            $stageName = $stageId !== null && isset($stageNames[$stageId]) ? $stageNames[$stageId] : ('stage:'.($stageId ?? '?'));
            $grouped[$stageName][] = $this->projectCard($row, $stageNames, $descriptionCap);
        }

        return $grouped;
    }

    /**
     * Project a raw kanban card row to the tool's card shape (DL-217): id, name,
     * stage, tags, dl_number, pr_number, updated_at — plus, ONLY when the caller
     * opted in (DL-245), description + description_truncated. Nothing else leaves
     * the bridge.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $stageNames
     * @param  ?int  $descriptionCap  null ⇒ omit both description keys entirely
     * @return array<string, mixed>
     */
    private function projectCard(array $row, array $stageNames, ?int $descriptionCap): array
    {
        $stageId = is_numeric($row['workflow_stage_id'] ?? null) ? (int) $row['workflow_stage_id'] : null;
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $tags = [];
        foreach (is_array($row['tags'] ?? null) ? $row['tags'] : [] as $tag) {
            if (is_string($tag)) {
                $tags[] = $tag;
            }
        }

        $card = [
            'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
            'name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : null,
            'stage' => $stageId !== null && isset($stageNames[$stageId]) ? $stageNames[$stageId] : null,
            'tags' => $tags,
            'dl_number' => is_scalar($payload['dl_number'] ?? null) ? $payload['dl_number'] : null,
            'pr_number' => is_scalar($payload['pr_number'] ?? null) ? $payload['pr_number'] : null,
            'updated_at' => is_scalar($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null,
        ];

        if ($descriptionCap !== null) {
            [$card['description'], $card['description_truncated']] = $this->capDescription($row['description'] ?? null, $descriptionCap);
        }

        return $card;
    }

    /**
     * Cut a description to the byte cap, reporting whether anything was cut. The
     * flag is load-bearing: a seat must never be able to mistake a truncated
     * scope for the whole scope.
     *
     * `mb_strcut`, not `substr` — the cap is a BYTE budget (response size is what
     * the opt-in bounds), and a raw byte cut can split a multi-byte character. The
     * invalid UTF-8 that produces would fail `json_encode` for the WHOLE response,
     * so one emoji at the cut point would blank the caller's entire board window.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function capDescription(mixed $raw, int $cap): array
    {
        if (! is_scalar($raw)) {
            return [null, false];
        }
        $description = (string) $raw;
        $cut = mb_strcut($description, 0, $cap, 'UTF-8');

        return [$cut, $cut !== $description];
    }
}
