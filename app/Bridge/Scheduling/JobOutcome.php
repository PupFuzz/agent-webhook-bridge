<?php

namespace App\Bridge\Scheduling;

use InvalidArgumentException;

/**
 * What one {@see JobHandler} pass has to say for itself (card#8425 / DL-325).
 *
 * ⭐ THERE IS NO `failed()` FACTORY, DELIBERATELY. A handler reports failure by THROWING —
 * {@see JobScheduler} catches per job and records the exception on the row. A returned
 * failure would be a second way to say the same thing, and the two would drift: a handler
 * that throws from a helper it forgot to wrap would be recorded one way and a handler that
 * returns one, the other. One channel, one recording.
 *
 * ⚑ "NOTHING TO DO" IS AN OK PASS, and its summary should say so. A handler that ran, found
 * no work and returned {@see self::ok} with `'nothing due'` is a healthy job — the registry
 * records that it ran, which is the property DL-012's silently-unscheduled command lacked.
 */
final class JobOutcome
{
    private function __construct(
        /** One line, operator vocabulary, printed verbatim by `bridge:jobs`. */
        public readonly string $summary,
    ) {}

    /**
     * The blank guard mirrors `App\Bridge\Check\Silence::because()`: a summary that says
     * nothing lets the one thing the field is for be satisfied without doing it, and the
     * enumeration then shows a green job whose last pass is unaccounted for.
     */
    public static function ok(string $summary): self
    {
        if (trim($summary) === '') {
            throw new InvalidArgumentException('a job outcome must state what the pass did');
        }

        return new self(trim($summary));
    }
}
