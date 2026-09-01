<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\RefusalContext;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The DL-009 belongs-to-mapped-board security guard: the ONE place that decides
 * whether a card kanban handed back is on the operator-mapped board for this repo,
 * the ONE place that reports the refusal (DL-292, card#7138), and — since card#7212 —
 * the ONE place that renders the (card board, mapped board) pair any writeback record
 * carries, so the success arm reuses this rendering instead of growing a second one — and,
 * since DL-300, the one place that PERSISTS that pair on the only occasion worth outliving
 * the log: when the two boards disagree.
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
 * `WritebackRefusalSignalCoverageTest` reds on a handler — or, since DL-301, a bridge
 * command — that reads a card's `board_id` at all. That closure is by KIND and holds at
 * every log level, which the `Log::warning`/`Log::error` population of that test's other
 * leg cannot do.
 *
 * ⭐ TWO ENTRY POINTS, ONE RULE, AND THE ORDER BETWEEN THEM IS THE POINT (card#8375).
 * {@see refuses} answers the membership question from a card ALREADY READ, which means the
 * read happened first — and on the `card#NNNN` path that read is an UNSCOPED
 * `GET /tasks/{id}.json` of an id parsed out of author-controlled text, against a kanban id
 * space that is GLOBAL across every board on the instance. So the tenant boundary's
 * precondition used to be a SUCCESSFUL CROSS-TENANT READ: a foreign id was resolved and then
 * refused downstream, and on a 403 it was not even refused by this guard — the read threw
 * first and the compare was structurally unreachable (DL-314). {@see refusesCardIdOutsideMappedBoard}
 * asks the question BEFORE any such read, through a board-scoped lookup
 * ({@see KanbanClient::cardRowsOnBoard}), so an id outside the mapping is never resolved at
 * all. Both are kept: the scoped check is what makes the boundary a property of the CODE
 * rather than of whatever the writeback token's scope happens to be, and the post-read compare
 * still owns the divergence record and stays the last word on the row actually written to.
 */
final class MappedBoardGuard
{
    /** The reason code every belongs-to-mapped-board refusal shares (third element of the alert dedup tuple). */
    public const REASON = 'card_not_on_mapped_board';

    /**
     * The card id does not resolve on the mapped board, AND the mapped board itself reads
     * back — so the board is visible to this token and this id is simply not one of its
     * cards (card#8375). Distinct from {@see REASON} because the EVIDENCE is different: that
     * one names the board a card kanban handed back was on, this one names an id no
     * board-scoped read of ours can find at all.
     */
    public const REASON_ID_OUTSIDE_MAPPED_BOARD = 'card_id_outside_mapped_board';

    /**
     * The card id does not resolve on the mapped board and the MAPPED BOARD does not read
     * back either — the token's own scope, not (necessarily) a foreign id. The refusal is
     * the same; the operator's next step is not, which is the whole reason the two are
     * separate slugs (card#8375).
     */
    public const REASON_MAPPED_BOARD_UNREADABLE = 'mapped_board_unreadable_to_this_token';

    /**
     * The board-scoped lookup answered a row that does not name this card on the mapped board
     * — a different card, or this card on a different board — so one of the two `q=` terms did
     * not narrow and no membership verdict can be read out of the answer. A
     * CONTRACT break, not a tenant verdict: the search silently drops what it does not
     * recognise, so this is the state that must never be mistaken for "not on the board".
     */
    public const REASON_SCOPE_LOOKUP_UNFILTERED = 'board_scope_lookup_unfiltered';

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
     * The board pair EVERY writeback record carries: the board the card kanban actually
     * handed back is on, and the board this repo's mapping intended to write to.
     *
     * ⛔ Both keys, on BOTH arms — the asymmetry was the defect (card#7212, rt#327). Until
     * this existed only {@see refuses} emitted the pair; a success emitted the mapped board
     * from CONFIG alone, so a write that LANDED on an out-of-mapping card was byte-identical
     * in the log to a correct one. Generalised: any check whose evidence is emitted only on
     * the REFUSAL path can answer "did we ever stop it?" and never "did this ever happen?" —
     * an absence of record is not a record of absence. Retention is 14 days and no audit
     * table records a card or board id, so a record not written here is unrecoverable.
     *
     * ⚑ `card_board` is the card's RAW `board_id`, NOT normalised through the predicate, and
     * that is deliberate twice over. (1) The accepted set is an INTERVAL (see the class
     * docblock): `'8'` and `8.9` both belong to a mapping of 8, and which spelling kanban
     * actually returned is exactly what a reader of this record wants. (2) The compare that
     * {@see refuses} runs — including on the Group-B sites (card#7211) that resolve ids from a
     * board-scoped SEARCH, since DL-298, and on `bridge:reconcile --fix` since DL-301 — accepts
     * that whole interval, so normalising here would render a value no gate on any path ever
     * computed. So the two values being EQUAL is the happy path, not an invariant this renders;
     * a divergence is the record doing its job.
     *
     * ⭐ AND, WHEN THE PAIR DIVERGES, IT PERSISTS ONE ROW (card#7212, second half). The log
     * line above is retention-bounded — 14 days, pruned by the receiver's own gate since
     * DL-199 — so on its own it answers "did this ever happen?" for a fortnight and then
     * stops. A record that expires is an absence on a timer. The divergent case, and ONLY
     * the divergent case, is therefore also written to `writeback_board_divergences`
     * ({@see BoardDivergenceLedger}); the happy path persists nothing, which is what keeps
     * an empty table meaningful — growth is the signal (DL-300).
     *
     * ⛔ THE RECORD IS MINTED HERE because this is the one place that holds BOTH the card and
     * the mapping, so the durable row and the log line are the same observation, decided by
     * the same {@see belongs} predicate — no second rendering, no second compare, and no
     * `$arm` argument for eleven call sites to spell (and for the twelfth to omit). It is why
     * a renderer has a side effect: the alternative is a persist call beside every record,
     * which is the shape that produced this defect in the first place.
     *
     * $disposition names what happened to the write, and only {@see refuses} passes anything
     * but the default: a divergence seen anywhere else is one no gate stopped.
     *
     * @param  array<string, mixed>  $card  as returned by {@see KanbanClient::getCard()}, or a
     *                                      raw search row — a card the caller has in hand either way
     * @return array{card_board: mixed, mapped_board: int}
     */
    public static function boardContext(
        array $card,
        WritebackMapping $mapping,
        string $disposition = BoardDivergenceLedger::DISPOSITION_RECORDED,
    ): array {
        $context = ['card_board' => $card['board_id'] ?? null, 'mapped_board' => $mapping->boardId];

        if (! self::belongs($card, $mapping)) {
            BoardDivergenceLedger::observe($context, $card, $disposition);
        }

        return $context;
    }

    /**
     * The guard AND its refusal report. Returns true when the card is NOT on the mapped
     * board — the caller returns without writing anything (permanent refusal: alert +
     * log + no-op, never a 5xx retry). Returns false when the card belongs and the
     * caller may proceed.
     *
     * $arm is the reaction name the message is prefixed with. SEVEN arms call this, in three
     * families: the TOKEN-resolved writes (`kanban_move_card`, `kanban_block_reason`,
     * `kanban_coord_card_move`); since DL-298, the SEARCH-resolved row re-checks
     * (`dependabot_card`, `promote_on_release`, `coord_card_create`); and since DL-301, the one
     * CLI arm — `bridge_reconcile`, whose rows come from the same board-scoped search but whose
     * write is applied by `bridge:reconcile --fix` rather than by a handler. They share one
     * reason code (`card_not_on_mapped_board`) and are kept apart in the dedup tuple by their
     * `$outcome` (DL-274(3)).
     * ⛔ This list is a restatement and has now gone stale twice — it named three arms after
     * DL-298 made it six, and six after DL-301 made it seven. If you add a caller, add it here;
     * the reason code is the thing to grep for if you suspect it has drifted again. What is NOT
     * a restatement, and is where a missing arm actually reds, is
     * `WritebackRefusalSignalCoverageTest`'s KIND leg — which is why DL-301 widened that leg's
     * population to the bridge CLI rather than only editing this sentence.
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
            ['card_id' => $cardId, 'repo' => $repo]
                + self::boardContext($card, $mapping, BoardDivergenceLedger::DISPOSITION_REFUSED)
                + ($issueNumber === null ? [] : ['issue' => $issueNumber]),
            $repo, $outcome, $cardId, self::REASON, $issueNumber,
        );

        return true;
    }

    /**
     * The SAME rule, asked BEFORE the card is read (card#8375): does this card id resolve on
     * the operator-mapped board? Returns true when it does not — the caller returns without
     * reading or writing anything (permanent refusal: alert + log + no-op, never a 5xx retry).
     *
     * ⭐ WHY THIS ORDER IS THE FIX AND THE COMPARE ALONE WAS NOT. On the `card#NNNN` path the
     * id is a literal parsed out of AUTHOR-CONTROLLED text and kanban's id space is GLOBAL
     * across every board on the instance, so nothing about the id says whose it is. Reading it
     * first and comparing afterwards makes the boundary's precondition a SUCCESSFUL
     * CROSS-TENANT READ — which is a read that already happened on this install's credential,
     * and which on a 403 never even reaches the compare. Measured live before this existed: a
     * repo event on one install resolved to a card another install owns, and the only thing
     * that stopped it was the API answering 403. That is a property of the TOKEN, not of the
     * code: nothing asserted the token's scope stays narrow, and no test went red if it
     * widened. Asking the SERVER for `(board, id)` moves the boundary into the code.
     *
     * ⛔ THE VERDICT IS READ OFF THE ROWS, NEVER OFF THE CALL. A search filter the endpoint
     * does not recognise degrades to free text or is dropped in silence, and the response is a
     * 200 either way — so this accepts only a row that names THIS card id AND whose own
     * `board_id` is the mapped board ({@see belongs}, the same predicate the post-read arm
     * uses). An answer carrying anything else establishes nothing and is refused under
     * {@see REASON_SCOPE_LOOKUP_UNFILTERED} rather than being reported as a tenant verdict.
     *
     * ⚑ BOTH SIDES OF THE ARCHIVE SWITCH, deliberately: kanban's search excludes archived rows
     * unless `?archived` is passed and offers no both-sides mode (DL-296), so a live-only check
     * would have started refusing every archived card on the mapped board — a second
     * accept/reject change riding along on this one. The archived probe therefore runs on the
     * live miss, and only there: the happy path is ONE request.
     *
     * ⚑ THREE REFUSAL REASONS, because the operator's next step differs and a 403 could never
     * tell them apart (which is what DL-314 recorded as undecidable and deferred). The
     * discriminator is a CONTROL, not an inference: the mapped board is probed on its own
     * ({@see KanbanClient::visibility}) after a miss, so "this id is not ours" is only claimed
     * when the board that would carry it reads back. A board that reads EMPTY reaches the
     * weaker verdict — an unreadable board and a genuinely empty one are one answer here, and
     * the message says so rather than picking the accusing one.
     *
     * ⚑ NO DIVERGENCE ROW AND NO `boardContext()` — by construction, not by omission. That
     * record is the (card board, mapped board) PAIR, and the card's board is exactly what a
     * refusal here never learns: no row of ours came back. Recording the mapped board alone
     * would put a half-pair in a table whose whole value is that both halves are measured.
     *
     * ⚑ THE ALERT WITHHOLDS THE CARD ID (DL-314): this arm holds an id it did NOT establish as
     * this install's — the definition of the case that rule exists for — so the id stays in
     * the `Log::warning` context (the local operator's surface) and never reaches the channel.
     */
    public static function refusesCardIdOutsideMappedBoard(
        WritebackAlertNotifier $alerts,
        KanbanClient $client,
        WritebackMapping $mapping,
        string $arm,
        int $cardId,
        string $repo,
        string $outcome,
    ): bool {
        $answeredNoMatchingRow = false;

        try {
            foreach ([false, true] as $archivedOnly) {
                foreach ($client->cardRowsOnBoard($mapping->boardId, $cardId, $archivedOnly) as $row) {
                    if (self::namesCard($row, $cardId) && self::belongs($row, $mapping)) {
                        return false;   // established on the mapped board — the caller may read it
                    }
                    // A row came back that is not this card on this board: whatever the query
                    // asked, the ANSWER is not narrowed, so no verdict can be read out of it.
                    $answeredNoMatchingRow = true;
                }
            }
        } catch (RequestException $e) {
            if (! RefusalContext::isPermanent($e)) {
                throw $e;   // transient → 5xx → redelivery retries once it is fixed
            }

            // A 4xx on a BOARD-SCOPED read says nothing about whose card the id is — the query
            // named this install's own board — so the foreign-id hypothesis is excluded here
            // and the slug says the token's scope instead.
            $alerts->warnAndNotifyCardIdWithheld(
                $arm.': REFUSED — the board-scoped lookup that establishes whether this card id is on the mapped board was itself refused by kanban (4xx), so membership could not be established and nothing was read unscoped (see `body` for the reason kanban gave); the card id is in this log line only, never in the alert channel',
                ['card_id' => $cardId, 'repo' => $repo, 'mapped_board' => $mapping->boardId] + RefusalContext::from($e),
                $repo, $outcome, RefusalContext::readReason('boardscope', $e, foreignIdExcluded: true),
            );

            return true;
        }

        $reason = match (true) {
            $answeredNoMatchingRow => self::REASON_SCOPE_LOOKUP_UNFILTERED,
            self::mappedBoardReadsBack($client, $mapping) => self::REASON_ID_OUTSIDE_MAPPED_BOARD,
            default => self::REASON_MAPPED_BOARD_UNREADABLE,
        };

        // Spelled as a local rather than inline, because these three are the operator's whole
        // diagnosis and an external checker classifies these rows by their literal phrases.
        $message = $arm.': '.match ($reason) {
            self::REASON_SCOPE_LOOKUP_UNFILTERED => 'REFUSED — the board-scoped card lookup answered a row that does NOT name this card on the mapped board (a different card, or this card on a different board), so kanban narrowed on neither term and this answer establishes no board membership either way. Treat it as a broken read, NOT as a foreign card id: the search drops a filter it does not recognise and still answers 200. Nothing was read unscoped and nothing was written; the card id is in this log line only, never in the alert channel',
            self::REASON_ID_OUTSIDE_MAPPED_BOARD => 'REFUSED — the card id is not on the mapped board. It was refused by the BOARD-SCOPED lookup, so the card was never read: kanban card ids are GLOBAL across every board on the instance and `card#NNNN` is parsed out of author-controlled text, so an id naming another install\'s card reaches this handler intact. The mapped board itself reads back, which is what rules out this token having lost it. Nothing was written; the card id is in this log line only, never in the alert channel',
            default => 'REFUSED — the card id is not on the mapped board AND the mapped board itself did not read back to this writeback token — it answered empty, or the probe of it failed — so board membership could not be established in either direction. Check the writeback token user\'s membership of the mapped board before reading this as a foreign card id; an unreadable board and a genuinely empty one are the same answer here. Nothing was read unscoped and nothing was written; the card id is in this log line only, never in the alert channel',
        };

        $alerts->warnAndNotifyCardIdWithheld(
            $message,
            ['card_id' => $cardId, 'repo' => $repo, 'mapped_board' => $mapping->boardId],
            $repo, $outcome, $reason,
        );

        return true;
    }

    /**
     * Does a search row name $cardId? The row's own `id`, read the way {@see belongs} reads
     * its `board_id` — a numeric value naming the card whatever its JSON type, and never a
     * loose `==` that would let a non-numeric value cast onto it.
     *
     * @param  array<string, mixed>  $row
     */
    private static function namesCard(array $row, int $cardId): bool
    {
        return is_numeric($row['id'] ?? null) && (int) $row['id'] === $cardId;
    }

    /**
     * The CONTROL behind the two "not on the mapped board" verdicts: does the mapped board
     * itself read back to this token at all? Without it, a token that had lost the board
     * would report every card id as foreign — a wrong-but-specific accusation (canon #10)
     * pointing the operator at the PR author instead of at their own credential.
     *
     * Diagnostic only, so it never changes WHETHER the move is refused — only which reason it
     * is refused under. A failure therefore reaches the weaker verdict rather than propagating:
     * throwing here would turn a permanent refusal into a 5xx redelivery storm, which is the
     * anti-pattern every arm in this file exists to avoid.
     */
    private static function mappedBoardReadsBack(KanbanClient $client, WritebackMapping $mapping): bool
    {
        try {
            return $client->visibility($mapping->boardId)['total'] > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
