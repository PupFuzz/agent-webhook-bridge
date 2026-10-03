<?php

namespace App\Bridge\Writeback;

use Illuminate\Support\Facades\Context;

/**
 * Which handler is running, and which kind of write it is making, for the board-mover log rows
 * written meanwhile — the runtime values of their `handler` and `op` context keys (card#11223,
 * `docs/writeback.md` § *The board-mover catalog*).
 *
 * ⭐ HELD IN LARAVEL'S CONTEXT, NOT THREADED THROUGH SIGNATURES. A shared site (a guard, the kanban
 * client, the alert notifier) logs on behalf of whichever handler called it, often several frames
 * down, so the value is set once where a write is applied — {@see OwedWriteQueue}, the one path
 * every durable handler runs through — and read where the row is logged. It is HIDDEN context, so
 * Laravel does not also append it to every log line's `extra`: only the rows that spell the keys
 * carry them, in the same `context` that holds `catalog_id`.
 *
 * Outside any scope — a classifier, `bridge:github-owed`, the board-tools door, `bridge:check` —
 * {@see handler()} is null and {@see op()} is {@see WriteOp::Undeclared}. A component whose write
 * is the same whoever calls it ({@see CardCollapse}, {@see PrCorrelationCommenter},
 * {@see ProtocolInvalidLabeler}) narrows the op with {@see forOp()} so a shared site it calls
 * reports that write and not the caller's.
 */
final class BoardMoverScope
{
    private const HANDLER = 'bridge.board_mover.handler';

    private const OP = 'bridge.board_mover.op';

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
     * Run $fn as $handler's dispatch, making $op. Restores the previous values afterwards, on a
     * throw too.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function forHandler(string $handler, WriteOp $op, callable $fn): mixed
    {
        return Context::scope($fn, hidden: [self::HANDLER => $handler, self::OP => $op->value]);
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
