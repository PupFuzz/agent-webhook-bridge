<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Scheduling\JobPassResult;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\TickRecord;

/**
 * THE TICK — the second, opt-in ingress into the periodic-job registry
 * (card#8425 / DL-325). ONE crontab line drives every periodic job this install has.
 *
 *     0,10,20,30,40,50 * * * * cd /path/to/bridge && php artisan bridge:tick
 *
 * ⛔ ADOPTING IT IS OPT-IN, AND NOTHING BREAKS WITHOUT IT. The registry also runs from
 * the inbound webhook's after-response gate (`App\Bridge\Scheduling\JobSchedulerGate`,
 * DL-199's shape), so an install that never adds this line behaves exactly as it does
 * today. What the line buys is the one thing an arrival-gated pass cannot give: periodic
 * work on an install receiving NO webhooks — DL-306's documented dead end, in its own
 * words, *"an install receiving nothing pushes nothing"*.
 *
 * ⛔ THE CRONTAB LINE NEVER CHANGES. That is the directive's third floor term and the
 * reason this command takes no job argument: what runs is DATA in the registry, inserted
 * and removed at runtime by any code path (`bridge:jobs`, or
 * `App\Bridge\Scheduling\JobRegistry` from PHP). A second crontab line is the thing this
 * design exists to make unnecessary.
 *
 * ⚠ RUN IT UNDER THE SEAT-OWNER ACCOUNT, NEVER ROOT. It executes registered handlers with
 * the bridge's own credentials; root buys nothing and widens everything.
 *
 * ⭐ IT STAMPS THE TICK BEFORE IT WORKS, and the order is deliberate. The stamp is the
 * death-is-the-alarm record (`App\Bridge\Scheduling\TickRecord`) that `bridge:jobs
 * --assert-tick` and `bridge:check` read: it answers *"is the clock alive?"*, so it must be
 * written by the fact that the clock fired, not by the pass having gone well. A tick that
 * fires into a registry full of failing jobs is a live clock with sick jobs — two different
 * alarms, and collapsing them hides whichever fires second.
 *
 * ⚑ EXIT CODE. A completed pass exits 0 even when jobs inside it FAILED or were REFUSED:
 * those are recorded on their own rows and reported by `bridge:jobs` / `bridge:check`, and
 * a crontab line that mails the operator on a handler bug trains them to filter the mail
 * that would have carried a dead-clock alarm. Only a fault that stopped the pass from
 * running at all — an unreachable database, an unusable cache backend, a cadence this
 * install cannot act on — is a non-zero exit. ⛔ EACH OF THOSE THREE RIDES THE RESULT, and
 * the cache one only started to when `App\Bridge\Support\FaultMarker` landed: the pass's
 * catch arm recorded its fault BY WRITING TO THE CACHE, so a dead store re-raised out of
 * the shell and this command died at the stamp with a stack trace — exit 1 by accident,
 * with no summary line and nothing in the log.
 *
 * ⛔ AN ORDINARY SKIP IS EXIT 0, and the distinction is read off
 * {@see JobPassResult::passFailed()} rather than off the reason
 * string. A pass that lost the non-blocking lock, one inside the shared minimum interval,
 * and one on an install with `BRIDGE_JOBS_ENABLED=false` are the scheduler working as
 * designed; a crontab line reddening on those would red on most of its runs on a busy
 * install and the alarm would be filtered within a week.
 *
 * ⚑ THERE IS NO `guardDatabase()` HERE, deliberately, and what makes that safe is that both
 * of this command's calls INTO the registry are total. `passSafely()` catches every `Throwable`
 * (recording included), and `TickRecord::stamp()` treats a stamp it cannot write as
 * unmeasured rather than throwing — so a `QueryException` cannot reach this frame and a
 * guard around the call would be a branch for a state its callee excludes. ⚠ That was an
 * ASSERTION about the callees before it was a property of them: the stamp threw on a dead
 * cache store, one line above the call this paragraph reasons about.
 */
class TickCommand extends BridgeCommand
{
    protected $signature = 'bridge:tick';

    protected $description = 'Run one bounded pass over the periodic-job registry (the single external tick)';

    public function __construct(private readonly JobScheduler $scheduler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Stamped first and unconditionally: see the class docblock. A pass that skips
        // (another one holds the lock) is still a tick that arrived. It cannot throw — a
        // stamp that cannot be written reads as unmeasured, which is TickRecord's own rule.
        TickRecord::stamp();

        $result = $this->scheduler->passSafely(JobPassSource::Tick);
        $this->line($result->summary());

        return $result->passFailed() ? self::FAILURE : self::SUCCESS;
    }
}
