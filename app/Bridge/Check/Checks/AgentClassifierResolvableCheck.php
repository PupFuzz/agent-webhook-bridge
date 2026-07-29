<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\Finding;
use Throwable;

/**
 * Can this agent's classifier actually be resolved? Migrated out of
 * `CheckCommand::handle()`'s per-agent loop (DL-242 stage 5a).
 *
 * The classifier FQCN is otherwise only resolved at dispatch time, where a bad value is
 * an uncaught 5xx — and the upstream retries, so one typo becomes a retry storm.
 * Reporting it at preflight is the difference between finding out here and finding out
 * from the retry storm.
 *
 * THE OUT-OF-PROCESS PROBE COMES FIRST AND THAT ORDER IS LOAD-BEARING (#2053). An
 * out-of-date `classify()` signature is an UNCATCHABLE `E_COMPILE_ERROR`, so loading the
 * class in this process to test it would kill `bridge:check` itself. The child process
 * absorbs that; only once it passes is {@see ClassifierResolver::for()} safe to call
 * here. The `for()` arm is therefore belt-and-braces by construction — no golden fixture
 * reaches it, and `AgentClassifierResolvableCheckTest` is its whole measurement. (Test
 * classes are NAMED, never `{@see}`-linked: pint's docblock fixer turns a fully-qualified
 * `{@see}` into a real `use`, which would make app code import a test class.)
 *
 * ITS `ok` MESSAGE IS BROADER THAN WHAT IT MEASURES, and that is preserved rather than
 * corrected. The line reads `agent config ok: {name}`, but the YAML load it sounds like
 * it certifies happens in `handle()` — this check only reaches its first statement
 * because the load already succeeded. The id is the narrow, honest one; the message is
 * the shipped wording, held byte-identical because stages 0-7 are a pure refactor. Stage
 * 8's inventory is where the two can be reconciled without an operator-visible change
 * being smuggled into a refactor.
 *
 * IT IS THE PER-AGENT ABORT GATE. A `fail` here means every remaining leg for this agent
 * is skipped — see `CheckSlot::AgentClassifier` for why that constraint lives on the slot
 * rather than in this class.
 */
final class AgentClassifierResolvableCheck implements PerAgentCheck
{
    public function id(): string
    {
        return 'agent.classifier_resolvable';
    }

    /**
     * @return iterable<Finding>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $name = $config->agentName;

        if (($err = ClassifierResolver::probeLoadable($config->classifierClass)) !== null) {
            yield Finding::fail("agent {$name}: {$err}");

            return;
        }

        try {
            ClassifierResolver::for($config);
        } catch (Throwable $e) {
            // The narrow form the plan allows to migrate: one resolving call, no
            // derivation. `CheckRunner` deliberately does not catch, so without this
            // the arm would abort `bridge:check` rather than report the agent it names.
            yield Finding::fail("agent {$name}: ".$e->getMessage());

            return;
        }

        yield Finding::ok("agent config ok: {$name}");
    }
}
