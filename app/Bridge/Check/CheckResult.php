<?php

namespace App\Bridge\Check;

use App\Bridge\Support\Finding;

/**
 * What one registered check reported in one run (DL-242).
 *
 * The registry's value over the raw `warn()` sites it replaces is that a check's
 * findings arrive ATTRIBUTED — the id is carried alongside them, so the run can be
 * inventoried rather than only printed. Stage 9's JSON renderer reads these; stage 8
 * asserts every registered check produced one.
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
