<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use Throwable;

/**
 * Flag the population the DL-213 `comment_to` default flip cannot reach, migrated out of
 * `CheckCommand::handle()`'s per-agent loop (DL-242 stage 5a).
 *
 * `comment_to` joined the fleet `wake_membership` default, and a default only reaches
 * installs that set NO explicit value. An install that set `wake_membership` explicitly
 * BEFORE the flip overrides the default, so its directed-reply wakes stay dark — and the
 * flip must not silently rewrite a deliberate operator config. This warns exactly that
 * population (coord-message on, `wake_membership` explicit, `comment_to` omitted); an
 * absent-key install needs no warn, because the default now covers it.
 *
 * THE GATE IS PART OF THE CHECK, not an `if` around its invocation (plan constraint (a)):
 * the check owns its own applicability verdict, which is why stage 8 can account for a
 * not-applicable run here as a silent CHECK rather than as an un-invoked slot.
 *
 * `families` IS READ WITHOUT A `try` AND `wake_membership` WITH ONE — the asymmetry is
 * deliberate and load-bearing. `families` is EAGERLY parsed by {@see AgentConfig::load()},
 * so a malformed value has already thrown at load and can never reach this check;
 * `wake_membership` is lazy, so a malformed value first throws HERE. Hardening the
 * `families` read would add a branch no input can take.
 *
 * NO GOLDEN FIXTURE REACHES THAT `catch`, but the command-level suite does: mutating it
 * reds `BridgeCommandsTest::test_check_fails_on_malformed_wake_membership`, which writes a
 * non-list value and asserts the substring plus the exit flip. `WakeMembershipCheckTest` is
 * what pins the composed per-agent message. (Named, never `{@see}`-linked: pint would turn
 * the FQCN into a real `use`.)
 */
final class WakeMembershipCheck implements PerAgentCheck
{
    public function id(): string
    {
        return 'agent.wake_membership';
    }

    /**
     * @return iterable<Finding>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $name = $config->agentName;
        $families = $config->classifierConfig->strings('families');
        $coordMessageOn = $families === [] || in_array('coord-message', $families, true);

        if (! $coordMessageOn || ! $config->classifierConfig->has('wake_membership')) {
            return;   // nothing to report; the run inventory records the disposition (DL-242 stage 8)
        }

        try {
            $membership = $config->classifierConfig->strings('wake_membership');
            if (! in_array('comment_to', $membership, true)) {
                yield Finding::warn("agent {$name}: classifier.config.wake_membership = [".implode(', ', $membership)."] is set explicitly and omits comment_to — a counterparty's comment addressed TO you on a thread you neither opened nor were labelled on will NOT live-wake you (the common post-a-reply-and-wait flow). comment_to is now in the fleet default; add it to your explicit list to catch directed replies, or leave it off to keep them dark deliberately.");
            }
        } catch (Throwable $e) {
            yield Finding::fail("agent {$name}: classifier.config.wake_membership — ".$e->getMessage());
        }
    }
}
