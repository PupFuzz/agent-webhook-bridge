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
 * renderer, and since DL-249 stage 9 there are two of them — `CheckCommand`'s inventory
 * line and {@see CheckJsonRenderer} — so a sentence here would put the text renderer's
 * voice inside the thing the json renderer also reads.
 *
 * IT ACCOUNTS FOR EVERY REGISTERED CHECK ON EVERY RUN THAT COMPLETES, and the qualifier is
 * load-bearing rather than pedantic: {@see CheckRunner} deliberately does not catch, so a
 * check that throws aborts `bridge:check` before any of this is rendered and the operator
 * gets no accounting at all — not a partial one.
 *
 * WHAT IT DOES NOT ESTABLISH, stated because an unstated bound reads as a guarantee:
 *  - It cannot see a check NOBODY WROTE. It accounts for the registered set, and a leg
 *    that never became a `Check` is outside it — the same bound `check-golden-coverage.md`
 *    carries, one level up. That file is generated from `CheckCommand::handle()` alone, so
 *    a migrated check is absent from it BY CONSTRUCTION and **absence there is not
 *    protection** — it does not speak for a predicate in either direction (DL-243). The
 *    two bounds compose rather than cover for each other: this class cannot see an
 *    unwritten check, and that file cannot see a written one.
 *  - It does NOT make the severity vocabulary precise, and DL-251 narrowed that bound
 *    rather than removing it. The sweep it carried moved every leg that REPORTS being
 *    unable to measure onto `unvalidated`, so **a `warn` here is a finding, not a
 *    not-run**. It is NOT therefore a measured one: a leg can warn off a swallowed throw
 *    having measured nothing, which is a different defect class (card#5698) on a
 *    different axis from this one. What the rule does and does not buy is owned by
 *    `App\Bridge\Support\Severity`'s docblock (NAMED, not `{@see}`-linked — a
 *    fully-qualified `{@see}` becomes a real import under pint) and is deliberately not
 *    restated here.
 *  - {@see CheckDisposition::NotRun} reasons are the ENVELOPE's claim about itself. If
 *    an envelope's condition is wrong, the reason is confidently wrong with it.
 *  - EVERY COUNT HERE IS KEYED BY CHECK ID, so a per-agent check is one row however many
 *    agents it ran for. One that ran for two of three agents — the third aborted at the
 *    classifier gate — counts once, as having run, and nothing in this class scopes that
 *    to the agents it actually reached. The bound is stated on
 *    {@see PerAgentCheck::runFor()} as the accepted granularity cost; it is repeated here
 *    because this is the class whose numbers a reader would otherwise take per-agent.
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
     * The distinct not-run reasons, deduplicated, in the REGISTRATION order of the first
     * not-run check carrying each — this walks `$dispositions`, which
     * {@see CheckRunner::inventory()} builds in registration order. NOT the order the
     * reasons were recorded in: the two coincide today only because `CheckCommand` happens
     * to close its envelopes in roughly registration order, and nothing holds it to that.
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
