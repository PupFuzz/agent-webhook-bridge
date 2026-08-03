<?php

namespace App\Bridge\Check;

use InvalidArgumentException;

/**
 * A check DECLARING that this execution deliberately had nothing to report (card#5596).
 *
 * WHAT IT CLOSES. Stage 8 made a check's silence VISIBLE and COUNTED
 * ({@see CheckDisposition::Silent}) but left two different things recording it: a
 * DELIBERATE silence — the check looked and correctly had nothing to say — and a BUG, a
 * check falling off the end of its generator through a path its author did not intend.
 * An empty yield cannot tell them apart, so the runner stopped inferring and the check
 * says which. Yielding one of these is the saying.
 *
 * WHY IT IS YIELDED AT THE PATH AND NOT ANSWERED BY A METHOD. The card's recommended
 * shape was a second contract method, by analogy with {@see OptInCheck::wasRequested()}.
 * The analogy breaks exactly where it matters: `wasRequested()` answers about the
 * INVOCATION and is a constructor-time fact, correct however many times `run()` is
 * called, while "was this silence deliberate" is a fact about the EXIT PATH of ONE
 * execution. A method consulted after the fact would need the check to record which path
 * it took — per-execution mutable state on an object {@see PerAgentCheck} re-invokes once
 * per agent — making correctness depend on every check resetting that state per call.
 * That is the shape stage 8 rejected for the not-run accounting, in its own words: it
 * *"would have made the mechanism's correctness depend on remembering to call it, which
 * is the same shape as the bug it closes"*. A yielded declaration is local to the path it
 * declares and cannot go stale.
 *
 * HOW IT READS, AND WHY IT IS YIELDED UNCONDITIONALLY. A declaration does not assert
 * *"I am silent"* — the check cannot know that while it is still running. It states
 * *"should this execution end with nothing reported, here is what that silence means"*.
 * So it goes at the END of a path, and the runner IGNORES it when findings were also
 * yielded. That is what makes the common shape a one-liner instead of a bookkeeping flag:
 *
 *     foreach ($this->problems() as $problem) {
 *         yield Finding::warn($problem);
 *     }
 *     yield Silence::because('every configured token was readable and 0600');
 *
 * TWO PLACEMENT RULES, AND THEY ARE THE WHOLE CONTRACT:
 *  1. Every explicit early `return;` gets its OWN declaration immediately before it,
 *     stating what that particular path means — the reasons differ per path and a single
 *     trailing one would launder them into one sentence.
 *  2. The fall-through off the end of the method gets a trailing declaration.
 * A check with no zero-finding path at all (every branch yields) needs neither, and adding
 * one would claim a state it cannot reach.
 *
 * THE TRIPWIRE SURVIVES THE TRAILING FORM, which is the property that makes it safe: an
 * early `return;` added later sits BEFORE the trailing declaration, so it skips it and the
 * execution is recorded undeclared. A new silent path cannot inherit an old path's
 * judgement.
 *
 * A `return;`-KEYED STATIC ANALYSER WAS THE OTHER OPTION AND IS STRUCTURALLY BLIND. The
 * most common silent path in this registry has no `return` at all — a `foreach` over a
 * collection that is empty (`AgentIdentityCollisionsCheck` is the plain case) — so an
 * analyser keyed on `return` sites cannot see the majority of the population. That is the
 * same blindness DL-251's sweep had when it keyed on the sites that REPORT.
 *
 * THE REASON IS NOT RENDERED, AND IS STILL THE POINT. Nothing prints it: this change is
 * output-neutral by requirement. It is read by the maintainer at the call site, and it is
 * a required CONSTRUCTOR ARGUMENT rather than a comment because that is what makes a blind
 * sweep visible in review — `Silence::because('...')` cannot be inserted across 37 files
 * without someone writing 37 sentences, which is the per-site judgement the card exists to
 * force.
 *
 * A SUSPECT PATH IS NOT DECLARED DELIBERATE. If the honest answer at a path is *"no, this
 * should have yielded something"*, the fix is a `Finding` (an output change, with its
 * own gate), not a declaration. Where the answer is *"silent today, and the question is
 * open"*, the reason SAYS SO and names the card — a declaration states what the silence
 * means today, never that it is right.
 *
 * WHAT A DECLARED SILENCE DOES NOT ESTABLISH, stated because an unstated bound reads as a
 * guarantee: it says the AUTHOR INTENDED this silence, not that the silence is CORRECT.
 * The author who wrongly returns early will equally wrongly declare that path. What the
 * mechanism buys is a forced review moment over the existing population, once, and an
 * un-bypassable tripwire on every zero-finding path added after it — not a detector for a
 * wrong judgement.
 */
final class Silence
{
    private function __construct(public readonly string $reason) {}

    /**
     * Declare a deliberate silence, stating what the check looked at and found nothing of.
     *
     * The empty guard mirrors {@see CheckRunner::register()}'s empty-id guard: a blank
     * reason is a declaration that declares nothing, and would let the one thing this
     * primitive is for be satisfied without doing it.
     */
    public static function because(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('a declared silence must state its reason');
        }

        return new self($reason);
    }
}
