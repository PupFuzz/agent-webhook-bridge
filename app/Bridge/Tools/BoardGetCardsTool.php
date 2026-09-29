<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * board_get_cards (card#10832, DL-435) — read N KNOWN card ids in one call, each answered with an
 * explicit `status`. A read tool on the board-tools door; {@see BoardToolsRegistry} is the set that
 * door offers, and `docs/board-tools.md`'s tool table is held against it, so neither is restated here.
 *
 * ⛔ THE CONTRACT IS N IN, N OUT. Every requested id comes back as exactly one entry, in request
 * order, with one of {@see STATUSES}. An id is never omitted: a seat asking after ten cards and
 * getting nine back cannot tell a missing card from a dropped one, and that silent omission —
 * `board_my_cards` leaving out every card outside the seat's lane, column window or archive side —
 * is what this tool exists to end (rt#572). Where the bridge cannot establish a status for an id,
 * the WHOLE call is refused; a partial answer with an unstated hole is the defect, not a fallback.
 *
 * ⭐ HOW EACH STATUS IS ESTABLISHED, in the order it is asked:
 *   1. {@see BoardScopedRow::lookUp} — the board-scoped search every card-id tool on this door uses
 *      (DL-323): live side, then archived side on a live miss. A row there is `found` or `archived`,
 *      and ONLY such a row's content is ever returned.
 *   2. Only after step 1 has run for EVERY id, and only for an id it missed on both sides,
 *      {@see KanbanClient::cardBoardId} — the unscoped by-id read, which
 *      hands back a board id and nothing else. Another board ⇒ `other_board`; 404 ⇒ `not_found`
 *      (no such id, or in kanban's trash — kanban answers both the same, before authorization);
 *      403 ⇒ `other_board`, but only once {@see ownBoardReadable} has shown the same token may read
 *      this board — see there for why that control is not optional.
 *
 * ⚠ IT CROSSES LANES, deliberately — the second read on this door that does (after DL-383's `tag`
 * read). A card id is caller-named on the caller's own board, so there is no wider population to
 * leak; a card on ANOTHER board is reported as `other_board` with no content and no board id.
 *
 * ⚠ NO WINDOW, and therefore no `truncated` / `total_is_lower_bound`: nothing is cut, because the
 * request itself is bounded ({@see MAX_IDS}). The honesty contract here is N in, N out.
 */
final class BoardGetCardsTool implements Tool
{
    public const STATUS_FOUND = 'found';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_OTHER_BOARD = 'other_board';

    public const STATUS_NOT_FOUND = 'not_found';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_FOUND, self::STATUS_ARCHIVED, self::STATUS_OTHER_BOARD, self::STATUS_NOT_FOUND];

    /**
     * The most ids one call may name. BORROWED, not chosen: {@see BoardMyCardsTool::DEFAULT_MAX_CARDS}
     * is derived from measured titles-only card sizes against a 16 KiB list budget.
     *
     * ⚠ IT BOUNDS THE CARD COUNT, NOT THIS TOOL'S RESPONSE SIZE. This tool's `{id,status,card}`
     * wrapper, plus the `swimlane_id` / `position` keys it adds beyond `board_my_cards`' card, cost
     * about 66 bytes/card more (measured against a realistic fixture by diffing the two shapes'
     * `json_encode` length; stable within a byte or two across a short and a 90-character card
     * name, since the overhead is fixed keys and small values, not card content). Applied to the
     * 310.3 chars/card `DEFAULT_MAX_CARDS` was sized against (the larger of its two live
     * measurements — see that constant), a full default answer here runs roughly a fifth over the
     * same 16 KiB budget. Soft, like that budget itself ("a bound, not a promise" — `board-tools.md`
     * § The default is capped): nothing enforces it, and no reply is truncated for it.
     *
     * It also bounds the upstream fan-out: at most `3 × MAX_IDS + 1` requests — 3 per id (live,
     * archived, by-id), plus at most one shared visibility-control request per call — asked only
     * when no id resolved on this board and some id 403s. A stage read never
     * adds to that ceiling: it is paid only when some id resolves in fewer than 3 reads (`found` or
     * `archived`), which is never true on a call where every id misses.
     *
     * ⚠ THAT FAN-OUT SHARES A SCARCE BUDGET: kanban rate-limits 300 req/min per AUTHENTICATED
     * USER (source-read, kanban `origin/dev` 4688b543, `RateLimiter::for('api', …)` in
     * `app/Providers/AppServiceProvider.php`), and this tool, every other board-tools call, and
     * the writeback all authenticate as the ONE writeback user — so the worst case above draws on
     * a budget the writeback's own card moves draw on too, and a 429 the writeback hits mid-move is
     * currently dropped as a PERMANENT failure rather than retried (card#10849; not addressed
     * here).
     */
    public const MAX_IDS = BoardMyCardsTool::DEFAULT_MAX_CARDS;

    public function name(): string
    {
        return 'board_get_cards';
    }

    public function acceptedArguments(): array
    {
        return ['ids', 'fields'];
    }

    public function refusedArgumentReason(string $key): ?string
    {
        return BoardCardProjection::refusedDescriptionArgument($key);
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        // Arguments first, then the board — so a refused call reads nothing.
        $ids = $this->requireIds($args);
        $fields = BoardCardProjection::fieldsArgument($args, $this->name());
        $boardId = (int) $cfg->boardId;

        // Every board-scoped lookup first, so the placement step below knows whether the call has
        // already proven it can read this board — see forbiddenVerdict().
        /** @var array<int, array{status: string, row: array<string, mixed>}|null> $scoped */
        $scoped = [];
        foreach ($ids as $id) {
            $scoped[$id] = $this->scopedVerdict($client, $boardId, $id, $agentName);
        }

        // A row resolved on this board IS the membership proof (kanban's search floors to
        // membership), so the control is asked only when no id resolved, at most once per call, and
        // only if some id 403s.
        $membership = new BoardMembershipControl($client, $boardId);
        if (array_filter($scoped) !== []) {
            $membership->proven();
        }
        $ownBoardReadable = fn (): bool => $this->ownBoardReadable($membership, $boardId, $agentName);
        /** @var array<int, array{status: string, row?: array<string, mixed>}> $verdicts */
        $verdicts = [];
        foreach ($ids as $id) {
            $verdicts[$id] = $scoped[$id] ?? $this->placedVerdict($client, $boardId, $id, $agentName, $ownBoardReadable);
        }

        $stageNames = [];
        if (in_array('stage', $fields, true) && array_filter($verdicts, fn (array $v): bool => isset($v['row'])) !== []) {
            try {
                $stageNames = $client->boardStageNames($boardId);
            } catch (RequestException $e) {
                throw $this->readRefusal($e, $agentName, BoardReadRoute::BoardScoped, "the stages of your board {$boardId}");
            }
        }
        $descriptionCap = in_array(BoardCardProjection::OPT_IN_FIELD, $fields, true) ? $cfg->descriptionMaxBytes : null;

        $cards = [];
        foreach ($ids as $id) {
            $entry = ['id' => $id, 'status' => $verdicts[$id]['status']];
            if (isset($verdicts[$id]['row'])) {
                $row = $verdicts[$id]['row'];
                $projected = BoardCardProjection::select(
                    BoardCardProjection::withPosition(BoardCardProjection::withSwimlane(BoardCardProjection::project($row, $stageNames, $descriptionCap), $row), $row),
                    $fields,
                );
                // `fields: []` selects nothing: OMIT `card` rather than send an empty value, so
                // "statuses only" is literally true and `card` never changes JSON type (object on
                // every other call) depending on what was asked for.
                if ($projected !== []) {
                    $entry['card'] = $projected;
                }
            }
            $cards[] = $entry;
        }

        return [
            'configured_board_id' => $boardId,
            'fields' => $fields,
            'cards' => $cards,
        ];
    }

    /**
     * Step 1: `found` / `archived` off the board-scoped lookup, or null when it missed on both sides.
     *
     * @return array{status: string, row: array<string, mixed>}|null
     */
    private function scopedVerdict(KanbanClient $client, int $boardId, int $id, string $agentName): ?array
    {
        try {
            $scoped = BoardScopedRow::lookUp($client, $boardId, $id, $this->name(), $agentName);
        } catch (RequestException $e) {
            throw $this->readRefusal($e, $agentName, BoardReadRoute::Search, "your board to establish where card {$id} is");
        }
        if ($scoped->live !== null) {
            return ['status' => self::STATUS_FOUND, 'row' => $scoped->live];
        }
        if ($scoped->archived !== null) {
            return ['status' => self::STATUS_ARCHIVED, 'row' => $scoped->archived];
        }

        return null;
    }

    /**
     * Step 2, for an id step 1 missed on both sides: the by-id read places it.
     *
     * @param  \Closure(): bool  $ownBoardReadable  {@see ownBoardReadable}, memoised for this call
     * @return array{status: string}
     */
    private function placedVerdict(KanbanClient $client, int $boardId, int $id, string $agentName, \Closure $ownBoardReadable): array
    {
        try {
            $onBoard = $client->cardBoardId($id);
        } catch (RequestException $e) {
            // Only the two statuses the by-id read is documented to answer as a VERDICT are consumed;
            // anything else (a 5xx, a 401 from a token revoked mid-call) keeps the dispatcher's
            // retryable 502, and a persistent 401 is then named by the next call's first search.
            return match ($e->response->status()) {
                404 => ['status' => self::STATUS_NOT_FOUND],
                403 => $this->forbiddenVerdict($boardId, $id, $agentName, $ownBoardReadable),
                default => throw $e,
            };
        }

        if ($onBoard === $boardId || $onBoard === null) {
            // Either the card IS on this board and the board-scoped search did not return it —
            // the shape kanban would produce if its VIEW authorization and its SEARCH scope
            // disagreed about who may read this board (on current kanban, source-read, the two
            // agree for an API token — see `BoardMembershipControl`'s docblock — so this is a
            // defence against a kanban version or configuration where they do not, not a live case
            // here) — or kanban answered 2xx with no board id at all. Neither is a status; both are
            // refused rather than guessed.
            Log::warning('board_get_cards: the by-id read and the board-scoped search disagree — refusing without a verdict', [
                'agent' => $agentName, 'card_id' => $id, 'board_id' => $boardId, 'by_id_board' => $onBoard,
            ]);

            throw new ToolRefusalException("board_get_cards: card {$id} could not be placed — the board-scoped search of your board {$boardId} did not return it, and the by-id read ".($onBoard === null ? 'answered without a board id' : 'says it IS on that board').'. That is a BROKEN READ, not a status, so NO cards were returned. The likely cause is a kanban whose VIEW authorization and its SEARCH scope disagree about who may read this board. This is an INSTALL fault; report it to your operator.', installFault: true);
        }

        return ['status' => self::STATUS_OTHER_BOARD];
    }

    /**
     * A 403 on the by-id read: a task carries the id, on a board the token's user may not view.
     *
     * ⛔ THAT IS `other_board` ONLY IF THIS BOARD IS ONE THE USER MAY VIEW: kanban's search answers a
     * non-member ZERO ROWS, not an error, so a writeback user that is not a member of this board
     * misses every card on it at step 1 and then 403s on each one here — and every card on the
     * seat's own board would come back `other_board`. Any id of THIS call that step 1 resolved is
     * proof enough (the search that returned it floors to membership), and every step-1 lookup runs
     * before any placement, so that proof is in hand whatever order the ids came in. Only when no
     * id resolved is the board asked, once ({@see ownBoardReadable}); when kanban refuses the token a
     * read of it (403) the call is refused. A readable board, empty or not, makes the 403 `other_board`.
     *
     * @param  \Closure(): bool  $ownBoardReadable  {@see ownBoardReadable}, memoised for this call
     * @return array{status: string}
     */
    private function forbiddenVerdict(int $boardId, int $id, string $agentName, \Closure $ownBoardReadable): array
    {
        if ($ownBoardReadable()) {
            return ['status' => self::STATUS_OTHER_BOARD];
        }

        Log::warning('board_get_cards: a card id 403s and the token may not read the agent\'s own board — refusing without a verdict', [
            'agent' => $agentName, 'card_id' => $id, 'board_id' => $boardId,
        ]);

        throw new ToolRefusalException("board_get_cards: card {$id} exists on a board the bridge's writeback token may not read, and kanban refuses (403) that same token a read of your board {$boardId} — the token's user is not a MEMBER of it (or the board is trashed), so the bridge cannot say whether card {$id} is on your board or another one. NO cards were returned. Have your operator check that token's membership of board {$boardId}.");
    }

    /** The membership control {@see forbiddenVerdict} needs ({@see BoardMembershipControl}). */
    private function ownBoardReadable(BoardMembershipControl $membership, int $boardId, string $agentName): bool
    {
        try {
            return $membership->readable();
        } catch (RequestException $e) {
            throw $this->readRefusal($e, $agentName, BoardReadRoute::BoardScoped, "the status of your board {$boardId} to establish that the token can read it");
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<int>
     */
    private function requireIds(array $args): array
    {
        $ids = $args['ids'] ?? null;
        $shape = 'board_get_cards: `ids` is required and must be a non-empty list of positive integers (card ids as `board_my_cards` reports them), at most '.self::MAX_IDS.' per call';
        if (! is_array($ids) || $ids === [] || ! array_is_list($ids)) {
            throw new ToolRefusalException($shape.'.');
        }
        if (count($ids) > self::MAX_IDS) {
            throw new ToolRefusalException($shape.' — this call names '.count($ids).'. Split it across calls.');
        }
        foreach ($ids as $id) {
            // is_int, not is_numeric — the rule every card-id tool on this door states: a coerced id can name a DIFFERENT card.
            if (! is_int($id) || $id < 1) {
                throw new ToolRefusalException($shape.' — '.json_encode($id).' is not one; a decorated string or a float is refused, never coerced.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new ToolRefusalException('board_get_cards: `ids` names the same card more than once — each id is answered by exactly one entry, so a repeat is refused rather than silently collapsed. Send each id once.');
        }

        return $ids;
    }

    /**
     * A 4xx the BOARD answered on one of this tool's reads, mapped to a named refusal. Which
     * statuses refuse and which are re-thrown is {@see BoardCallRefusal}'s.
     */
    private function readRefusal(RequestException $e, string $agentName, BoardReadRoute $route, string $what): \Throwable
    {
        $status = BoardCallRefusal::permanentOnRead($e);
        if ($status === null) {
            return $e;
        }

        Log::warning('board_get_cards: the board refused a read', [
            'agent' => $agentName, 'route' => $route->name, 'status' => $status,
        ]);

        return BoardCallRefusal::readRefusal($this->name(), $route, $status, $what, 'so NO cards were returned');
    }
}
