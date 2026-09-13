<?php

namespace App\Bridge\Provision;

/**
 * A kanban user as the API answered for a PRESENTED TOKEN — the id, and the display name
 * that makes the id legible.
 *
 * ⛔ THE NAME IS NOT DECORATION, which is why it is a required field rather than a nullable
 * one: this shape exists to be shown to an operator who must decide whether the account the
 * token authenticates as is the writeback's own (docs/writeback.md § 1). A bare integer
 * cannot be recognised as "that's my personal account" and an offer carrying only one asks
 * for a confirmation the operator has no way to give — so a response with no name resolves
 * to a FAILURE and the by-hand recipe, never to an offer of the id alone.
 *
 * ⚠ NOTHING ELSE FROM THAT RESPONSE IS CARRIED. The body is sensitive as a CLASS, whatever
 * this API version returns — see {@see KanbanIdentityResolver}.
 */
final class KanbanIdentity
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}
}
