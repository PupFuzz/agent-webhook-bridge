<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\Finding;
use App\Bridge\Support\SignalAllowlist;
use Throwable;

/**
 * Validate every agent's `echo_suppression.treat_as_signal` against the agent roster,
 * migrated out of `CheckCommand::handle()` (DL-242 stage 5c).
 *
 * A name with no matching agent config is fail-closed at dispatch — the classify path
 * throws and the receiver 5xxs — so an operator who fixes it at runtime discovers it as
 * an outage. Preflight is where it can still be a typo. `SignalAllowlist::default()` is
 * the code that decides; this check calls it exactly as the dispatch path will and
 * reports what it throws.
 *
 * A GLOBAL CHECK THAT ITERATES AGENTS, NOT A `PerAgentCheck`. The inline code ran this
 * AFTER the per-agent loop finished, in one post-loop block, because the roster it
 * validates against does not exist until every YAML has been read. Registering it
 * per-agent would move its lines up into the loop and break the byte-identical output
 * contract (plan constraint (b)).
 *
 * IT IS SEPARATE FROM {@see AgentIdentityCollisionsCheck} even though the two share a
 * registry and a guard: a collision is a warn the operator may knowingly accept, an
 * unresolvable signal name is a `fail` that flips the exit code, and the fixes differ
 * (rename an identity vs correct a name here). One check would hand stage 8's inventory
 * a row that means two things.
 *
 * THE `catch` IS THIS CHECK'S OWN, in the narrow form the plan allows: it wraps ONE call
 * and no derivation, so one bad agent surfaces as one error line instead of aborting the
 * run and skipping every remaining agent — `CheckRunner` deliberately does not catch.
 *
 * NO GOLDEN FIXTURE REACHES THE THROW — every fixture's `treat_as_signal` resolves, so
 * the golden harness cannot tell this walk's two branches apart and a green run is not
 * evidence for it. THE SUITE AT LARGE IS NOT SILENT ON IT: mutating the throw also reds
 * `BridgeCommandsTest::test_check_fails_on_unknown_treat_as_signal_name`, which drives an
 * unresolvable name through the command and asserts the substring plus the exit flip. What
 * `AgentTreatAsSignalCheckTest` adds is the severity and the composed message. (Named,
 * never `{@see}`-linked: pint would turn the FQCN into a real `use`.)
 */
final class AgentTreatAsSignalCheck implements Check
{
    public function id(): string
    {
        return 'agent.treat_as_signal';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->registry === null) {
            return;
        }

        foreach ($ctx->configs as $config) {
            try {
                SignalAllowlist::default($config->echoSuppression->treatAsSignal, $ctx->registry);
            } catch (Throwable $e) {
                yield Finding::fail("agent {$config->agentName}: ".$e->getMessage());
            }
        }
    }
}
