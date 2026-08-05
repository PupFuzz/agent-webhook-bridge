<?php

namespace App\Bridge\Check;

/**
 * What became of one registered check in one run (DL-242 stage 8).
 *
 * The registry's stage-8 invariant is that every registered check is ACCOUNTED FOR on
 * every run THAT COMPLETES — not that every check emits a finding. (The qualifier is the
 * one {@see CheckRunner} imposes by deliberately not catching: a check that throws aborts
 * `bridge:check`, and the operator gets no account at all rather than a partial one.)
 * Measurement at the start of the stage showed the emission form is false on every
 * install shape the corpus covers: on the baseline install 13 of 37 checks are never
 * invoked at all (whole slots sit behind conditional envelopes in
 * `CheckCommand::handle()`), 13 more run and report nothing, and 2 opt-in probes are
 * never asked to look — 9 of 37 emit anything at all. (Stated in this enum's OWN terms
 * because an earlier revision said "15 more run and report nothing", a PRE-taxonomy
 * count that folded {@see self::NotRequested} into {@see self::Silent}: the two are
 * separated one file down, and 15 reconciles with nothing the operator's line prints.)
 * Turning THAT into
 * "everything emits" would have meant dissolving envelopes stages 3a and 7b preserved as
 * behavior. See the stage 8 result in `docs/CHECK-REGISTRY-PLAN.md`.
 *
 * THE FOUR CASES ARE NOT A SEVERITY, AND DELIBERATELY SO. `Severity` answers "how bad
 * is what this check found"; a disposition answers "did this check get to look at all".
 * Collapsing them would put `not requested` in the severity vocabulary, which the
 * resolved opt-in-probe decision refused: `unvalidated` keeps exactly one meaning —
 * *"I should have measured this and the install stopped me"* — and no new `Severity`
 * case means the exhaustive-`match` property and the exit contract are untouched by
 * construction.
 */
enum CheckDisposition: string
{
    /** Ran, and yielded at least one finding — the findings are the report. */
    case Reported = 'reported';

    /**
     * Ran and yielded nothing. A POSITIVE statement about the install: the check
     * looked and had nothing to say (no identity collisions, no unconsumed event
     * types). It is NOT the same as {@see self::NotRequested} or {@see self::NotRun},
     * and the whole point of this enum is that the three are no longer indistinguishable
     * from one another in a green run.
     *
     * IT IS NO LONGER INDISTINGUISHABLE FROM A BUG (card#5596), and the strengthening is
     * deliberately NOT a fifth case. A check now DECLARES a deliberate silence by yielding
     * a {@see Silence}, and an execution that declares nothing is recorded ALONGSIDE this
     * disposition ({@see CheckInventory::undeclaredSilent()}) rather than beside it in this
     * enum — so `--format=json`'s four `disposition` values, the exhaustive-`match`
     * property and the exit contract are untouched by construction, exactly as
     * {@see self::NotRun}'s reasons are carried alongside rather than encoded here.
     *
     * ⚠ A DECLARED SILENCE IS STILL NOT A CORRECT ONE. The declaration records the author's
     * intent, and the author who returns early by mistake will declare that path by
     * mistake too; what it buys is that a path nobody judged cannot stay quiet. {@see Silence}
     * owns that bound in full.
     */
    case Silent = 'silent';

    /**
     * An opt-in check whose flag the operator did not pass ({@see OptInCheck}).
     *
     * Distinct from {@see self::Silent} because it carries NO statement about the
     * install: the check was never asked to look. Per the resolved opt-in-probe
     * decision, the not-running here is a fact about the INVOCATION, which the operator
     * necessarily knows because they chose not to type the flag — so there is no false
     * belief to correct and `unvalidated` would dilute the one signal with a precise job.
     */
    case NotRequested = 'not-requested';

    /**
     * Registered, but its slot was never invoked this run — the conditional envelope
     * around it in `CheckCommand::handle()` did not open (no `writeback.json`, no
     * enabled `board_tools` block, no agent configs, or a fail-soft catch fired).
     *
     * THIS IS THE CASE THE STAGE EXISTS FOR. `CheckRunner`'s own docblock named it
     * before it was measured — *"A SLOT THAT IS NEVER RUN IS THE SAME HOLE ONE LEVEL
     * DOWN"* — and it is 13 of 37 checks on the baseline install. It is DERIVED from
     * the registration list rather than reported by the caller, so a slot whose
     * invocation is forgotten cannot go unaccounted; {@see CheckRunner::noteNotRun()}
     * only attaches the human-readable REASON, and a missing reason degrades the
     * message without opening a hole.
     */
    case NotRun = 'not-run';
}
