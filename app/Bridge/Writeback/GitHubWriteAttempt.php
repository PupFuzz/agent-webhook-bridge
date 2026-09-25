<?php

namespace App\Bridge\Writeback;

/**
 * What came of ONE attempt at an owed-able GitHub write — the arm the writer took, returned rather
 * than only logged, so `bridge:github-owed` can report a repair honestly (card#10242 / DL-419,
 * shared with the correlation comment at card#10365 / DL-422).
 *
 * ⛔ IT EXISTS BECAUSE "THE DEBT IS GONE" DOES NOT MEAN "THE WRITE LANDED". A write that lands and
 * a refusal that can never be repaired both leave {@see GitHubWriteDebt} with no entry, so a caller
 * inferring the outcome from the record would report a permanent refusal as a success. The writer
 * that took the arm is the one that knows both answers, so it states them here.
 */
final class GitHubWriteAttempt
{
    public function __construct(
        /** The writer's arm: a success value (`applied`, `posted`, `already_posted`) or one of its `REASON_*` values. */
        public readonly string $arm,
        /** GitHub landed the write — confirmed from an answer that carries it, never from a status alone. */
        public readonly bool $landed,
        /** The write is still owed after this attempt: not landed, and the writer's predicate says it can. */
        public readonly bool $owed,
        public readonly ?int $status = null,
    ) {}

    /** What the operator reads beside the write: the arm, with its status where there is one. */
    public function describe(): string
    {
        return $this->arm.($this->status === null ? '' : ' ('.$this->status.')');
    }
}
