<?php

namespace App\Bridge\Scheduling;

/**
 * What is known about the tick's liveness (card#8425 / DL-325).
 *
 * ⭐ FOUR STATES, AND THE TWO EXTRA ONES ARE THE POINT. The tick becomes the single point
 * of failure for every periodic job on an install that adopted it, so the alarm has to be
 * the tick's own absence — `bridge:prune` sat unscheduled for 45 days across three installs
 * precisely because nothing announced it. But an alarm that cannot distinguish *"I have no
 * measurement"* from *"the measurement is bad"* pages the wrong installs, so the vocabulary
 * is derived from the ruling on staleness rather than from convenience:
 *
 *  - **A THRESHOLD LIVES ON THE RECORD, NOT ON THE WATCHER.** There is no fleet-wide
 *    constant here. The horizon is the operator's own declaration
 *    (`BRIDGE_JOBS_TICK_EXPECTED_EVERY`), because only this install knows what its crontab
 *    line says. A seat that declares a long horizon is not paged for meeting it.
 *  - **AN ABSENT RECORD IS UNMEASURED, NOT STALE.** Never read absence as death.
 *  - **NO DECLARED HORIZON ⇒ NO VERDICT, and never a destructive one.** The age is reported
 *    and no staleness is claimed.
 */
enum TickState: string
{
    /**
     * No tick has ever been recorded here. THE THIRD STATE — this is the ordinary reading
     * on an install that has not adopted the tick, and it is also what a declared-but-never
     * -seen tick reads as. It means *nothing measured*, never *dead*; the caller decides
     * whether the absence is expected, and only a caller holding a declaration may call it
     * a problem.
     */
    case Unmeasured = 'unmeasured';

    /**
     * A tick was recorded and no horizon was declared against it. An age is known; a
     * verdict is not, and inventing a default constant to produce one is exactly the
     * fleet-wide threshold this design refuses.
     */
    case Undeclared = 'undeclared';

    /** A horizon was declared and the last tick is inside it. */
    case Fresh = 'fresh';

    /**
     * A horizon was declared and the last tick BLEW IT. This is evidence on any install
     * with no tuning, because the install set the number itself.
     */
    case Stale = 'stale';
}
