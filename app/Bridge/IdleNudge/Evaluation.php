<?php

namespace App\Bridge\IdleNudge;

/**
 * One measured pass: every seat bucketed, every declared agent given exactly one verdict.
 */
final class Evaluation
{
    /**
     * @param  array<string, int>  $seatTally  seat bucket => count; the buckets partition every seat
     * @param  list<AgentVerdict>  $verdicts  one per declared agent, sorted by agent name
     */
    public function __construct(
        public readonly array $seatTally,
        public readonly array $verdicts,
    ) {}

    /** @return list<NudgePlan> */
    public function plans(): array
    {
        $plans = [];
        foreach ($this->verdicts as $v) {
            if ($v->plan !== null) {
                $plans[] = $v->plan;
            }
        }

        return $plans;
    }

    /** @return array<string, int> verdict code => count */
    public function verdictTally(): array
    {
        $tally = [];
        foreach ($this->verdicts as $v) {
            $tally[$v->code] = ($tally[$v->code] ?? 0) + 1;
        }
        ksort($tally);

        return $tally;
    }
}
