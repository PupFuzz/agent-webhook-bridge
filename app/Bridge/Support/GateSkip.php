<?php

namespace App\Bridge\Support;

/**
 * Why an {@see AfterResponseGate::oncePerInterval()} call did NO work — the two ordinary,
 * non-fault outcomes, which a caller must be able to tell apart from each other and from a
 * pass that ran (card#8432).
 *
 * ⚑ IT IS A RETURN VALUE AND NOT A THROW, because neither case is an error: a busy install
 * produces one of these on the overwhelming majority of deliveries. `App\Bridge\Scheduling\JobScheduler`
 * is the caller that acts on the distinction — `bridge:tick`'s exit code is 0 on both, but
 * its summary line says which — while the two gates that ignore the value are just as
 * correct, since for them "no work happened" is the whole fact.
 */
enum GateSkip
{
    /** Another pass held the lock. The loser skips INSTANTLY; it never queues (DL-199). */
    case Locked;

    /** The interval marker still stood, so this pass is inside the previous one's cadence. */
    case TooSoon;
}
