<?php

namespace App\Bridge\Scheduling\Handlers;

use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobOutcome;
use App\Bridge\Standup\StandupConfig;
use App\Bridge\Standup\StandupGate;

/**
 * The PM standup digest, driven from the periodic-job registry (card#8425 / DL-325).
 *
 * ⭐ WHY THIS IS THE HANDLER THE REGISTRY SHIPS WITH. DL-306 recorded the event gate's cost
 * against itself, in its own words: *"the pass fires on the first inbound webhook AFTER the
 * interval lapses, so an install receiving nothing pushes nothing."* The digest is
 * therefore the one shipped subsystem with a documented dead end that only a clock can
 * close, which makes it the honest first instance rather than a demo job: an operator who
 * adopts the tick and inserts this instance gets the digest at zero traffic, and an
 * operator who does not keeps exactly today's delivery-cadence behaviour.
 *
 * ⭐ IT DELEGATES TO {@see StandupGate::runPass()} RATHER THAN RE-IMPLEMENTING THE PASS.
 * Two copies would push two digests: the pass's interval marker is what makes "asked by the
 * event gate" and "asked by the tick" collapse to one push per `standup.interval`, and a
 * handler that called `StandupService` directly would step around it (canon #5).
 *
 * ⚑ TWO INTERVALS, AND THEY MEAN DIFFERENT THINGS. The INSTANCE's `interval_s` is how often
 * the scheduler ASKS; `bridge.standup.interval` is how often the digest is actually PUSHED.
 * Asking every 10 minutes and pushing daily is the intended shape — the short ask is what
 * makes the push land near its due time instead of up to a full interval late.
 *
 * ⚑ {@see JobCapability::ReadAndAlert}: it reads the bridge's own stores and boards and
 * pushes a message. It writes no board state and mutates no install state, so it needs no
 * arming — the read-and-alert class the governance split leaves under normal review.
 */
final class StandupDigestJob implements JobHandler
{
    public function __construct(private readonly StandupGate $gate) {}

    public function name(): string
    {
        return 'standup_digest';
    }

    public function capability(): JobCapability
    {
        return JobCapability::ReadAndAlert;
    }

    public function run(JobContext $ctx): JobOutcome
    {
        $cfg = StandupConfig::fromConfig();

        // Not an error and not a silent no-op: an install that inserted the instance and
        // left the digest off has a job that will never do anything, and the enumeration
        // should say so in the row rather than reporting a healthy pass every 10 minutes.
        if (! $cfg->enabled) {
            return JobOutcome::ok('digest is OFF for this install (BRIDGE_STANDUP_ENABLED=false) — nothing pushed');
        }

        // Throws are the handler's failure channel; the scheduler records them on the row.
        $this->gate->runPass();

        // The pass itself decides whether the interval had elapsed, so this cannot claim a
        // push happened — it claims only that the digest was ASKED, which is the fact this
        // handler can source. DL-306's rule, applied to its own job entry.
        return JobOutcome::ok('digest asked ('.$cfg->summary().')');
    }
}
