<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Tools\SshTransportProbe;

/**
 * The opt-in live ssh round-trip (card 4952, `--probe-tools-ssh=<user@host>`), migrated
 * out of `CheckCommand::probeBoardToolsSsh()` (DL-242 stage 1).
 *
 * Round-trips a real `board_my_cards` over the ssh-forced-command transport and certifies
 * the scope header matches a configured ssh agent's lane. A failure is HARD (→ non-zero
 * exit), like `--probe-tools`: it certifies the enablement.
 *
 * REGISTERED UNCONDITIONALLY, RUN UNCONDITIONALLY, SILENT WHEN NOT REQUESTED (plan
 * constraint (a)). The command no longer guards the call site on the flag; the flag is a
 * constructor argument and a null target yields nothing. That silence is the byte-identical
 * stage-1 behavior AND the exact seam the resolved opt-in-probe decision plugs into: "not
 * requested" is a third disposition owned by the inventory/renderer split, so stage 8/9
 * changes what THIS `return` produces without touching the command.
 *
 * ITS FIRST FINDING IS UNPREFIXED. `board_tools ssh probe: ` is not
 * `board_tools ssh: ` + `probe: `, so the two message shapes this check emits could not
 * share one render-time prefix — which is why checks yield display-ready messages during
 * the migration rather than the runner carrying a prefix.
 */
final class SshLiveProbeCheck implements Check
{
    /** @param string|null $target the `--probe-tools-ssh` value; null when the flag was not passed */
    public function __construct(
        private readonly SshProbeEnvironment $env,
        private readonly ?string $target,
    ) {}

    public function id(): string
    {
        return 'board_tools.ssh_live_probe';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($this->target === null) {
            return;   // stage 8/9 turns this into the `not requested` disposition
        }

        $sshAgents = array_values(array_filter(
            $ctx->configs,
            fn (AgentConfig $c) => $c->boardTools !== null && $c->boardTools->enabled && $c->boardTools->transport === 'ssh',
        ));
        if ($sshAgents === []) {
            yield Finding::warn('board_tools ssh probe: --probe-tools-ssh was given but no agent has an enabled ssh-transport board_tools block — nothing to certify.');

            return;
        }

        $expected = array_map(
            fn (AgentConfig $c) => ['agent' => $c->agentName, 'board_id' => $c->boardTools?->boardId, 'swimlane_id' => $c->boardTools?->swimlaneId],
            $sshAgents,
        );
        // No ssh_account: the live leg certifies the TARGET's forced command, which the
        // remote sshd resolves — the local account mapping is the pinned-line leg's job.
        $probe = new SshTransportProbe($this->env);

        foreach ($probe->probeLive($this->target, $expected) as $finding) {
            yield new Finding($finding->severity, 'board_tools ssh: '.$finding->message);
        }
    }
}
