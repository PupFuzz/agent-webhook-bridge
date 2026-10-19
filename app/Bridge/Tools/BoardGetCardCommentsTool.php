<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\KanbanFieldLimits;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * board_get_card_comments (card#12010) — READ one card's comments, newest first, a page at a time. A
 * read tool on the board-tools door; {@see BoardToolsRegistry} is the set that door offers, and
 * `docs/board-tools.md`'s tool table is held against it, so neither is restated here.
 *
 * ⭐ WHAT IT IS FOR: a seat that records results as card comments ({@see BoardCommentCardTool})
 * could not read them back — no other tool projects comments — so it derived owed work from
 * elsewhere and asked for work whose results were already on the card.
 *
 * ⭐ THE REACH IS `board_get_cards`' REACH FOR ONE ID: a card on this agent's configured board, live
 * or archived, in any lane, established through {@see BoardScopedRow::lookUp} before the card is read
 * at all (DL-323: the id is caller-supplied against an id space global across the instance). Anything
 * else — another board, a trashed card, no such id, or a board the writeback token cannot see (one
 * answer from kanban's search) — is refused `out_of_scope`, with nothing of that card read.
 * `board_get_cards` tells `other_board` from `not_found` with an extra by-id read; a refusal here
 * names no status, so it does not pay for that read.
 *
 * ⛔ THE COMMENTS COME FROM THE FULL TASK READ ({@see KanbanClient::getCard}) — kanban has no comment
 * read route (`GET` on `tasks/{id}/comments.json` is not routed; that path is POST-only), and the
 * task read returns the card's WHOLE comment history, unpaged, oldest first. So the paging here is
 * the bridge's own, over that one list, and every page costs the same two requests.
 *
 * ⛔ A READ THAT FAILED IS NEVER AN EMPTY PAGE. A 2xx the comments cannot be read out of — no
 * `comments` list, a row without an integer id, a string body or a readable `created_at` — is refused
 * `comments_unreadable`; an empty list is a card with none. That is the whole of the incident this
 * tool closes: an answer of "no comments" for a card whose comments were not read.
 *
 * ⛔ `writer` IS AUTHENTICATED BY THE KANBAN AUTHOR, NOT BY THE TEXT. The `FROM: <seat>` line
 * ({@see CommentAttribution}) can be typed by anyone who can comment, so a comment names a writer
 * only when its kanban author is the user the writeback token resolves to — the author of every
 * `board_comment_card` write ({@see writebackUser}). When that user cannot be resolved, no comment
 * names one.
 *
 * ⚠ THE CURSOR IS KEYED ON THE LAST COMMENT RETURNED — its `created_at` and its id — not on an
 * offset, so a comment posted between two pages is not returned twice and pushes nothing older off
 * the walk. A comment deleted mid-walk is simply not there.
 */
final class BoardGetCardCommentsTool implements Tool
{
    /**
     * A page when `limit` is not given. CHOSEN, not measured: kanban caps one comment at
     * {@see KanbanFieldLimits::COMMENT_MAX} characters, so no count of
     * comments bounds the response's bytes, and this repo holds no measurement of real comment
     * sizes. Ten keeps a page of short result notes inside the 16 KiB single-list budget the door
     * sizes its other reads to ({@see BoardMyCardsTool::DEFAULT_MAX_CARDS}).
     */
    public const DEFAULT_LIMIT = 10;

    /** `writer_check`: the writeback user was resolved, and each `writer` was checked against it. */
    public const WRITER_VERIFIED = 'verified';

    /** `writer_check`: no comment on the page opens with the bridge's line, so nothing was asked. */
    public const WRITER_NO_LINE = 'no_attribution_line';

    /** `writer_check`: the writeback user could not be resolved, so every `writer` is null. */
    public const WRITER_UNAVAILABLE = 'unavailable';

    /** The largest page a call may ask for — CHOSEN, for the same reason as {@see DEFAULT_LIMIT}. */
    public const MAX_LIMIT = 50;

    /** `since` — an RFC 3339 date-time: a `T`, seconds, an optional fraction, and an offset that is not optional. */
    private const TIMESTAMP = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/';

    public function name(): string
    {
        return 'board_get_card_comments';
    }

    public function acceptedArguments(): array
    {
        return ['card_id', 'limit', 'cursor', 'since'];
    }

    public function refusedArgumentReason(string $key): ?string
    {
        return null;
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        // Arguments first, then the board — so a refused call reads nothing.
        $cardId = $this->requireCardId($args);
        $limit = $this->limit($args);
        [$since, $sinceRaw] = $this->since($args);
        $after = $this->cursor($args, $cardId);
        $boardId = (int) $cfg->boardId;

        $status = $this->requireCardOnBoard($client, $boardId, $cardId, $agentName);

        try {
            $task = $client->getCard($cardId);
        } catch (RequestException $e) {
            throw $this->taskReadRefusal($e, $cardId, $agentName);
        }

        $comments = $this->readComments($task, $boardId, $cardId, $agentName);
        if ($since !== null) {
            $comments = array_values(array_filter($comments, static fn (array $c): bool => $c['at'] > $since));
        }
        usort($comments, static fn (array $a, array $b): int => [$b['at'], $b['id']] <=> [$a['at'], $a['id']]);
        $total = count($comments);

        if ($after !== null) {
            $comments = array_values(array_filter($comments, static fn (array $c): bool => [$c['at'], $c['id']] < [$after['at'], $after['id']]));
        }
        $page = array_slice($comments, 0, $limit);
        $last = $page === [] ? null : $page[array_key_last($page)];
        [$writerCheck, $writebackUserId] = $this->writebackUser($client, $page, $cardId, $agentName);

        return [
            'card_id' => $cardId,
            // OBSERVED: the task read answered this board, or nothing was returned.
            'board_id' => $boardId,
            'card_status' => $status,
            'since' => $sinceRaw,
            'comments' => array_map(static fn (array $c): array => $c['out'] + [
                'writer' => $writebackUserId !== null && $c['out']['author']['user_id'] === $writebackUserId ? $c['claimed'] : null,
            ], $page),
            'writer_check' => $writerCheck,
            'writeback_user_id' => $writebackUserId,
            'total' => $total,
            'next_cursor' => $last !== null && count($comments) > $limit ? self::encodeCursor($cardId, $last['at'], $last['id']) : null,
        ];
    }

    /**
     * `found` or `archived` ({@see BoardGetCardsTool::STATUSES}) for a card on this board; refuses
     * anything else.
     */
    private function requireCardOnBoard(KanbanClient $client, int $boardId, int $cardId, string $agentName): string
    {
        try {
            $found = BoardScopedRow::lookUp($client, $boardId, $cardId, $this->name(), $agentName);
        } catch (RequestException $e) {
            throw $this->readRefusal($e, $agentName, BoardReadRoute::Search, "your board to establish that card {$cardId} is on it");
        }

        if ($found->live !== null) {
            return BoardGetCardsTool::STATUS_FOUND;
        }
        if ($found->archived !== null) {
            return BoardGetCardsTool::STATUS_ARCHIVED;
        }

        Log::warning('board_get_card_comments: refused — no card with this id is on the agent\'s board', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
        ]);

        throw new ToolRefusalException("board_get_card_comments: card {$cardId} is not on your board {$boardId} — this tool reads comments only on cards on your own board (live or archived, any lane), so NO comments were returned. A card on another board, a card in kanban's trash and an id no card has are one answer here. ⚠ A board the bridge's writeback token is not a MEMBER of answers exactly the same way: kanban's search returns zero rows rather than an error — if you believe this card is on your board, have your operator check that token's membership of board {$boardId}.", reason: 'out_of_scope');
    }

    /**
     * Every comment on the task read, each as `{id, at, out}` — `at` for ordering and the `since` /
     * cursor compares, `out` the shape returned. Refuses a body that does not show it is this card on
     * this board, and one whose comments cannot be read.
     *
     * @param  array<string, mixed>  $task
     * @return list<array{id: int, at: \DateTimeImmutable, claimed: ?string, out: array{id: int, author: array{user_id: ?int}, created_at: string, body: string}}>
     */
    private function readComments(array $task, int $boardId, int $cardId, string $agentName): array
    {
        $board = $task['board_id'] ?? null;
        if (($task['id'] ?? null) !== $cardId || ! is_int($board)) {
            $this->unreadable($cardId, $agentName, 'the card read did not answer for this card');
        }
        if ($board !== $boardId) {
            // The board-scoped lookup placed it here a moment ago: it moved in between.
            Log::warning('board_get_card_comments: refused — the card left the agent\'s board between the lookup and the read', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
            ]);

            throw new ToolRefusalException("board_get_card_comments: card {$cardId} is no longer on your board {$boardId} — it moved between the bridge's board check and its read of the card, so NO comments were returned.", reason: 'out_of_scope');
        }

        $rows = $task['comments'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->unreadable($cardId, $agentName, 'the card read carried no comment list');
        }

        $comments = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            $userId = is_array($row) && array_key_exists('user_id', $row) ? $row['user_id'] : false;
            $body = is_array($row) ? ($row['content'] ?? null) : null;
            $createdAt = is_array($row) ? ($row['created_at'] ?? null) : null;
            $at = is_string($createdAt) ? self::timestamp($createdAt) : null;
            // A null user_id is kanban's deleted account (its DL-0331): the comment is kept, and so is it here.
            if (! is_int($id) || ($userId !== null && ! is_int($userId)) || ! is_string($body) || $at === null) {
                $this->unreadable($cardId, $agentName, 'a comment on the card read had no readable id, author, body or created_at');
            }
            /** @var string $createdAt */
            $comments[] = ['id' => $id, 'at' => $at, 'claimed' => CommentAttribution::writer($body), 'out' => [
                'id' => $id,
                'author' => ['user_id' => $userId],
                'created_at' => $createdAt,
                'body' => $body,
            ]];
        }

        return $comments;
    }

    /**
     * Whether the page's attribution lines can be believed, and the id they are believed for: a
     * comment's `writer` is its claimed seat ONLY when its kanban author is the user the
     * writeback token IS — the author of every `board_comment_card` write. That user is asked of
     * kanban ({@see KanbanClient::currentUser}), never taken from `writeback.json`'s
     * `identity_id`, which is a declaration an operator wrote and not what authored the comment.
     * Asked once, and only when some comment on the page carries the line. A failure answers
     * `unavailable` with no id, so no comment names a writer — it does not refuse the read.
     *
     * @param  list<array{claimed: ?string}>  $page
     * @return array{string, ?int}
     */
    private function writebackUser(KanbanClient $client, array $page, int $cardId, string $agentName): array
    {
        if (array_filter($page, static fn (array $c): bool => $c['claimed'] !== null) === []) {
            return [self::WRITER_NO_LINE, null];
        }
        $resolution = $client->currentUser();
        if ($resolution->identity === null) {
            Log::warning('board_get_card_comments: the writeback user could not be resolved, so no comment names a writer', [
                'agent' => $agentName, 'card_id' => $cardId, 'cause' => $resolution->failure,
            ]);

            return [self::WRITER_UNAVAILABLE, null];
        }

        return [self::WRITER_VERIFIED, $resolution->identity->id];
    }

    private function unreadable(int $cardId, string $agentName, string $what): never
    {
        Log::warning('board_get_card_comments: refused — the card read answered 2xx and its comments could not be read', [
            'agent' => $agentName, 'card_id' => $cardId, 'what' => $what,
        ]);

        throw new ToolRefusalException("board_get_card_comments: the board answered the read of card {$cardId}, but {$what}, so the bridge cannot say what comments the card has — NO comments were returned. This is not an empty card. It is an INSTALL fault (the board is answering a shape the bridge does not recognise); report it to your operator.", installFault: true, reason: 'comments_unreadable');
    }

    /** A failure of the task read: 404 is a card gone mid-call; the rest is the door's shared classification. */
    private function taskReadRefusal(RequestException $e, int $cardId, string $agentName): \Throwable
    {
        if ($e->response->status() === 404) {
            return new ToolRefusalException("board_get_card_comments: card {$cardId} no longer exists — it was removed (or moved to kanban's trash) between the board check and the read, so NO comments were returned.", reason: 'card_gone');
        }

        return $this->readRefusal($e, $agentName, BoardReadRoute::TaskDetail, "the comments of card {$cardId}");
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

        Log::warning('board_get_card_comments: the board refused a read', [
            'agent' => $agentName, 'route' => $route->name, 'status' => $status,
        ]);

        return BoardCallRefusal::readRefusal($this->name(), $route, $status, $what, 'so NO comments were returned');
    }

    /** @param  array<string, mixed>  $args */
    private function requireCardId(array $args): int
    {
        $cardId = $args['card_id'] ?? null;
        // is_int, not is_numeric — the rule every card-id tool on this door states: a coerced id can name a DIFFERENT card.
        if (! is_int($cardId) || $cardId < 1) {
            throw new ToolRefusalException('board_get_card_comments: `card_id` is required and must be a positive integer (the id `board_my_cards` reports for the card) — a decorated string or a float is refused, never coerced. Nothing was sent to the board.', reason: 'bad_arguments');
        }

        return $cardId;
    }

    /** @param  array<string, mixed>  $args */
    private function limit(array $args): int
    {
        if (! array_key_exists('limit', $args)) {
            return self::DEFAULT_LIMIT;
        }
        $limit = $args['limit'];
        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new ToolRefusalException('board_get_card_comments: `limit` must be an integer from 1 to '.self::MAX_LIMIT.' when provided — it is the number of comments in one page, newest first (default '.self::DEFAULT_LIMIT.'). Use `cursor` for the next page. Nothing was sent to the board.', reason: 'bad_arguments');
        }

        return $limit;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{?\DateTimeImmutable, ?string}
     */
    private function since(array $args): array
    {
        if (! array_key_exists('since', $args)) {
            return [null, null];
        }
        $raw = $args['since'];
        $at = is_string($raw) ? self::timestamp($raw) : null;
        if ($at === null) {
            throw new ToolRefusalException('board_get_card_comments: `since` must be an ISO-8601 timestamp with a time and an offset, e.g. `2026-10-19T14:00:00Z` or `2026-10-19T16:00:00+02:00` — '.json_encode($raw).' is not one. A date alone or a time with no offset is refused rather than guessed at. Only comments created AFTER it are returned. Nothing was sent to the board.', reason: 'bad_arguments');
        }

        return [$at, $raw];
    }

    /**
     * The position after which the page starts, or null for the first page.
     *
     * @param  array<string, mixed>  $args
     * @return array{at: \DateTimeImmutable, id: int}|null
     */
    private function cursor(array $args, int $cardId): ?array
    {
        if (! array_key_exists('cursor', $args)) {
            return null;
        }
        $raw = $args['cursor'];
        $decoded = is_string($raw) ? json_decode((string) base64_decode(strtr($raw, '-_', '+/'), true), true) : null;
        $at = is_array($decoded) && is_string($decoded['at'] ?? null) ? self::timestamp($decoded['at']) : null;
        if (! is_array($decoded) || array_keys($decoded) !== ['card', 'at', 'id'] || ! is_int($decoded['card']) || ! is_int($decoded['id']) || $at === null) {
            throw new ToolRefusalException('board_get_card_comments: `cursor` is not one this tool issued — pass back the `next_cursor` of the previous page unchanged, or omit it for the first page. Nothing was sent to the board.', reason: 'bad_arguments');
        }
        if ($decoded['card'] !== $cardId) {
            throw new ToolRefusalException("board_get_card_comments: `cursor` was issued for card {$decoded['card']}, not card {$cardId} — a cursor continues the walk of the card it came from. Nothing was sent to the board.", reason: 'bad_arguments');
        }

        return ['at' => $at, 'id' => $decoded['id']];
    }

    private static function encodeCursor(int $cardId, \DateTimeImmutable $at, int $id): string
    {
        // base64url: padding only ever ends the string, so dropping every `=` is dropping the padding.
        return str_replace('=', '', strtr(base64_encode((string) json_encode(['card' => $cardId, 'at' => $at->format('Y-m-d\TH:i:s.uP'), 'id' => $id])), '+/', '-_'));
    }

    /** An RFC 3339 date-time that names a real instant, or null — a rolled-over day (Feb 30) included. */
    private static function timestamp(string $value): ?\DateTimeImmutable
    {
        if (preg_match(self::TIMESTAMP, $value) !== 1) {
            return null;
        }
        $parsed = date_parse($value);
        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }
}
