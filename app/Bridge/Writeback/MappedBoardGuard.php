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
 * `WritebackRefusalSignalCoverageTest` reds on a read of a card's `board_id` ANYWHERE in
 * `app/` that its list does not disposition — a handler, a bridge command, a board tool
 * (the population was two globs until card#8530). That closure is by KIND and holds at
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
     * The card id does not resolve on ANY of the boards this mapping declares (card#9850 /
     * DL-404), and every one of them reads back — so the declared set was fully checked and
     * this id is in none of it. The multi-board sibling of {@see REASON_ID_OUTSIDE_MAPPED_BOARD},
     * kept apart because the operator's next question differs: that one asks whether the id
     * belongs to this install at all, this one also asks whether the mapping's `boards` list
     * is missing a board this repo genuinely cites.
     */
    public const REASON_ID_OUTSIDE_DECLARED_BOARDS = 'card_id_outside_declared_boards';

    /**
     * The card id resolved on none of the declared boards AND at least one of them did not
     * read back — so the set was not fully checked and "in none of it" was never established.
     * The multi-board sibling of {@see REASON_MAPPED_BOARD_UNREADABLE} (card#9850 / DL-404).
     */
    public const REASON_DECLARED_BOARD_UNREADABLE = 'declared_board_unreadable_to_this_token';

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
     * ⚑ `mapped_board` IS THE REPO'S CONFIGURED BOARD, ALWAYS — `board_id` in `writeback.json`,
     * read off {@see WritebackMapping::$mappedBoardId} so that a mapping narrowed onto another
     * declared board (card#9850 / DL-404) cannot change what the key means to a reader who
     * already keys on it. A mapping that declares `boards` adds `declared_board`: the board
     * the card was established on and the write was judged against, which is what
     * {@see belongs} compared. It is present on EVERY record such a mapping emits — equal to
     * `mapped_board` when the card is on the mapped board — and absent on a single-board
     * mapping, whose records stay byte-identical. The ledger row does not carry it: the table
     * has no such column (see {@see BoardDivergenceLedger::observe}).
     *
     * @param  array<string, mixed>  $card  as returned by {@see KanbanClient::getCard()}, or a
     *                                      raw search row — a card the caller has in hand either way
     * @return array{card_board: mixed, mapped_board: int, declared_board?: int}
     */
    public static function boardContext(
        array $card,
        WritebackMapping $mapping,
        string $disposition = BoardDivergenceLedger::DISPOSITION_RECORDED,
    ): array {
        $context = ['card_board' => $card['board_id'] ?? null, 'mapped_board' => $mapping->mappedBoardId];
        if ($mapping->declaresAdditionalBoards()) {
            $context['declared_board'] = $mapping->boardId;
        }

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
     * `WritebackRefusalSignalCoverageTest`'s KIND leg — which is why that leg's population was
     * WIDENED, to the bridge CLI (DL-301) and then to the whole of `app/` (card#8530), rather
     * than only editing this sentence.
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
     *
     * ⭐ N DECLARED BOARDS, NOT ONE — AND THE CARD DECIDES WHICH (card#9850 / DL-404). A
     * coordination repo's pull requests cite cards on SEVERAL boards, so one repo → one
     * `board_id` could not express the right destination: the move went to the mapped board
     * or nowhere, and the sprint card the branch cited was never touched. The generalisation
     * is a LOOP where this held a scalar — {@see WritebackMapping::perDeclaredBoard()}, whose
     * first element is the mapped board and whose remainder is the operator's optional
     * `boards` list — and $mapping is NARROWED IN PLACE onto whichever declared board the card
     * was established on, so the caller's stage map, `started` promote-from / unpark sets,
     * board-order read and post-read compare all speak about the board actually written to
     * (records still name the repo's configured board as `mapped_board` — see boardContext()). A single-board mapping yields one
     * candidate, the same object, and every request this makes is the one it made before.
     *
     * ⛔ WHY IT IS A DECLARED SET AND NOT "ASK THE CARD". `card#NNNN` is parsed out of
     * author-controlled text against an id space that is GLOBAL across the instance, so
     * resolving the board from the card's own record means an UNSCOPED `GET /tasks/{id}` of an
     * author-supplied id — the very read the rest of this class exists to prevent. N bounded,
     * operator-declared, board-scoped lookups keep the boundary in the code.
     *
     * ⛔⛔ AND ON A MISS ACROSS ALL N, THE REFUSAL NAMES WHAT IT CHECKED AND NEVER WHERE THE
     * CARD ACTUALLY IS. Do NOT add a final unscoped diagnostic read to enrich the refusal with
     * "here is the board it is actually on" — and the reason is not that it merely risks
     * reopening card#8375: LOGGING IS THE LEAK. The test is never *which read did I make*, it
     * is *can a cross-install value reach an output stream, a log, a transcript or an argv* —
     * and a diagnostic read whose result is "only logged" has already crossed that boundary,
     * onto a durable surface. The boards this checked are a MEASUREMENT and are named; the
     * board the card is really on was never measured and could only be learned by that read,
     * so it is a guess or a violation and appears nowhere.
     *
     * ⚑ AND NO DIVERGENCE ROW ON AN N-BOARD MISS, carrying forward the precedent the
     * half-a-pair note below states for the single-board case. A `writeback_board_divergences`
     * row ASSERTS A RELATIONSHIP — *this card is on that board instead of this one*. A miss
     * across the declared set establishes only *not in the declared set*, so a row for it
     * would put a claim in the ledger wider than its evidence, where a later reader takes it
     * as ground. The refusal IS the record.
     *
     * @param  WritebackMapping  $mapping  IN: the repo's mapping. OUT, and ONLY when this returns
     *                                     false: that mapping narrowed onto the declared board the card
     *                                     was established on. By reference rather than as a returned
     *                                     value so a caller cannot use the guard and then keep reading
     *                                     the un-narrowed mapping — the "guard whose result is dropped
     *                                     on the floor" shape `GetCardTenantCheckCoverageTest` exists
     *                                     to catch. Untouched on every refusal path.
     * @param  string  $reason  OUT: the reason code this refusal alerted under, set only when this returns
     *                          true — for a caller that reports the refusal somewhere the alert does not
     *                          reach (DL-390's PR comment), which must tell a foreign id from an install fault
     */
    public static function refusesCardIdOutsideMappedBoard(
        WritebackAlertNotifier $alerts,
        KanbanClient $client,
        WritebackMapping &$mapping,
        string $arm,
        int $cardId,
        string $repo,
        string $outcome,
        string &$reason = '',
    ): bool {
        $declared = $mapping->perDeclaredBoard();
        $boardIds = $mapping->declaredBoardIds();
        $multi = count($boardIds) > 1;

        ['on' => $on, 'unfiltered' => $answeredNoMatchingRow, 'refused' => $lookupRefused]
            = self::locateOnDeclaredBoards($client, $declared, $cardId);

        if ($on !== null) {
            $mapping = $on;   // narrowed onto the board the card is ON

            return false;   // established on a declared board — the caller may read it
        }

        // A 4xx from ANY declared board's lookup takes precedence over a wrong-row answer from
        // any other — ranked in locateOnDeclaredBoards(), which never returns both causes, so
        // this branch and the one below cannot both apply and their order is not a ranking.
        if ($lookupRefused !== null) {
            // A 4xx on a BOARD-SCOPED read says nothing about whose card the id is — the query
            // named a board this install declares — so the foreign-id hypothesis is excluded
            // here and the slug says the token's scope instead.
            $reason = RefusalContext::readReason('boardscope', $lookupRefused, foreignIdExcluded: true);
            $alerts->warnAndNotifyCardIdWithheld(
                $arm.': REFUSED — '.($multi
                    ? 'a board-scoped lookup that establishes whether this card id is on one of the boards this mapping declares ('.implode(', ', $boardIds).') was itself refused by kanban (4xx), so membership could not be established across the declared set and nothing was read unscoped (see `body` for the reason kanban gave); the card id is in this log line only, never in the alert channel'
                    : 'the board-scoped lookup that establishes whether this card id is on the mapped board was itself refused by kanban (4xx), so membership could not be established and nothing was read unscoped (see `body` for the reason kanban gave); the card id is in this log line only, never in the alert channel'),
                ['card_id' => $cardId, 'repo' => $repo, 'mapped_board' => $mapping->boardId]
                    + ($multi ? ['declared_boards' => $boardIds] : [])
                    + RefusalContext::from($lookupRefused),
                $repo, $outcome, $reason,
            );

            return true;
        }

        $reason = match (true) {
            $answeredNoMatchingRow => self::REASON_SCOPE_LOOKUP_UNFILTERED,
            ! self::everyDeclaredBoardReadsBack($client, $declared) => $multi ? self::REASON_DECLARED_BOARD_UNREADABLE : self::REASON_MAPPED_BOARD_UNREADABLE,
            default => $multi ? self::REASON_ID_OUTSIDE_DECLARED_BOARDS : self::REASON_ID_OUTSIDE_MAPPED_BOARD,
        };

        // Spelled as a local rather than inline, because these are the operator's whole
        // diagnosis and an external checker classifies these rows by their literal phrases —
        // which is also why the single-board sentences below are unchanged to the byte rather
        // than generalised into one template that would have moved all of them at once.
        $message = $arm.': '.match ($reason) {
            self::REASON_SCOPE_LOOKUP_UNFILTERED => 'REFUSED — the board-scoped card lookup answered a row that does NOT name this card on the mapped board (a different card, or this card on a different board), so kanban narrowed on neither term and this answer establishes no board membership either way. Treat it as a broken read, NOT as a foreign card id: the search drops a filter it does not recognise and still answers 200. Nothing was read unscoped and nothing was written; the card id is in this log line only, never in the alert channel',
            self::REASON_ID_OUTSIDE_MAPPED_BOARD => 'REFUSED — the card id is not on the mapped board. It was refused by the BOARD-SCOPED lookup, so the card was never read: kanban card ids are GLOBAL across every board on the instance and `card#NNNN` is parsed out of author-controlled text, so an id naming another install\'s card reaches this handler intact. The mapped board itself reads back, which is what rules out this token having lost it. Nothing was written; the card id is in this log line only, never in the alert channel',
            self::REASON_MAPPED_BOARD_UNREADABLE => 'REFUSED — the card id is not on the mapped board AND the mapped board itself did not read back to this writeback token — it answered empty, or the probe of it failed — so board membership could not be established in either direction. Check the writeback token user\'s membership of the mapped board before reading this as a foreign card id; an unreadable board and a genuinely empty one are the same answer here. Nothing was read unscoped and nothing was written; the card id is in this log line only, never in the alert channel',
            self::REASON_ID_OUTSIDE_DECLARED_BOARDS => 'REFUSED — the card id is on NONE of the '.count($boardIds).' boards this mapping declares (checked, in this order: '.implode(', ', $boardIds).'), and every one of them read back, so the declared set was fully checked. Each was asked with a BOARD-SCOPED lookup and the card was never read, so THIS LINE DOES NOT AND MUST NOT SAY WHICH BOARD THE CARD IS ACTUALLY ON — that was not measured, and the only way to learn it is the unscoped read of an author-supplied id this check exists to prevent (card#8375). Either the card is not this install\'s, or this repo cites a board the mapping\'s `boards` list is missing — add it there. Nothing was written; the card id is in this log line only, never in the alert channel',
            default => 'REFUSED — the card id is on none of the '.count($boardIds).' boards this mapping declares (checked, in this order: '.implode(', ', $boardIds).') AND at least one of them did not read back to this writeback token — it answered empty, or the probe of it failed — so "not in the declared set" was never established. Check the writeback token user\'s membership of every declared board before reading this as a foreign card id; an unreadable board and a genuinely empty one are the same answer here. Nothing was read unscoped and nothing was written; the card id is in this log line only, never in the alert channel',
        };

        $alerts->warnAndNotifyCardIdWithheld(
            $message,
            ['card_id' => $cardId, 'repo' => $repo, 'mapped_board' => $mapping->boardId]
                + ($multi ? ['declared_boards' => $boardIds] : []),
            $repo, $outcome, $reason,
        );

        return true;
    }

    /**
     * WHICH OF THE DECLARED BOARDS, IF ANY, DOES THIS CARD ID RESOLVE ON — the pure lookup,
     * with no verdict, no message and no alert (card#9850 / DL-404).
     *
     * ⭐ IT IS PUBLIC BECAUSE IT HAS A SECOND CALLER AND MUST NOT HAVE A SECOND COPY.
     * `bridge:writeback-exposure` asks exactly this question — of card ids read out of an
     * install's own merged pull requests — and a second spelling of it would be a second
     * spelling of the archive rule (DL-296), of the read-the-verdict-off-the-ROWS rule
     * (DL-298) and of the row predicate itself. The two callers differ only in what they DO
     * with the three outcomes: {@see refusesCardIdOutsideMappedBoard} turns them into a
     * refusal and a report; the command turns them into `exposed` / `unreachable`.
     *
     * ⛔ THE TWO MISS CAUSES ARE RANKED HERE, ONCE, AND NEVER BOTH SET. A miss can have seen a
     * 4xx on one declared board AND a wrong-row answer on another (or on the same board's other
     * archive side). The 4xx governs — as the single-board lookup's 4xx always did, because it
     * ended that lookup before any verdict was read — so `unfiltered` is true only when nothing
     * was refused. Both callers used to receive both raw flags and rank them for themselves; the
     * guard ranked 4xx first and the exposure command ranked the wrong row first, so one card
     * got two causes and two remedies (card#9850, #770 r6). Returning at most one cause makes a
     * divergent ranking unwritable rather than merely untested. Pinned on the guard by
     * `WritebackTenantScopeTest` / `WritebackMultiBoardTest` `test_a_4xx_…_wrong_row_answer…`
     * and on the command by `WritebackExposureCommandTest::test_a_card_whose_lookup_was_both_refused_…`.
     *
     * The ARCHIVE switch is the OUTER loop, so the happy path is one request per declared
     * board and the archived probe still runs only after every live side has missed (kanban's
     * search excludes archived rows unless `?archived` is passed and offers no both-sides
     * mode, DL-296). With one declared board that is live-then-archived — exactly the
     * sequence this issued before the loop existed.
     *
     * ⛔ A TRANSIENT failure PROPAGATES, and that is deliberate on both callers: the handler
     * wants the 5xx that makes kanban redeliver, and the command wants to report the mapping
     * as unmeasured rather than as clean. Only a PERMANENT (4xx) refusal is captured — and a
     * board that could not be ASKED has not answered "no", so the loop keeps going: a positive
     * establishment on another declared board is a measurement that stands on its own, and
     * black-holing every card because one board's membership lapsed would be the widest
     * possible reading of a 4xx.
     *
     * @param  list<WritebackMapping>  $declared  the mapping once per declared board, in probe order
     * @return array{on: ?WritebackMapping, unfiltered: bool, refused: ?RequestException}
     *                                                                                    `on` — the declared board the card was established on,
     *                                                                                    null when none was; `refused` — the first PERMANENT
     *                                                                                    refusal of a declared board's lookup, meaning the set
     *                                                                                    was not fully asked; `unfiltered` — some board answered a
     *                                                                                    row that is NOT this card on that board, so kanban
     *                                                                                    narrowed on neither term and no verdict may be read out
     *                                                                                    of the answer at all, and NOTHING was refused (a refusal
     *                                                                                    outranks it — see above). Both are false/null when `on`
     *                                                                                    is set: a cause is a property of a miss
     */
    public static function locateOnDeclaredBoards(KanbanClient $client, array $declared, int $cardId): array
    {
        $answeredNoMatchingRow = false;
        /** @var ?RequestException the first PERMANENT refusal of a declared board's lookup */
        $lookupRefused = null;
        /** @var array<int, true> declared boards whose lookup kanban refused, keyed by board id */
        $unaskable = [];

        foreach ([false, true] as $archivedOnly) {
            foreach ($declared as $candidate) {
                if (isset($unaskable[$candidate->boardId])) {
                    // A 4xx is about this token's access to THIS BOARD, not about the archive
                    // switch, so a board that could not be asked once is not asked again. It is
                    // also what keeps a single-board mapping's request count at one.
                    continue;
                }
                try {
                    $rows = $client->cardRowsOnBoard($candidate->boardId, $cardId, $archivedOnly);
                } catch (RequestException $e) {
                    if (! RefusalContext::isPermanent($e)) {
                        throw $e;
                    }
                    $unaskable[$candidate->boardId] = true;
                    $lookupRefused ??= $e;

                    continue;
                }
                foreach ($rows as $row) {
                    if (self::namesCard($row, $cardId) && self::belongs($row, $candidate)) {
                        return ['on' => $candidate, 'unfiltered' => false, 'refused' => null];
                    }
                    // A row came back that is not this card on this board: whatever the query
                    // asked, the ANSWER is not narrowed, so no verdict can be read out of it.
                    $answeredNoMatchingRow = true;
                }
            }
        }

        // The ranking, and its only spelling: a refusal governs, so a wrong-row answer is
        // reported only when no declared board refused the lookup.
        return ['on' => null, 'unfiltered' => $lookupRefused === null && $answeredNoMatchingRow, 'refused' => $lookupRefused];
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
     * The CONTROL behind the "not in the declared set" verdicts: does EVERY declared board
     * read back to this token at all? Without it, a token that had lost a board would report
     * every card id as foreign — a wrong-but-specific accusation (canon #10) pointing the
     * operator at the PR author instead of at their own credential.
     *
     * ALL of them, not any (card#9850 / DL-404): the claim the strong verdict makes is that
     * the declared SET was checked and the id was in none of it, and one board this token
     * cannot see leaves that claim unearned. With one declared board this is exactly the
     * single `visibility` probe it has always been.
     *
     * Diagnostic only, so it never changes WHETHER the move is refused — only which reason it
     * is refused under. A failure therefore reaches the weaker verdict rather than propagating:
     * throwing here would turn a permanent refusal into a 5xx redelivery storm, which is the
     * anti-pattern every arm in this file exists to avoid.
     *
     * @param  list<WritebackMapping>  $declared  the mapping once per declared board
     */
    private static function everyDeclaredBoardReadsBack(KanbanClient $client, array $declared): bool
    {
        foreach ($declared as $candidate) {
            try {
                if ($client->visibility($candidate->boardId)['total'] <= 0) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
