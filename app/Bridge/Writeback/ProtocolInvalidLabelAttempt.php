<?php

namespace App\Bridge\Writeback;

/**
 * What came of ONE attempt to write `protocol:invalid` — the arm {@see ProtocolInvalidLabeler}
 * took, returned rather than only logged, so `bridge:relabel` can report a repair honestly
 * (card#10242 / DL-419).
 *
 * ⛔ IT EXISTS BECAUSE "THE DEBT IS GONE" DOES NOT MEAN "THE LABEL LANDED". A write that lands and
 * a refusal that can never be repaired both leave {@see ProtocolInvalidLabelDebt} with no entry, so
 * a caller inferring the outcome from the record would report a permanent refusal as a success.
 */
final class ProtocolInvalidLabelAttempt
{
    public function __construct(
        /** {@see ProtocolInvalidLabeler::APPLIED}, one of its `REASON_*` values, or `deduped`. */
        public readonly string $arm,
        public readonly ?int $status = null,
    ) {}

    public function applied(): bool
    {
        return $this->arm === ProtocolInvalidLabeler::APPLIED;
    }

    /** Can the identical write still land? False on a refusal nothing an operator does will clear. */
    public function repairable(): bool
    {
        return ! $this->applied() && ProtocolInvalidLabelDebt::retriable($this->arm, $this->status);
    }

    /** What the operator reads beside the thread: the arm, with its status where there is one. */
    public function describe(): string
    {
        return $this->arm.($this->status === null ? '' : ' ('.$this->status.')');
    }
}
