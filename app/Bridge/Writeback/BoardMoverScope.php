<?php

namespace App\Bridge\Writeback;

use Illuminate\Support\Facades\Context;

/**
 * Which handler is running, which delivery it runs for, and which kind of write it is making, for
 * the board-mover log rows written meanwhile — the runtime values of their `handler`,
 * `webhook_event_id` and `op` context keys (card#11223, `docs/writeback.md` § *The board-mover
 * catalog*). `webhook_event_id` identifies the DELIVERY, which can cover several writes and several cards, so
 * it correlates rows and does not say whether a given move landed. Every site spells it from here
 * and none leaves it to a caller to add.
 *
 * ⭐ HELD IN LARAVEL'S CONTEXT, NOT THREADED THROUGH SIGNATURES. A shared site (a guard, the kanban
 * client, the alert notifier) logs on behalf of whichever handler called it, often several frames
 * down, so the value is set once where a write is applied — {@see OwedWriteQueue}, the one path
 * every durable handler runs through — and read where the row is logged. It is HIDDEN context, so
 * Laravel does not also append it to every log line's `extra`: only the rows that spell the keys
 * carry them, in the same `context` that holds `catalog_id`.
 *
 * Outside any scope — `bridge:github-owed`, the board-tools door, `bridge:check` — {@see handler()}
 * and {@see webhookEventId()} are null and {@see op()} is {@see WriteOp::Undeclared}. A classifier
 * is outside a handler's scope too, but not outside every scope: its DL correlation reads run under
 * {@see forOp()} for the write they correlate for, so they say that op with a null handler. A
 * component whose write is the same whoever calls it ({@see CardCollapse},
 * {@see PrCorrelationCommenter}, {@see ProtocolInvalidLabeler}) narrows the op with {@see forOp()}
 * so a shared site it calls reports that write and not the caller's.
 */
final class BoardMoverScope
{
    private const HANDLER = 'bridge.board_mover.handler';

    private const OP = 'bridge.board_mover.op';

    private const EVENT = 'bridge.board_mover.webhook_event_id';

    public static function handler(): ?string
    {
        $handler = Context::getHidden(self::HANDLER);

        return is_string($handler) ? $handler : null;
    }

    public static function op(): string
    {
        $op = Context::getHidden(self::OP);

        return is_string($op) ? $op : WriteOp::Undeclared->value;
    }

    /**
     * The id of the webhook delivery the running write was queued for, the `webhook_event_id` an
     * owed write's own rows carry, so rows of one delivery can be correlated. Null outside a write
     * applied for a delivery.
     */
    public static function webhookEventId(): ?int
    {
        $id = Context::getHidden(self::EVENT);

        return is_int($id) ? $id : null;
    }

    /**
     * Run $fn as $handler's dispatch, making $op, for the delivery $webhookEventId (null: none,
     * and an outer scope's delivery is not inherited). Restores the previous values afterwards, on
     * a throw too.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function forHandler(string $handler, WriteOp $op, callable $fn, ?int $webhookEventId = null): mixed
    {
        return Context::scope($fn, hidden: [self::HANDLER => $handler, self::OP => $op->value, self::EVENT => $webhookEventId]);
    }

    /**
     * Run $fn making $op, under whichever handler is already running.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function forOp(WriteOp $op, callable $fn): mixed
    {
        return Context::scope($fn, hidden: [self::OP => $op->value]);
    }
}
