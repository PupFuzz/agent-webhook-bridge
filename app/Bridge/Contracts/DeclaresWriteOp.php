<?php

namespace App\Bridge\Contracts;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Writeback\WriteOp;

/**
 * A {@see DurableReaction} handler that says which kind of write a target asks it for — the `op`
 * every board-mover log row written while that target is applied or owed carries (card#11223).
 *
 * Optional, and deliberately not a method on {@see DurableReaction}: that marker is implemented by
 * custom handlers too (docs/customization.md), and a required method there would stop a custom
 * handler from loading at all. A durable handler that does not declare gets
 * {@see WriteOp::Undeclared}.
 *
 * Must be pure: it is read from the target alone, before the handler runs and again when an owed
 * write is given up, so it cannot depend on what the board holds.
 */
interface DeclaresWriteOp
{
    public function writeOp(ReactionTarget $target): WriteOp;
}
