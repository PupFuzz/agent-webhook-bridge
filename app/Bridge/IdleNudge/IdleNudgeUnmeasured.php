<?php

namespace App\Bridge\IdleNudge;

use RuntimeException;

/**
 * A pass that could not MEASURE the fleet — never "no idle seats".
 *
 * ⛔ THE MESSAGE IS BRIDGE VOCABULARY ONLY. It is built from fixed phrases, an HTTP status, a
 * refusal `error` token matched against the documented set, config KEY names and file PATHS —
 * never from a response body, a refusal `message`, or any value the snapshot carried. It is
 * stored on the job row and printed by two commands, and what the far end wrote is not the
 * bridge's to repeat there.
 */
final class IdleNudgeUnmeasured extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        /**
         * True when the pass already wrote its own record before throwing — a fleet read that
         * did not measure on a pass that still judged every seat-record agent. The job then
         * must not overwrite that record with a bare unmeasured one.
         */
        public readonly bool $passRecorded = false,
    ) {
        parent::__construct('idle nudge pass UNMEASURED: '.$reason);
    }
}
