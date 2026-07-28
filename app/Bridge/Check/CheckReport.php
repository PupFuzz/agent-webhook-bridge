<?php

namespace App\Bridge\Check;

use App\Bridge\Support\Finding;

/**
 * What one {@see CheckRunner} pass produced, IN ORDER (DL-242).
 *
 * Order is load-bearing, not incidental: stages 0-7 hold a byte-identical output
 * contract, and each stage moves one check unit out of `handle()` REGISTERED AT THE
 * SAME ORDINAL POSITION. A report that reordered its results would break that
 * contract the moment a renderer walked it.
 */
final class CheckReport
{
    /** @param list<CheckResult> $results */
    public function __construct(public readonly array $results = []) {}

    /**
     * Every finding, in registration order and then in the order its check yielded
     * them — the sequence a renderer prints.
     *
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];
        foreach ($this->results as $result) {
            foreach ($result->findings as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
