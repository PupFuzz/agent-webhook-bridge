<?php

namespace App\Bridge\IdleNudge;

/**
 * One measured pass: every seat bucketed, every declared agent given exactly one verdict.
 */
final class Evaluation
{
    /**
     * @param  array<string, int>|null  $seatTally  seat bucket => count; the buckets partition every
     *                                              seat. Null = no fleet snapshot was read this pass
     * @param  list<AgentVerdict>  $verdicts  one per declared agent, sorted by agent name
     */
    public function __construct(
        public readonly ?array $seatTally,
        public readonly array $verdicts,
    ) {}

    /**
     * This evaluation with more verdicts, re-sorted by agent name.
     *
     * @param  list<AgentVerdict>  $verdicts
     */
    public function with(array $verdicts): self
    {
        $all = [...$this->verdicts, ...$verdicts];
        usort($all, fn (AgentVerdict $a, AgentVerdict $b): int => $a->agent <=> $b->agent);

        return new self($this->seatTally, $all);
    }

    /** @return list<NudgePlan|SeatOfferPlan> */
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
