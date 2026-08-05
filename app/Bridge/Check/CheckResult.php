<?php

namespace App\Bridge\Check;

use App\Bridge\Support\Finding;

/**
 * What one registered check reported in one run (DL-242).
 *
 * The registry's value over the raw `warn()` sites it replaces is that a check's
 * findings arrive ATTRIBUTED — the id is carried alongside them, so the run can be
 * inventoried rather than only printed. {@see CheckJsonRenderer} reads these, and the
 * `agent` below is the one attribution the text renderer has no place to put: it prints
 * a check's display-ready message and nothing frames it per check (DL-249 stage 9).
 *
 * A RESULT IS NOT THE INVENTORY, and the difference is what stage 8 turned on. A result
 * exists only for a check that RAN, so the set of results cannot speak for a check whose
 * slot was never invoked — 13 of 37 on the baseline install. {@see CheckRunner::inventory()}
 * is the account that covers those, and it is derived from the registration list rather
 * than from these.
 */
final class CheckResult
{
    /** @param list<Finding> $findings */
    public function __construct(
        public readonly string $id,
        public readonly array $findings,
        /** The agent a {@see PerAgentCheck} ran for; null for a global {@see Check}. */
        public readonly ?string $agent = null,
    ) {}
}
