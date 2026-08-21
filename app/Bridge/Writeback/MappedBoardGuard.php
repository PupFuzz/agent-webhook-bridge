<?php

namespace App\Bridge\Writeback;

/**
 * The DL-009 belongs-to-mapped-board security guard: the ONE place that decides
 * whether a card kanban handed back is on the operator-mapped board for this repo,
 * and the ONE place that reports the refusal (DL-292, card#7138).
 *
 * Before this, the same rule was spelled three times — `$boardId !== $mapping->boardId`
 * in `KanbanMoveCardHandler`, `($card['board_id'] ?? null) !== $mapping->boardId` in
 * `KanbanBlockReasonHandler`, and the `is_numeric` + `(int)` form in
 * `KanbanCoordCardMoveHandler` — which is how one guard came to carry two different
 * report severities (card#7133: the coord copy sat at `Log::info` while its twins
 * alerted) and three different accepted sets. One behaviour, three implementations,
 * so a change to the rule had to be made three times and correctly each time.
 *
 * ⛔ THE PREDICATE, and the direction of it, because it has been reported backwards
 * once already. `WritebackMapping::$boardId` is a `readonly int`, and `!==` does NO
 * type juggling — so the two `!==` spellings refused ANY non-int `board_id`, including
 * a numeric string `"8"` or a float `8.0`, which name the very board that is mapped.
 * The `is_numeric` + `(int)` form adopted here is therefore the most PERMISSIVE of the
 * three and the most CORRECT; it is not the strictest. `is_numeric` is what stops
 * `(int)` coercing a NON-numeric value (`'8abc'` casts to `8`) onto the mapped id,
 * which is the one hole that tolerating numeric strings would otherwise open.
 *
 * ⚑ The accepted set is an INTERVAL, not a value: `(int)` TRUNCATES, so for a mapped
 * board of 8 this takes every numeric spelling of a value in `[8, 9)` — `8.9` and
 * `'8.0000001'` included, `7.9` not (truncation only reaches the board from above).
 * That is inherent to this form rather than a separate choice, and it is RULED
 * deliberate: unreachable while kanban returns `board_id` as a JSON integer, and it
 * opens no hole, since every accepted value still truncates onto the mapped board.
 * ⛔ Do not make the compare lossless without re-opening the gate — DL-292 minutes the
 * approved set as a vector table, and `WritebackRefusalSignalCoverageTest` pins this
 * predicate verbatim so the minute cannot drift from the code.
 *
 * The report is inside the primitive, not left to the caller, and that is the point:
 * a refusal cannot be minted at some other log level, or with some other reason code,
 * without minting a fourth copy of the compare — and
 * `WritebackRefusalSignalCoverageTest` reds on a handler that reads a card's
 * `board_id` at all. That closure is by KIND and holds at every log level, which the
 * `Log::warning`/`Log::error` population of that test's other leg cannot do.
 */
final class MappedBoardGuard
{
    /** The reason code every belongs-to-mapped-board refusal shares (third element of the alert dedup tuple). */
    public const REASON = 'card_not_on_mapped_board';

    /**
     * Whether $card is on the repo's mapped board. A numeric `board_id` naming the
     * mapped board belongs to it whatever its JSON type; anything else does not.
     *
     * @param  array<string, mixed>  $card  as returned by {@see KanbanClient::getCard()}
     */
    public static function belongs(array $card, WritebackMapping $mapping): bool
    {
        return is_numeric($card['board_id'] ?? null) && (int) $card['board_id'] === $mapping->boardId;
    }

    /**
     * The guard AND its refusal report. Returns true when the card is NOT on the mapped
     * board — the caller returns without writing anything (permanent refusal: alert +
     * log + no-op, never a 5xx retry). Returns false when the card belongs and the
     * caller may proceed.
     *
     * $arm is the reaction name the message is prefixed with (`kanban_move_card`,
     * `kanban_block_reason`, `kanban_coord_card_move`) — the arms share one reason code
     * and are kept apart in the dedup tuple by their `$outcome` (DL-274(3)).
     * $issueNumber is passed by the issue/PR-keyed arms only, and adds the `issue` key
     * to the log context (DL-285).
     *
     * @param  array<string, mixed>  $card  as returned by {@see KanbanClient::getCard()}
     */
    public static function refuses(
        WritebackAlertNotifier $alerts,
        array $card,
        WritebackMapping $mapping,
        string $arm,
        int $cardId,
        string $repo,
        string $outcome,
        ?int $issueNumber = null,
    ): bool {
        if (self::belongs($card, $mapping)) {
            return false;
        }

        $alerts->warnAndNotify(
            $arm.': REFUSED — card is not on the mapped board',
            [
                'card_id' => $cardId,
                'repo' => $repo,
                'card_board' => $card['board_id'] ?? null,
                'mapped_board' => $mapping->boardId,
            ] + ($issueNumber === null ? [] : ['issue' => $issueNumber]),
            $repo, $outcome, $cardId, self::REASON, $issueNumber,
        );

        return true;
    }
}
