<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;

/**
 * The v0.68.0 pre-upgrade advisory (DL-225): an agent that landed on the ssh transport via
 * the FLIPPED DEFAULT — no explicit `transport:` key — whose ssh setup is not yet complete
 * will lose its loopback HTTP path on upgrade, because the implicit-http block now reads as
 * ssh, the bearer stops resolving and the call fails closed (401). Migrated out of
 * `CheckCommand::checkSshTransport()` (DL-242 stage 7b).
 *
 * ADVISORY ONLY — it warns and never fails. The runtime break is already loud; this is the
 * pre-deploy heads-up. An EXPLICIT `transport:` choice is intentional and never advised,
 * which is what `transportExplicit` records.
 *
 * ITS INPUT IS ANOTHER CHECK'S REPORT, NOT THE INSTALL — the one place in this registry
 * where that is true. {@see CheckContext::$sshSetupIncomplete} is read back off
 * {@see CheckSlot::BoardToolsSsh}'s findings by `CheckCommand`, which selects them BY CHECK
 * ID so a second check registered into that slot cannot silently start feeding this
 * advisory. The consequence to keep in view: this check is downstream of another check's
 * SEVERITY vocabulary, so a re-assignment there changes what this advises with nothing in
 * this file to show for it. **DL-251 was that re-assignment** — both of
 * `SshPinnedLineCheck`'s `warn`s became `unvalidated`, and `severityMeansSetupIncomplete()`
 * had to widen in the same commit or this advisory would have gone silent on the very
 * install shape it exists for. The coupling is unchanged, and so is the reason to keep
 * naming it; the root-cause fix (have the pinned-line leg report the FACT instead of this
 * inferring it from a severity) is named in DL-251 and not built.
 *
 * ABSENT ⇒ NOT INCOMPLETE, deliberately. The map carries only agents whose pinned-line
 * findings said otherwise, so an agent whose ssh setup verified clean and an agent whose
 * slot never ran are both absent — the inline code read the same `?? false`. That is
 * correct for an advisory whose action is "pin transport: http": nothing to advise on
 * evidence nobody produced.
 */
final class BoardToolsSshDefaultAdvisoryCheck implements PerAgentCheck
{
    public function id(): string
    {
        return 'board_tools.ssh_flipped_default';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $bt = $config->boardTools;
        if ($bt === null || $bt->transportExplicit) {
            yield Silence::because('this agent has no board_tools block, or it names transport: explicitly — the advisory exists only for a block that got ssh from the v0.68.0 flipped default');

            return;
        }
        if (($ctx->sshSetupIncomplete[$config->agentName] ?? false) !== true) {
            yield Silence::because('this agent is on ssh by the flipped default, and the ssh slot reported its setup complete — the advisory has nothing to warn about');

            return;
        }

        yield Finding::warn("board_tools ssh: agent {$config->agentName} is on ssh by the v0.68.0 default (no explicit transport:); its ssh setup is incomplete or could not be verified from here — pin `transport: http` to keep the loopback path, or complete ssh setup and run `sudo bridge:check` to certify.");
    }
}
