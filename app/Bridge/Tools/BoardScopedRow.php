<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\MappedBoardGuard;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

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
 *
 * ⭐ AND THE LOOKUP SEQUENCE IS HERE TOO ({@see lookUp}), for the same reason: every card-id
 * write tool on this door asks the same questions in the same order — live side, a BROKEN
 * READ refused as one, the archived side only on a live miss — and then decides what the
 * answer means for ITS call. The sequence is the shared part; the verdict and every message
 * about it stay with the tool.
 */
final class BoardScopedRow
{
    /**
     * @param  array<string, mixed>|null  $live  this card's row on the live side, or null
     * @param  array<string, mixed>|null  $archived  this card's row on the archived side, or null — asked, and so
     *                                               possibly non-null, only when $live is null
     */
    private function __construct(
        public readonly ?array $live,
        public readonly ?array $archived,
    ) {}

    /**
     * Establish card `$cardId` on board `$boardId`: LIVE (its row on the live side), else
     * ARCHIVED (its row on the archived side), else neither — both properties null.
     *
     * ⛔ A live-side answer that is not this card on this board is refused HERE as a BROKEN
     * READ (DL-323 Decision 2's `board_scope_lookup_unfiltered`): it is not a verdict about the
     * card, so no tool may read it as one. The archived side is asked only on a live MISS (DL-296:
     * kanban has no both-sides mode), so it costs nothing on a live card.
     *
     * ⚠ A `RequestException` from either lookup propagates unclassified: which 4xx is a named
     * refusal, and what it says the call was trying to establish, is the calling tool's.
     *
     * @throws RequestException
     * @throws ToolRefusalException
     */
    public static function lookUp(KanbanClient $client, int $boardId, int $cardId, string $toolName, string $agentName): self
    {
        $liveRows = $client->cardRowsOnBoard($boardId, $cardId);
        $live = self::forCard($liveRows, $boardId, $cardId);
        if ($live !== null) {
            return new self($live, null);
        }

        if ($liveRows !== []) {
            Log::warning("{$toolName}: the board-scoped lookup answered a row that is not this card on this board — refusing without a scope verdict", [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'rows' => count($liveRows),
            ]);

            throw new ToolRefusalException("{$toolName}: the board lookup for card {$cardId} answered a row that is not that card on your board — that is a BROKEN READ, not a verdict about the card, so nothing was written. Report it to your operator.");
        }

        return new self(null, self::forCard($client->cardRowsOnBoard($boardId, $cardId, archivedOnly: true), $boardId, $cardId));
    }

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
