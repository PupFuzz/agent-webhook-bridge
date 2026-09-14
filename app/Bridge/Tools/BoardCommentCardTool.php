<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\KanbanFieldLimits;
use App\Bridge\Writeback\PinGuard;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * board_comment_card (DL-381, card#9459) — APPEND a comment to a card on the calling seat's own
 * board. A write tool on the board-tools door; {@see BoardToolsRegistry} is the set that door
 * offers, and `docs/board-tools.md`'s tool table is held against it, so neither is restated here.
 *
 * ⭐ WHAT IT IS FOR: the only other way a seat could add a note to a card was
 * {@see BoardCorrectCardTool}, whose `description` REPLACES the body — so an append needed the
 * card's existing bytes first, and `board_my_cards` windows its read. For a card outside that
 * window the only available write deleted the card's contents (rt#485). A comment is a new ROW
 * under the card: it needs no read of the body and cannot overwrite anything.
 *
 * ⭐ THE SCOPE IS THE BOARD, AND ONLY THE BOARD. The card must be on this agent's configured
 * board — established through {@see KanbanClient::cardRowsOnBoard} and read off the rows by
 * {@see BoardScopedRow}, never through the unscoped `getCard()` (DL-323: the id is
 * caller-supplied against an id space that is global across the instance). There is no minted,
 * assigned or lane requirement: a comment changes nothing on the card and is attributed, so the
 * relations the correction and take tools rest on have nothing to protect here. ⛔ OUT of scope,
 * each refused: a card on any other board (the coord board included — this door has never written
 * there), an ARCHIVED card (kanban itself would accept the comment; the bridge refuses, as the
 * correction and take tools do, because commenting on a retire is not work this door serves), and
 * a trashed card (kanban's search does not return it, so it is refused as not on the board).
 *
 * ⛔ ATTRIBUTION IS THE BRIDGE'S. Every seat writes through the one writeback user, so the
 * comment's kanban author says nothing about which seat wrote it. The body's FIRST line is
 * `FROM: <agent>`, where the agent is the name the front door derived — the same value
 * `board_create_card` stamps as `created-by:` — and no argument can name it ({@see
 * ATTRIBUTION_ARGS} only picks the refusal's message). A caller may write its own `FROM:` line in
 * the text; it lands after the bridge's and cannot displace it.
 *
 * ⛔ APPEND-ONLY. The accept set is `card_id` + `content`; there is no argument that names an
 * existing comment, so there is no expressible edit or delete ({@see EDIT_ARGS} again only picks
 * the message).
 *
 * CONTENT follows the door's string posture ({@see BoardToolArgs}): blank once trimmed is refused,
 * the trimmed value is what is stored, and the length bound reads the value AS SENT — conservative,
 * so this door never accepts over ssh a padded value it would refuse. The bound is
 * {@see KanbanFieldLimits::COMMENT_MAX} over the whole body sent, attribution line included,
 * because that body is what kanban's validator measures. Kanban renders comment markdown; the
 * bridge passes the text through as it does a card description.
 *
 * ⚠ THE PIN DOES NOT GOVERN THIS WRITE. {@see PinGuard::PINNED_FIELDS} is a FIELD rule and a
 * comment writes no field; a human who froze a card is not harmed by a note appearing under it.
 *
 * ⚠ NO IDEMPOTENCY — THE SAME CHOICE AS `board_correct_card`, AND ITS COST IS DIFFERENT HERE. A
 * correction re-sent writes the same value twice; a comment re-sent after a retryable 502 whose
 * POST had in fact landed posts it TWICE. The window is a board fault on the one POST, and a
 * duplicate note is visible and harmless to the card's state. Stated rather than hidden.
 *
 * REFUSALS ARE DETERMINISTIC: a permanent board 4xx on the lookup or the write is a named
 * refusal ({@see BoardCallRefusal}), never the dispatcher's retryable 502.
 */
final class BoardCommentCardTool implements Tool
{
    /** ⚠ One constant: the HTTP door's `ConvertEmptyStringsToNull` makes a blank value a non-string, the ssh door sees it blank — one refusal either way. */
    private const CONTENT_REFUSAL = 'board_comment_card: `content` is required and must be a non-empty string (the text of the comment)';

    /**
     * Arguments that try to say WHO wrote the comment. Message quality only — the accept set is
     * the boundary.
     *
     * @var list<string>
     */
    private const ATTRIBUTION_ARGS = ['from', 'author', 'agent', 'agent_name', 'seat', 'user', 'user_id', 'kanban_user_id', 'as', 'signature'];

    /**
     * Arguments that assume a comment can be changed after it is written. Message quality only.
     *
     * @var list<string>
     */
    private const EDIT_ARGS = ['comment_id', 'edit', 'update', 'replace', 'delete', 'remove'];

    public function name(): string
    {
        return 'board_comment_card';
    }

    public function acceptedArguments(): array
    {
        return ['card_id', 'content'];
    }

    public function refusedArgumentReason(string $key): ?string
    {
        $lower = strtolower($key);
        if (in_array($lower, self::ATTRIBUTION_ARGS, true)) {
            return "`{$key}` is not an argument here — the comment is attributed to YOU, on its first line, from the bridge identity your call authenticated as, never from anything you send.";
        }
        if (in_array($lower, self::EDIT_ARGS, true)) {
            return "`{$key}` is not an argument here — this tool only APPENDS a new comment; nothing on this door edits or deletes one.";
        }

        return null;
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        // Arguments first, then the board — so a refused call reads nothing and writes nothing.
        $cardId = $this->requireCardId($args);
        $body = $this->attributedBody($args, $agentName);

        $boardId = (int) $cfg->boardId;
        $this->requireCardOnBoard($client, $boardId, $cardId, $agentName);

        try {
            $client->addComment($cardId, $body);
        } catch (RequestException $e) {
            throw $this->writeRefusal($e, $cardId, $agentName);
        }

        Log::info('board_comment_card: commented', ['agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId]);

        return [
            'commented' => true,
            'card_id' => $cardId,
            // OBSERVED: the row was accepted only because its own `board_id` was this value.
            'board_id' => $boardId,
            'attributed_to' => $agentName,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function requireCardId(array $args): int
    {
        $cardId = $args['card_id'] ?? null;
        // is_int, not is_numeric — the rule the other card-id tools state: a coerced id can name a
        // DIFFERENT card.
        if (! is_int($cardId) || $cardId < 1) {
            throw new ToolRefusalException('board_comment_card: `card_id` is required and must be a positive integer (the id `board_my_cards` reports for the card)');
        }

        return $cardId;
    }

    /**
     * The exact body POSTed: the bridge's attribution line, a blank line, the caller's trimmed text.
     *
     * @param  array<string, mixed>  $args
     */
    private function attributedBody(array $args, string $agentName): string
    {
        $content = $args['content'] ?? null;
        if (! is_string($content) || BoardToolArgs::emptyAfterTrim($content)) {
            throw new ToolRefusalException(self::CONTENT_REFUSAL);
        }

        $attribution = "FROM: {$agentName}\n\n";
        $room = KanbanFieldLimits::COMMENT_MAX - mb_strlen($attribution);
        if (mb_strlen($content) > $room) {
            $max = KanbanFieldLimits::COMMENT_MAX;

            throw new ToolRefusalException('board_comment_card: `content` is '.mb_strlen($content)." characters — kanban accepts a comment of at most {$max} (`content => max:{$max}`), and the bridge's attribution line takes ".mb_strlen($attribution)." of those, so at most {$room} are yours. Nothing was written; shorten it or split it across comments.");
        }

        return $attribution.BoardToolArgs::trimmed($content);
    }

    /**
     * Refuses unless the card is on this agent's board and live. ⚠ A card that is not on the board
     * and a board the writeback token cannot read are ONE answer (kanban's search floors a caller to
     * its member boards and answers zero rows), so the not-on-board message carries that disjunct.
     */
    private function requireCardOnBoard(KanbanClient $client, int $boardId, int $cardId, string $agentName): void
    {
        try {
            $live = $client->cardRowsOnBoard($boardId, $cardId);
        } catch (RequestException $e) {
            throw $this->lookupRefusal($e, $cardId, $agentName);
        }

        if (BoardScopedRow::forCard($live, $boardId, $cardId) !== null) {
            return;
        }

        if ($live !== []) {
            Log::warning('board_comment_card: the board-scoped lookup answered a row that is not this card on this board — refusing without a scope verdict', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'rows' => count($live),
            ]);

            throw new ToolRefusalException("board_comment_card: the board lookup for card {$cardId} answered a row that is not that card on your board — that is a BROKEN READ, not a verdict about the card, so nothing was written. Report it to your operator.");
        }

        // Only on a live MISS — the other side of kanban's archive SWITCH (DL-296).
        try {
            $archived = $client->cardRowsOnBoard($boardId, $cardId, archivedOnly: true);
        } catch (RequestException $e) {
            throw $this->lookupRefusal($e, $cardId, $agentName);
        }

        if (BoardScopedRow::forCard($archived, $boardId, $cardId) !== null) {
            throw new ToolRefusalException("board_comment_card: card {$cardId} is ARCHIVED — an archived card is a deliberate retire, and this tool comments only on live cards, so nothing was written. Unarchive it if the work is live again.");
        }

        Log::warning('board_comment_card: refused — no card with this id is on the agent\'s board', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
        ]);

        throw new ToolRefusalException("board_comment_card: card {$cardId} is not on your board {$boardId} — this tool comments only on cards on your own board. Nothing was written. ⚠ A board the bridge's writeback token is not a MEMBER of answers exactly the same way: kanban's search returns zero rows rather than an error, so an unreadable board and an empty one are one answer here — if you believe this card is on your board, have your operator check that token's membership of board {$boardId}. Use `board_my_cards` to see your board's cards.");
    }

    private function lookupRefusal(RequestException $e, int $cardId, string $agentName): \Throwable
    {
        $status = BoardCallRefusal::permanentOnRead($e);
        if ($status === null) {
            return $e;
        }

        Log::warning('board_comment_card: the board-scoped lookup was refused by the board', [
            'agent' => $agentName, 'card_id' => $cardId, 'status' => $status,
        ]);

        return BoardCallRefusal::readRefusal(
            $this->name(),
            BoardReadRoute::Search,
            $status,
            "your board to establish that card {$cardId} is on it",
            'so nothing was written',
        );
    }

    /**
     * A 4xx the BOARD answered on the comment POST. Classify first, log second — a transient
     * status is re-thrown for the retryable 502 and is not a refusal.
     *
     * ⛔ The 403 names `comment.create`, not `task.update`: kanban's `CommentPolicy::createFor` asks
     * the board write gate and then that one permission, and a Member role carries it.
     */
    private function writeRefusal(RequestException $e, int $cardId, string $agentName): \Throwable
    {
        $status = BoardCallRefusal::permanentOnWrite($e);
        if ($status === null) {
            return $e;
        }

        Log::warning('board_comment_card: the board refused the comment write', [
            'agent' => $agentName, 'card_id' => $cardId, 'status' => $status,
        ]);

        return new ToolRefusalException(match ($status) {
            404 => "board_comment_card: card {$cardId} no longer exists — it was removed between the board check and the write, so NOTHING was written. Re-read your cards with `board_my_cards`.",
            403 => "board_comment_card: the board refused the comment on card {$cardId} (403) — the card is on your board, but the bridge's writeback user may not comment on it. ".BoardCallRefusal::writeGatesClause('POST', 'comment.create', ' — the permission a card comment needs, separate from the card writes').' Nothing was written. This is an INSTALL fault, not something your arguments can fix; report it to your operator.',
            401 => "board_comment_card: the board did not accept the bridge's writeback token at all on the comment to card {$cardId} (401) — it has been revoked, rotated or replaced with a value the board does not know. Nothing was written. This is an INSTALL fault; retrying will not change it.",
            422 => "board_comment_card: the board REJECTED the comment on card {$cardId} (422) — kanban's own validator refused it, so nothing was written and re-sending the same call cannot succeed. The bridge bounds the comment at ".KanbanFieldLimits::COMMENT_MAX.' characters, attribution line included, before it sends, so reaching this means the text broke a kanban rule the bridge does not mirror (or one that has moved). Report it to your operator.',
        });
    }
}
