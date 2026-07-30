<?php

namespace App\Bridge\Check;

/**
 * The exact, per-check account of one `bridge:check` run (DL-242 stage 8).
 *
 * This is what replaces the *"floor, not an inventory"* disclaimer. That disclaimer
 * existed because the only thing the command could count was findings that happened to
 * carry one severity, and it had to say so — a tally that cannot see a check which was
 * never invoked is not an inventory of anything.
 *
 * IT IS DATA, NOT PROSE. The wording of the operator-facing summary belongs to the
 * renderer, which stage 9 splits into a text/json pair; putting a sentence here would
 * put the text renderer's voice inside the thing the json renderer also reads.
 *
 * WHAT IT DOES NOT ESTABLISH, stated because an unstated bound reads as a guarantee:
 *  - It cannot see a check NOBODY WROTE. It accounts for the registered set, and a leg
 *    that never became a `Check` is outside it — the same bound `check-golden-coverage.md`
 *    carries, one level up. That file is generated from `CheckCommand::handle()` alone, so
 *    a migrated check is absent from it BY CONSTRUCTION and **absence there is not
 *    protection** — it does not speak for a predicate in either direction (DL-243). The
 *    two bounds compose rather than cover for each other: this class cannot see an
 *    unwritten check, and that file cannot see a written one.
 *  - It does NOT make the severity vocabulary precise. A check reporting `warn` because
 *    it could not run still reads as a finding here, not as a not-run. That
 *    re-assignment is card#5291's and is a separate, gated decision.
 *  - {@see CheckDisposition::NotRun} reasons are the ENVELOPE's claim about itself. If
 *    an envelope's condition is wrong, the reason is confidently wrong with it.
 */
final class CheckInventory
{
    /**
     * @param  array<string, CheckDisposition>  $dispositions  every registered check id
     * @param  array<string, string>  $notRunReasons  id ⇒ why its slot never opened
     */
    public function __construct(
        public readonly array $dispositions,
        public readonly array $notRunReasons = [],
    ) {}

    public function registered(): int
    {
        return count($this->dispositions);
    }

    /** How many registered checks carry the given disposition. */
    public function count(CheckDisposition $disposition): int
    {
        return count(array_filter($this->dispositions, fn (CheckDisposition $d) => $d === $disposition));
    }

    /** Checks that actually executed — {@see CheckDisposition::Reported} plus {@see CheckDisposition::Silent}. */
    public function ran(): int
    {
        return $this->count(CheckDisposition::Reported) + $this->count(CheckDisposition::Silent);
    }

    /**
     * The distinct not-run reasons, in first-recorded order, deduplicated.
     *
     * Deduplicated because one envelope closing accounts for several checks and the
     * operator needs the CAUSE once, not once per check: nine writeback checks share
     * "this install has no writeback.json".
     *
     * @return list<string>
     */
    public function notRunReasons(): array
    {
        $seen = [];
        foreach ($this->dispositions as $id => $disposition) {
            if ($disposition !== CheckDisposition::NotRun) {
                continue;
            }
            $reason = $this->notRunReasons[$id] ?? null;
            if ($reason !== null && ! in_array($reason, $seen, true)) {
                $seen[] = $reason;
            }
        }

        return $seen;
    }

    /**
     * Not-run checks whose reason was never recorded.
     *
     * A DEGRADED MESSAGE, NOT A HOLE — and the distinction is the whole reason
     * accounting is derived from the registration list rather than from the caller
     * remembering to declare a skip. A forgotten {@see CheckRunner::noteNotRun()} lands
     * a check here; a mechanism that instead required the caller to declare every skip
     * would have lost the check entirely, which is the defect this stage closes.
     *
     * @return list<string>
     */
    public function unexplainedNotRun(): array
    {
        $out = [];
        foreach ($this->dispositions as $id => $disposition) {
            if ($disposition === CheckDisposition::NotRun && ! isset($this->notRunReasons[$id])) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
