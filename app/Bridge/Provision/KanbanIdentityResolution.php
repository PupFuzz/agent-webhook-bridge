<?php

namespace App\Bridge\Provision;

/**
 * The outcome of asking the kanban API who a token is: either a {@see KanbanIdentity} or a
 * NAMED cause, never an exception and never a bare false.
 *
 * ⛔ THE CAUSE IS PART OF THE CONTRACT. Every caller here is fail-soft — the resolve is an
 * offer inside setup, and setup must run offline — so the only thing distinguishing "you
 * have not placed the token yet" from "that token is rejected" from "something answered but
 * it was not this API" is the string this carries. A single "could not resolve" would send
 * an operator to the wrong repair on six of the seven arms, which is the wrong-but-specific
 * cause the project ranks below an honest generic one.
 */
final class KanbanIdentityResolution
{
    private function __construct(
        public readonly ?KanbanIdentity $identity,
        public readonly ?string $failure,
    ) {}

    public static function resolved(KanbanIdentity $identity): self
    {
        return new self($identity, null);
    }

    public static function failed(string $cause): self
    {
        return new self(null, $cause);
    }
}
