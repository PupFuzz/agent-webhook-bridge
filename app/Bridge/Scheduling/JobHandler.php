<?php

namespace App\Bridge\Scheduling;

/**
 * One thing the periodic-job registry knows how to DO (card#8425 / DL-325).
 *
 * ⛔ READ `docs/periodic-jobs.md` BEFORE ADDING ONE. A periodic job is the LAST resort in
 * this design: the bridge's first answer to "this needs to happen regularly" is the
 * after-response event gate, because the thing that creates the work is usually the thing
 * that can evaluate the gate (DL-199's symmetry argument). A new handler is a claim that no
 * such symmetry exists.
 *
 * ⭐ THE HANDLER IS THE GOVERNED SURFACE. Adding one is a code change and gets code review;
 * inserting an INSTANCE of an existing one is ungated and happens at runtime. That is what
 * makes "instances are free" safe: what a job can do never changes without a diff.
 * {@see JobCapability::MutatesState} handlers additionally require the install's operator
 * to arm them by name before they can run at all.
 *
 * ⚑ FAILURE IS A THROW, not a return value — see {@see JobOutcome}. Anything thrown is
 * caught by {@see JobScheduler}, recorded on the instance row and surfaced by `bridge:jobs`
 * and `bridge:check`; it never reaches the webhook response or the tick's exit code.
 *
 * ⚠ A HANDLER MUST BE BOUNDED. It runs inside an FPM worker's after-response callback on
 * the event ingress, so an unbounded pass holds a worker. Bound the work per pass and let
 * the next pass continue — the same rule DL-199's retention batch follows.
 */
interface JobHandler
{
    /**
     * The registry key instances reference. Stable once shipped: a renamed handler reads as
     * every instance of it suddenly naming nothing, which is a loud refusal on a job that
     * was working.
     */
    public function name(): string;

    /** What this handler is permitted to do. See {@see JobCapability} for what that buys. */
    public function capability(): JobCapability;

    /**
     * Do one bounded pass. Return {@see JobOutcome::ok} with a one-line account (including
     * when there was nothing to do); throw to report failure.
     */
    public function run(JobContext $ctx): JobOutcome;
}
