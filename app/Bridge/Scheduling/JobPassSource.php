<?php

namespace App\Bridge\Scheduling;

/**
 * WHICH INGRESS drove this scheduler pass (card#8425 / DL-325).
 *
 * ⭐ THE TWO INGRESSES ARE ADDITIVE AND COVER EACH OTHER'S BLIND SPOT, which is the whole
 * reason this enum exists rather than a bool:
 *  - {@see self::EventGate} — the after-response webhook gate (DL-199's shape). It covers
 *    the BUSY install and needs no crontab line, so an install that has not adopted the
 *    tick keeps behaving exactly as it does today.
 *  - {@see self::Tick} — `bridge:tick`, one crontab line. It covers the SILENT install,
 *    which is DL-306's documented dead end in its own words: *"the pass fires on the first
 *    inbound webhook AFTER the interval lapses, so an install receiving nothing pushes
 *    nothing."* No event gate can fix that; only a clock can.
 *
 * A handler is handed this so it can say where it ran from, and the scheduler logs it. It
 * is NOT a permission axis: every job is runnable from either ingress, because a job whose
 * correctness depended on which clock woke it would be a job that behaves differently on
 * two installs of the same release.
 */
enum JobPassSource: string
{
    case EventGate = 'event_gate';
    case Tick = 'tick';

    /** A hand-run `bridge:jobs run` — an operator asking for a pass now. */
    case Manual = 'manual';
}
