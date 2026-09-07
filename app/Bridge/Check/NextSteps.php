<?php

namespace App\Bridge\Check;

use App\Bridge\Check\Checks\BoardToolsClientHalfCheck;
use App\Bridge\Check\Checks\SshPinnedLineCheck;
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
 * the parsed agent configs, the bearer index the command built once, and the pinned-line
 * and client-half {@see CheckResult}s (read by check id, the way the command's own DL-225
 * readback reads them — and NOT through {@see CheckContext::$sshSetupIncomplete}, which
 * folds `unvalidated` into `true` and so cannot tell a blind read from a measured fault). It walks no directory, opens no token file and
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
    public const PROVISION = 'php artisan bridge:provision-tools --agent=';

    /**
     * The command for a bridge half this run COULD NOT MEASURE: the same check, as the
     * account that can read what this one could not. Never `bridge:provision-tools` — that
     * is the remedy for a measured fault, and running it on an unmeasured one is the
     * re-provision-a-working-seat cost the split exists to prevent.
     */
    private const RERUN_PRIVILEGED = 'sudo php artisan bridge:check';

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
        $pinnedLine = self::severitiesById($results, SshPinnedLineCheck::ID);
        $clientHalf = self::severitiesById($results, BoardToolsClientHalfCheck::ID);

        $steps = [];
        foreach ($ctx->configs as $cfg) {
            $name = $cfg->agentName;
            $state = self::stateOf(
                $cfg,
                $cfg->boardTools?->transport === 'ssh'
                    ? self::worst($pinnedLine[$name] ?? [])
                    : $ctx->boardToolsResolver?->bearerSeverity($name),
                in_array(Severity::Ok, $clientHalf[$name] ?? [], true),
            );
            if ($state === null) {
                continue;
            }
            $steps[] = new NextStep(
                agent: $name,
                state: $state,
                command: match ($state) {
                    NextStepState::NoBlock, NextStepState::BridgeSideIncomplete => self::PROVISION.$name,
                    NextStepState::BridgeSideUnverified => self::RERUN_PRIVILEGED,
                    // The seat's own wiring happens on the seat, which this box may not
                    // touch — so the command a BRIDGE reader can run is the one that
                    // re-asks the question once the seat has answered it by calling.
                    NextStepState::SeatSideUnreported => 'php artisan bridge:check',
                },
                doc: self::DOC,
            );
        }

        return $steps;
    }

    /**
     * Which state this agent is in, or null when it owes nothing.
     *
     * @param  ?Severity  $bridgeHalf  what this run concluded about the BRIDGE half of the
     *                                 door for this agent — the pinned-line probe's worst
     *                                 severity for an ssh agent, the bearer index's verdict
     *                                 for an http one; null where nothing was asked
     * @param  bool  $seatReported  whether this run observed a successful call for it
     */
    private static function stateOf(AgentConfig $cfg, ?Severity $bridgeHalf, bool $seatReported): ?NextStepState
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

        // ⭐ THE SPLIT THE STATE EXISTS FOR. `Unvalidated` is a leg that COULD NOT LOOK, and
        // it must not be spent as a fault: the remedy for a fault is to re-provision, the
        // remedy for a blind read is to re-run as the account that can read, and they are
        // opposites. `Warn` is grouped with `Fail` because both are MEASURED conclusions
        // (`CheckCommand::severityMeansSetupIncomplete()` draws the same line). `null` is
        // an enabled agent nothing asked about — unreachable by construction, since the
        // bearer index is built over every enabled http agent and the pinned-line slot runs
        // for every enabled ssh one — and is grouped with the measured arm rather than the
        // unverified one so that, if it ever fires, it sends the reader to a leg that
        // prints its own diagnosis instead of to `sudo`.
        $bridgeHalfState = match ($bridgeHalf) {
            Severity::Ok => null,
            Severity::Unvalidated => NextStepState::BridgeSideUnverified,
            Severity::Warn, Severity::Fail, null => NextStepState::BridgeSideIncomplete,
        };
        if ($bridgeHalfState !== null) {
            return $bridgeHalfState;
        }

        return $seatReported ? null : NextStepState::SeatSideUnreported;
    }

    /**
     * The one severity a set of findings from one leg amounts to: the MEASURED verdict
     * (`Fail`, then `Warn`) outranks a blind read (`Unvalidated`), which outranks `Ok` — a
     * leg that measured a fault AND could not read something else has still measured a
     * fault. Null for a leg that yielded nothing for this agent.
     *
     * @param  list<Severity>  $severities
     */
    private static function worst(array $severities): ?Severity
    {
        foreach ([Severity::Fail, Severity::Warn, Severity::Unvalidated, Severity::Ok] as $rank) {
            if (in_array($rank, $severities, true)) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * Every severity ONE per-agent check yielded, keyed by agent — selected BY ID, never by
     * walking the whole report, so a second check later registered in the same slot cannot
     * silently start feeding this derivation (the rule `CheckCommand`'s own pinned-line
     * readback follows).
     *
     * FOR THE CLIENT HALF THE CONSUMER KEYS ON THE PRESENCE OF `Ok`, NOT ON THE ABSENCE OF
     * `Unvalidated`, and the asymmetry is deliberate. {@see BoardToolsClientHalfCheck} yields
     * `unvalidated` for two different reasons — no fresh record, and a ledger read that
     * failed outright — and nothing in the severity separates them. Treating only a positive
     * `ok` as *observed* means an unreadable ledger produces an entry that says the seat has
     * not been observed, which is TRUE on both paths; the inverse rule would have produced a
     * silent clean over a measurement that never happened.
     *
     * @param  list<CheckResult>  $results
     * @return array<string, list<Severity>>
     */
    private static function severitiesById(array $results, string $id): array
    {
        $out = [];
        foreach ($results as $result) {
            if ($result->id !== $id || $result->agent === null) {
                continue;
            }
            foreach ($result->findings as $finding) {
                $out[$result->agent][] = $finding->severity;
            }
        }

        return $out;
    }
}
