<?php

namespace App\Bridge\Check;

use App\Bridge\Check\Checks\BoardToolsClientHalfCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;

/**
 * WHAT AN AGENT SHOULD RUN NEXT to finish enabling board tools, per agent (card#8959,
 * DL-352).
 *
 * WHAT IT CLOSES. A fresh install ends with a check inventory and a tally, and nothing in
 * it says the two-way board window EXISTS — so an impl agent that could read, file and
 * correct its own cards through the bridge is never told the capability is there or how to
 * turn it on. The runbook has existed since DL-217; nothing pointed at it from the one
 * command a fresh install is told to run.
 *
 * ⛔ THE "PROMPT" IS OUTPUT, NEVER A TTY PROMPT, and that is structural rather than
 * stylistic: every `bridge:*` command is non-interactive by construction (there is no
 * `ask`/`confirm`/`choice` call anywhere in `app/`), because the seat that runs install
 * steps is an agent's Bash with no TTY — an interactive wizard would block it forever. So
 * the prompt is a block the agent READS, on the command a fresh install already runs.
 *
 * ⭐ IT DERIVES, IT DOES NOT MEASURE. Every input is something this run already produced:
 * the parsed agent configs, the bearer index the command built once, the ssh
 * setup-incompleteness the command already reads back off the pinned-line findings, and
 * the client-half {@see CheckResult}s. It walks no directory, opens no token file and
 * issues no board read — a second walk would be a second answer able to disagree with the
 * lines printed above it, which is the defect this whole command's registry exists to
 * remove.
 *
 * ⚠ ITS POPULATION IS THE AGENTS WHOSE YAML PARSED ({@see CheckContext::$configs}), which
 * is narrower than the agents on disk. An agent whose config did not parse is already a
 * `fail` with its own line, and this run knows nothing about its board_tools block — so it
 * gets no entry rather than a guessed one. The block is a POINTER to work, not an
 * inventory of the install; {@see CheckInventory} is the inventory.
 *
 * ⛔ IT CANNOT FLIP THE EXIT CODE, by construction and not by discipline: it returns
 * values and yields no {@see Finding}, and only a `fail` finding moves
 * `bridge:check`'s verdict. Nothing here changes what the command accepts or rejects.
 */
final class NextSteps
{
    /**
     * The section that owns the enablement runbook.
     *
     * ONE CONSTANT, READ BY BOTH RENDERERS AND BY THE TESTS, because a pointer restated at
     * three sites is three chances to name a heading the doc no longer has.
     */
    public const DOC = 'docs/board-tools.md § Same-box enablement (Apache/FPM)';

    /**
     * The command that acts on every bridge-side state — it is transport-aware, so one
     * spelling serves both doors: for an http agent it mints or names the bearer fault, for
     * an ssh agent it prints the ready-to-run provisioning invocation for each leg, and for
     * an agent with no block at all it prints the paste-ready skeleton.
     *
     * ⛔ THE SKELETON IS NOT REPRODUCED HERE. `ProvisionToolsCommand::printSkeleton()` owns
     * those lines; a second copy in this file would be the one that goes stale the next time
     * a `board_tools` key is added.
     */
    private const PROVISION = 'php artisan bridge:provision-tools --agent=';

    /**
     * Derive one entry per agent whose board-tools enablement is incomplete, in config
     * order.
     *
     * FIRST MATCH WINS, and the order of the arms is the order the work has to happen in:
     * an agent with no block cannot have a bearer fault, and an agent whose bridge half is
     * broken has nothing to ask of its seat yet. So each agent yields the EARLIEST
     * unfinished step, never a list of everything still outstanding.
     *
     * @param  list<CheckResult>  $results  every result this run produced ({@see CheckRunner::results()})
     * @return list<NextStep>
     */
    public static function derive(CheckContext $ctx, array $results): array
    {
        $indexed = $ctx->boardToolsResolver?->indexedAgents() ?? [];
        $reported = self::clientHalfReported($results);

        $steps = [];
        foreach ($ctx->configs as $cfg) {
            $state = self::stateOf($cfg, $ctx, $indexed, $reported);
            if ($state === null) {
                continue;
            }
            $steps[] = new NextStep(
                agent: $cfg->agentName,
                state: $state,
                command: $state === NextStepState::SeatSideUnreported
                    // The seat's own wiring happens on the seat, which this box may not
                    // touch — so the command a BRIDGE reader can run is the one that
                    // re-asks the question once the seat has answered it by calling.
                    ? 'php artisan bridge:check'
                    : self::PROVISION.$cfg->agentName,
                doc: self::DOC,
            );
        }

        return $steps;
    }

    /**
     * Which state this agent is in, or null when it owes nothing.
     *
     * @param  list<string>  $indexed  agents whose bearer the resolver indexed
     * @param  array<string, true>  $reported  agents whose client half this run observed
     */
    private static function stateOf(AgentConfig $cfg, CheckContext $ctx, array $indexed, array $reported): ?NextStepState
    {
        $bt = $cfg->boardTools;
        if ($bt === null) {
            return NextStepState::NoBlock;
        }

        if (! $bt->enabled) {
            // A DEFAULT-on block that could not satisfy itself is unfinished work; an
            // EXPLICIT `enabled: false` is a decision, and the two are distinguishable
            // exactly because `suppressedReason` is non-null only on the first.
            return $bt->suppressedReason !== null ? NextStepState::BridgeSideIncomplete : null;
        }

        // The ssh door authenticates by the pinned forced-command `--agent` and carries no
        // bearer, so the bearer index says nothing about it; its bridge-side completeness
        // is the pinned line, which `CheckCommand` has already read back off the
        // pinned-line findings by the time this runs.
        $bridgeSideBroken = $bt->transport === 'ssh'
            ? isset($ctx->sshSetupIncomplete[$cfg->agentName])
            : ! in_array($cfg->agentName, $indexed, true);

        if ($bridgeSideBroken) {
            return NextStepState::BridgeSideIncomplete;
        }

        return isset($reported[$cfg->agentName]) ? null : NextStepState::SeatSideUnreported;
    }

    /**
     * Agents this run OBSERVED a successful board-tools call for.
     *
     * KEYED ON THE `ok` FINDING RATHER THAN ON THE ABSENCE OF AN `unvalidated` ONE, and the
     * asymmetry is deliberate. {@see BoardToolsClientHalfCheck} yields `unvalidated` for
     * two different reasons — no fresh record, and a ledger read that failed outright — and
     * nothing in the severity separates them. Treating only a positive `ok` as *observed*
     * means an unreadable ledger produces an entry that says the seat has not been observed,
     * which is TRUE on both paths; the inverse rule would have produced a silent clean over
     * a measurement that never happened.
     *
     * @param  list<CheckResult>  $results
     * @return array<string, true>
     */
    private static function clientHalfReported(array $results): array
    {
        $reported = [];
        foreach ($results as $result) {
            if ($result->id !== BoardToolsClientHalfCheck::ID || $result->agent === null) {
                continue;
            }
            foreach ($result->findings as $finding) {
                if ($finding->severity === Severity::Ok) {
                    $reported[$result->agent] = true;
                }
            }
        }

        return $reported;
    }
}
