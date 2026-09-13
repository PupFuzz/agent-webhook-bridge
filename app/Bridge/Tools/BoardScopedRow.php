<?php

namespace App\Bridge\Tools;

use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\MappedBoardGuard;

/**
 * THE VERDICT A BOARD-SCOPED CARD LOOKUP IS READ OFF — the one place the tools door
 * decides whether a set of rows establishes *this card, on this board* (card#9170,
 * extracted at its second caller from {@see BoardCorrectCardTool}).
 *
 * ⛔ THE ROWS ESTABLISH THE SCOPE, NEVER THE FACT THAT THE CALL WAS MADE WITH A SCOPED
 * QUERY. `GET /tasks/search.json` drops a term it does not recognise and still answers
 * 200 (DL-323 Decision 2's `board_scope_lookup_unfiltered`), so a row counts only when
 * its OWN `id` names the card and its OWN `board_id` is the agent's configured board.
 * That is the whole of the rule, and it is here rather than copied per tool because a
 * card id on this door is CALLER-SUPPLIED against a kanban id space that is GLOBAL
 * across every board on the instance: a second copy is a second chance to get a tenant
 * boundary subtly wrong, and the two would then disagree about one refusal.
 *
 * ⚠ IT IS NOT {@see MappedBoardGuard::belongs}, and consolidating
 * the two is not free. That one answers the same question for the WRITEBACK, keyed on a
 * `WritebackMapping` and paired with a per-row refusal REPORT on the alert channel; this
 * door has neither a mapping nor an alert channel (a refusal here travels back down the
 * call that asked). What is shared is the predicate's SHAPE, not its inputs or its
 * reporting, so the honest state is two owners with this note between them rather than
 * one primitive carrying an argument that means nothing to half its callers.
 */
final class BoardScopedRow
{
    /**
     * The one row that IS this card on this board, or null.
     *
     * ⚠ NOT SPELLED `match()`, and the reason is an instrument rather than taste: PHP
     * tokenises `match` as `T_MATCH` and not `T_STRING`, so the structural scanners in
     * `tests/Support/SourceScan.php` cannot see a method by that name OPEN a body — every
     * site inside one is attributed to `(file scope)`, sharing an ordinal with the whole
     * file. Measured here (`WritebackRefusalSignalCoverageTest` reported this class's
     * `board_id` read as `BoardScopedRow.php::(file scope)#1`). The guards' set equality
     * still holds; what is lost is which METHOD a dispositioned site sits in.
     *
     * @param  list<array<string, mixed>>  $rows  as {@see KanbanClient::cardRowsOnBoard} returns them
     * @return array<string, mixed>|null
     */
    public static function forCard(array $rows, int $boardId, int $cardId): ?array
    {
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $board = $row['board_id'] ?? null;
            if (is_numeric($id) && (int) $id === $cardId && is_numeric($board) && (int) $board === $boardId) {
                return $row;
            }
        }

        return null;
    }
}
