<?php

namespace App\Bridge\IdleNudge;

use RuntimeException;

/**
 * A seat offer record that does not measure the seat — never "idle", never "nothing to do"
 * (rt#562 consumer contract rule 5). `verdict` is the {@see AgentVerdict} code; it is the only
 * text that leaves the job, because nothing the record holds is bridge vocabulary.
 */
final class SeatRecordUnmeasured extends RuntimeException
{
    public function __construct(public readonly string $verdict)
    {
        parent::__construct('seat record UNMEASURED: '.$verdict);
    }
}
