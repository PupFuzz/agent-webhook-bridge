<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Tools\SshTransportProbe;

/**
 * The offline SSH-transport pinned-line probe (card 4952), migrated out of
 * `CheckCommand::checkSshTransport()` (DL-242 stage 1).
 *
 * Per ssh agent, verify the pinned `authorized_keys` line forces
 * `bridge:tools-call --agent=X` and denies pty+forwarding (OUTCOME-based, never a
 * `restrict` keyword match) plus a FIPS key algo on a FIPS seat. Only a `fail` finding
 * flips the exit.
 *
 * IT IS PER-AGENT BECAUSE THE FORCED-COMMAND ACCOUNT IS (`board_tools.ssh_account`,
 * default the invoking run-user), so the keys resolution is per-agent — and because its
 * caller's iteration is a SECOND per-agent loop, distinct from the main config loop.
 * That is the pair of iterations that made {@see CheckSlot} necessary.
 *
 * THE DL-225 ADVISORY IS DELIBERATELY NOT HERE. It reads these findings' severities but
 * emits AFTER the whole loop, so folding it in would either reorder output or make this
 * check stateful across agents. It stays inline in `checkSshTransport()`, reading the
 * severities off this check's report — a stage-2-7 unit, not stage 1's.
 */
final class SshPinnedLineCheck implements PerAgentCheck
{
    public function __construct(private readonly SshProbeEnvironment $env) {}

    public function id(): string
    {
        return 'board_tools.ssh_pinned_line';
    }

    /**
     * @return iterable<Finding>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        // Constructed per agent: the probe binds the agent's forced-command account.
        $probe = new SshTransportProbe($this->env, $config->boardTools?->sshAccount);

        foreach ($probe->probePinnedLine($config->agentName) as $finding) {
            yield new Finding($finding->severity, 'board_tools ssh: '.$finding->message);
        }
    }
}
