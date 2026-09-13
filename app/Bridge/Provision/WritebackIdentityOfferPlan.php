<?php

namespace App\Bridge\Provision;

/**
 * What {@see WritebackIdentityOffer::prepare()} decided, as data rather than as printed
 * output: the identity to OFFER (null ⇒ nothing to offer), the lines that state it, and the
 * warnings that must be seen BEFORE the operator answers.
 *
 * ⚑ The split exists so the decision is testable and statically analysed while only the
 * rendering lives in the console command. `notes` and `warnings` are separate because the
 * command renders them at different severities — a collision between two tokens is not an
 * informational line, and folding them into one list would lose that at the call site.
 */
final class WritebackIdentityOfferPlan
{
    /**
     * @param  list<string>  $notes
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly ?KanbanIdentity $offered,
        public readonly array $notes = [],
        public readonly array $warnings = [],
    ) {}

    /** Writeback is off, or the id is already declared: setup says nothing at all. */
    public static function nothingToOffer(): self
    {
        return new self(null);
    }
}
