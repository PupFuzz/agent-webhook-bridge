<?php

namespace App\Bridge\Scheduling;

use Illuminate\Contracts\Foundation\Application;

/**
 * The EVENT ingress into the periodic-job registry (card#8425 / DL-325): a scheduler pass
 * queued off the inbound webhook, after the response is already sent.
 *
 * ⭐ THIS IS THE INGRESS THAT NEEDS NO CRONTAB LINE, and it is why adopting the tick is
 * opt-in rather than a dependency. An install that never edits a crontab keeps the exact
 * behaviour it has today: the registry runs on delivery, bounded, after the response —
 * DL-199's shape, applied to a job list instead of to retention alone.
 *
 * ⚠ WHAT IT CANNOT DO, and the reason the tick exists beside it: an install receiving no
 * webhooks evaluates no gates, so it runs no periodic work at all. DL-306 recorded that as
 * a documented cost of the event gate; it is unfixable from this side, because the gate's
 * clock IS the traffic. See {@see JobScheduler} for how the two ingresses divide.
 *
 * It is the third user of the after-response gate shape (`App\Bridge\Retention\RetentionGate`,
 * `App\Bridge\Standup\StandupGate`) and deliberately does NOT re-implement the interval
 * marker, the non-blocking lock or the never-throws shell. Since card#8432 those rules live
 * once for all three, in `App\Bridge\Support\AfterResponseGate`; {@see JobScheduler} binds
 * them to this subsystem's keys, because the tick ingress needs the identical rules and a
 * second copy of them would let one ingress drift from the other.
 */
final class JobSchedulerGate
{
    public function __construct(
        private readonly Application $app,
        private readonly JobScheduler $scheduler,
    ) {}

    /**
     * Queue a scheduler pass to run after this request's response has been sent. Cheap and
     * side-effect-free when the registry is off: no callback is registered.
     */
    public function schedule(): void
    {
        // Asked of the resolved posture directly: {@see JobsConfig} is the one home for
        // the enabled predicate, and a second name for it here bought nothing.
        if (! JobsConfig::fromConfig()->enabled) {
            return;
        }

        $this->app->terminating(fn () => $this->scheduler->passSafely(JobPassSource::EventGate));
    }
}
