<?php

namespace App\Bridge\Writeback;

/**
 * The write-kind a board-mover log row is about — the value of its `op` context key
 * (card#11223). `docs/board-mover-catalog.json`'s `ops` block declares the same values with a
 * description each, and `BoardMoverCatalogTest` holds the two sets equal, so a case added here
 * without a catalog entry (or the reverse) reds. `docs/writeback.md` § *The board-mover catalog*
 * owns what each value means to a reader.
 */
enum WriteOp: string
{
    case Move = 'move';
    case Write = 'write';
    case Stamp = 'stamp';
    case Label = 'label';
    case Comment = 'comment';
    case None = 'none';
    case Undeclared = 'undeclared';
}
