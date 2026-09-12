<?php

namespace App\Bridge\Check;

use App\Bridge\Check\Checks\BoardToolsClientHalfCheck;
use App\Bridge\Check\Checks\SshPinnedLineCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;

/**
 * WHAT TO RUN NEXT to finish wiring this install, per agent (card#8959, DL-352 — widened
 * past board tools by card#9150).
 *
 * WHAT IT CLOSES. A fresh install ends with a check inventory and a tally, and nothing in
 * it says the two-way board window EXISTS — so an impl agent that could read, file and
 * correct its own cards through the bridge is never told the capability is there or how to
 * turn it on. The runbook has existed since DL-217; nothing pointed at it from the one
 * command a fresh install is told to run.
 *
 * ⛔ THE "PROMPT" IS OUTPUT, NEVER A TTY PROMPT, and that is structural rather than
 * stylistic: `bridge:check` must run HEADLESS, because the seat that runs install steps is
 * an agent's Bash with no TTY — a question there would not be answered, it would block
 * forever. So the prompt is a block the agent READS, on the command a fresh install runs.
 *
 * ⚠ THE RULE IS ABOUT THIS COMMAND, NOT ABOUT `app/`. It used to add *"there is no
 * `ask`/`confirm`/`choice` call anywhere in `app/`"* — a derivable census, and false: it
 * was falsified by `bridge:jobs install-tick` (card#9058) and again by `bridge:provision`'s
 * confirmed `identity_id` offer (card#9141). Both are MUTATING commands an operator runs by
 * hand, and both refuse rather than block where they cannot ask
 * (`App\Console\Commands\Bridge\BridgeCommand::canPromptToConfirm()` owns what that
 * means — install-tick's own copy of that predicate is card#9255). The census is not re-synced here: a claim about
 * the whole of `app/` has no business in the docblock of one renderer, and what is
 * load-bearing for this block is the sentence above it.
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
 * ⚠ THAT BY-ID RULE HAS EXACTLY ONE DELIBERATE REVERSAL, AND IT IS NAMED HERE RATHER THAN
 * LEFT TO BE FOUND (card#8973 / DL-360). The lost-block suppression below reads
 * {@see CheckContext::$boardToolsLost}, a context field, because the by-id route CANNOT
 * carry that fact: {@see self::severitiesById()} skips every result whose `agent` is null,
 * and a run-once {@see Check} always has a null agent ({@see CheckResult::$agent}), so
 * `board_tools.lost` — which names its seats in PROSE — is invisible to it in principle
 * rather than by accident. This is a stated exception, not a precedent: a PER-AGENT leg has
 * no such excuse and still reads by id.
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
 *
 * ⚠ THAT IS A STATEMENT ABOUT THIS DERIVATION, NOT ABOUT EVERY FAULT IT POINTS AT, and since
 * card#9150 the difference is visible on one screen. The board-tools entries point at legs
 * that never fail; a {@see NextStepState::GithubWebhookMissing} entry points at a leg that
 * DOES, so an install printing one exits non-zero — because of the finding above it, never
 * because of the line here. Reading the sentence above as *this block appearing means the run
 * still passed* was true when it was written and is not any more.
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
     * The section that owns the github repo-webhook runbook (card#9150).
     *
     * A SECOND CONSTANT RATHER THAN A WIDENED FIRST ONE: the two states point at genuinely
     * different runbooks, and one `doc` field covering both would have to name the shallower
     * of them. Same rule as {@see self::DOC} otherwise — one constant, read by both renderers
     * and by the tests, so a pointer restated at three sites cannot name a heading the doc no
     * longer has.
     */
    public const WEBHOOK_DOC = 'docs/writeback.md § The repo webhook (one-time, in GitHub)';

    /**
     * The command that acts on every bridge-side state — it is transport-aware, so one
     * spelling serves both doors: for an http agent it mints or names the bearer fault, for
     * an ssh agent it prints the per-agent setup packet (card#8971 / DL-357 — the whole
     * five-step, three-actor enablement exchange), and for an agent with no block at all it
     * prints the paste-ready skeleton.
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
     * Derive one entry per OUTSTANDING ITEM, in config order.
     *
     * ⚠ THE UNIT IS NOT UNIFORMLY THE AGENT, and this docblock said it was until card#9150
     * r3 — on the very method whose last statement is `array_merge($steps, self::webhookSteps($ctx))`.
     * The board-tools half below is per AGENT; {@see self::webhookSteps()} is per
     * **(agent, scope)**, because one repo's missing hook deafens every agent subscribed to
     * it. `docs/check-json-contract.md` § 7a states that for the consumer.
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
                self::seatReported($clientHalf[$name] ?? []),
            );
            if ($state === null) {
                continue;
            }
            // ⛔ ONE VOICE PER AGENT, AND THIS IS THE ONE STATE THAT CAN CONTRADICT A
            // FINDING ABOVE IT (card#8973 / DL-360). DL-357 Decision 8 ratified the
            // `no_block` wording on the premise that it is "the one state a
            // correctly-configured install can sit in forever" — a QUESTION for the
            // operator, never a defect this run found. A LOST FAIL two lines above breaks
            // that premise outright, and the advice underneath it ("NO ⇒ put board_tools:
            // with enabled: false") would MUTE the failure rather than answer it. The
            // remedy for a lost block is in the FAIL line; this block stays quiet about it.
            if ($state === NextStepState::NoBlock && in_array($name, $ctx->boardToolsLost, true)) {
                continue;
            }
            $steps[] = new NextStep(
                agent: $name,
                state: $state,
                command: self::commandFor($state, $name),
                doc: self::DOC,
            );
        }

        return array_merge($steps, self::webhookSteps($ctx));
    }

    /**
     * The ONE command a step in `$state` names, for `$agent`.
     *
     * ⛔ ONE EXHAUSTIVE MATCH FOR THE WHOLE ENUM, called by BOTH halves of the derivation
     * rather than one per half (card#9150). Two matches would each be exhaustive over the
     * cases their own half can produce and would each need a dead arm for the other half's —
     * a state's command decided in a branch nothing reaches, which is where a wrong command
     * hides. Here every arm is live, so a sixth state is a phpstan error at exactly one site
     * and has to be assigned deliberately.
     */
    private static function commandFor(NextStepState $state, string $agent): string
    {
        return match ($state) {
            NextStepState::NoBlock, NextStepState::BridgeSideIncomplete => self::PROVISION.$agent,
            NextStepState::BridgeSideUnverified => self::RERUN_PRIVILEGED,
            // The seat's own wiring happens on the seat, which this box may not touch — so
            // the command a BRIDGE reader can run is the one that re-asks the question once
            // the seat has answered it by calling.
            NextStepState::SeatSideUnreported => 'php artisan bridge:check',
            // Same shape, one plane over: the remedy is repo-settings work no command on this
            // box can perform (`bridge:provision` skips every non-kanban provider by design),
            // so what is named is the re-ask.
            NextStepState::GithubWebhookMissing => 'php artisan bridge:check',
        };
    }

    /**
     * One entry per (agent, scope) whose github webhook this run READ THE REPO'S HOOK LIST FOR
     * and did not find (card#9150).
     *
     * ⛔ ITS INPUT IS THE MEASURED-ABSENT SET AND NOTHING ELSE. {@see CheckContext::$githubWebhooksMissing}
     * is written only by the leg's `fail` arm, so an unmeasured scope cannot reach this block
     * — the same discipline the board-tools half draws between a MEASURED fault and a bridge
     * half this run could not read, applied to the one plane where the wrong call sends an
     * operator to re-create a hook that is already there.
     *
     * ⚑ PER (AGENT, SCOPE), NOT PER SCOPE, and the duplication is the honest shape: the
     * missing hook is one fault on the repo, but every agent subscribed to that repo is deaf
     * because of it, and this block's unit is *what THIS agent owes*. The `fail` finding above
     * is the one line about the repo; these are the one line per seat it silenced.
     *
     * THEY COME LAST, after every board-tools entry, because the block is read top-down and
     * the board-tools half is the one an install works through in order. Within this half the
     * order is the leg's own reporting order (config order, then subscription order), which is
     * the order the findings above printed in.
     *
     * @return list<NextStep>
     */
    private static function webhookSteps(CheckContext $ctx): array
    {
        $steps = [];
        foreach ($ctx->githubWebhooksMissing as $missing) {
            foreach ($missing['agents'] as $agent) {
                $steps[] = new NextStep(
                    agent: $agent,
                    state: NextStepState::GithubWebhookMissing,
                    command: self::commandFor(NextStepState::GithubWebhookMissing, $agent),
                    doc: self::WEBHOOK_DOC,
                    scope: $missing['scope'],
                );
            }
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
     * Did this run OBSERVE a successful board-tools call for the agent whose client-half
     * severities these are?
     *
     * THE CONSUMER KEYS ON A POSITIVE REPORT, NOT ON THE ABSENCE OF `Unvalidated`, and the
     * asymmetry is deliberate. {@see BoardToolsClientHalfCheck} yields `unvalidated` for two
     * different reasons — no fresh record, and a ledger read that failed outright — and
     * nothing in the severity separates them. Treating only a positive report as *observed*
     * means an unreadable ledger produces an entry that says the seat has not been observed,
     * which is TRUE on both paths; the inverse rule would have produced a silent clean over
     * a measurement that never happened.
     *
     * ⭐ A POSITIVE REPORT IS `Ok` OR `Warn`, and the second is what card#8974 / DL-364
     * added. Both are the SAME finding — the `client half REPORTED` line — and the severity
     * between them turns on the seat's SNAPSHOT VERSION, not on whether it called: a seat
     * running a stale channel server has still reported, and telling its operator to go ask
     * it to call would send them after the one thing that already happened, while the actual
     * remedy (re-deploy the snapshot) is printed on the line above. Keying on `Ok` alone did
     * exactly that.
     *
     * ⛔ THIS IS STILL AN INSTALL FACT INFERRED FROM A SEVERITY — the coupling DL-238(g)
     * names, here in its second instance. It is sound only while `Warn` has exactly one
     * producer in that check; `BoardToolsClientHalfCheckTest` pins the severity set the check
     * can emit and `CheckNextStepsTest` pins this consequence, so a third producer cannot
     * arrive unnoticed. The root-cause fix is the one DL-251 already names: have the check
     * report the fact rather than have this derive it from how the finding printed.
     *
     * @param  list<Severity>  $severities
     */
    private static function seatReported(array $severities): bool
    {
        return in_array(Severity::Ok, $severities, true)
            || in_array(Severity::Warn, $severities, true);
    }

    /**
     * Every severity ONE per-agent check yielded, keyed by agent — selected BY ID, never by
     * walking the whole report, so a second check later registered in the same slot cannot
     * silently start feeding this derivation (the rule `CheckCommand`'s own pinned-line
     * readback follows).
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
