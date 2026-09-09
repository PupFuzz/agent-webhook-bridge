<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckResult;
use App\Bridge\Check\NextSteps;
use App\Bridge\Check\NextStepState;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Tools\ClientHalfLedger;
use App\Bridge\Tools\ClientHalfRecord;
use App\Bridge\Tools\ConfigSeenLedger;
use App\Models\BoardToolsConfigSeen;
use Throwable;

/**
 * Has an agent's `board_tools` block been LOST? (card#8973 / DL-360)
 *
 * WHAT THIS CLOSES. `bridge:check` reads the config that is there NOW, and every board-tools
 * leg keys on the block being PRESENT: {@see BoardToolsClientHalfCheck} yields a declared
 * silence for an agent with no enabled block, the bearer and board-state planes never see it,
 * and the ssh legs run over the ssh subset it is not in. So an install whose blocks were
 * dropped — a home-dir restore that came back without them, a hand edit — renders exactly
 * like an install that was never provisioned: silence, exit 0. A live install ran ten days
 * with dead two-way board tools on that silence, and it surfaced only when a seat noticed a
 * tool returning a non-JSON body. A check that cannot fail on the failure it is run for is a
 * decoration.
 *
 * ⭐ THE WITNESS IS A DATABASE ROW, AND THAT IS THE WHOLE MECHANISM. `board_tools_config_seen`
 * ({@see ConfigSeenLedger}) records that a run of THIS install parsed an enabled block for an
 * agent. It lives in the bridge's own database, so restoring the config tree does not bring it
 * back, and its survival is exactly what makes the block's absence visible. ⚠ THE SURVIVAL IS
 * CONDITIONAL AND THE CONDITION IS STATED RATHER THAN ASSUMED: the database must not live
 * inside the restored tree. True for MariaDB and for a SQLite file outside the home dir; false
 * for a SQLite file under the restored path, where the witness dies with the config.
 *
 * ⛔ A `board_tools_client_calls` ROW ALONE NEVER FAILS, AND THAT IS AN OPERATOR RULING
 * DEVIATING FROM THE CARD'S OWN MECHANISM. That row says the board-tools DOOR OPENED for an
 * agent, which `bridge:check --probe-tools`, `bin/provision-board-tools.py --self-cert` and a
 * hand-run `bridge:tools-call --agent=X` all stamp indistinguishably — so any agent ever
 * probed and later renamed or removed carries one FOREVER, and treating it as "this seat had a
 * block" would flip `bridge:check`'s exit code on installs nobody had touched, the moment they
 * upgraded. It is quoted as EVIDENCE inside a LOST line and is never its trigger. THE COST IS
 * ACCEPTED AND NAMED: a seat whose block vanished BEFORE this release shipped has no
 * config-seen row and is never reported, because for that seat there will never be another
 * enabled sighting to record.
 *
 * ⭐ POPULATION FIRST, THEN THE DIRECTORY GATE, and the order is the design. An install with no
 * board-tools history has no subject here and must say nothing — asking "could I read the
 * config dir?" before asking "is there anything to check?" would turn an unreadable directory
 * on a fleet that never had board tools into a finding about a question nobody asked.
 *
 * ⛔ ONLY AN EXPLICIT `retired:` KEY SILENCES A RECORDED SEAT. An `enabled: false` block is a
 * decision WHILE PRESENT and nothing once deleted: deleting a declining seat's YAML re-opens
 * the question as a LOST FAIL whose remedy is the retirement. Deleting a seat is a
 * decommission, and the product asks for the decommission to be STATED. Recording `enabled:
 * false` as a durable tombstone was the alternative and was withdrawn: it would have made
 * `bridge:check`'s own `no_block` advice — *"NO ⇒ put board_tools: with enabled: false"* — a
 * permanent silent mute for that seat.
 *
 * ⚑ EVERY FINDING HERE CARRIES `agent: null` IN `--format=json`. This is a run-once
 * {@see Check}, so its {@see CheckResult} has no agent
 * ({@see CheckResult::$agent}) and the JSON renderer emits that, while
 * `docs/check-json-contract.md` tells consumers to use `findings[].agent` for per-agent
 * detail. THE SEAT IS NAMED IN PROSE ONLY. It is a run-once check because its population is
 * the RECORDED roster, which is not a subset of the agents the per-agent loop iterates — the
 * seats it exists to report are exactly the ones with no config to iterate.
 *
 * ⚑ IT WITHHOLDS THE `no_block` NEXT STEP for every seat it reports, through
 * {@see CheckContext::$boardToolsLost} — see {@see NextStepState::NoBlock} for why that
 * question must not be printed beside this FAIL.
 */
final class BoardToolsLostCheck implements Check
{
    /**
     * The registry id, as a constant for the same reason {@see BoardToolsClientHalfCheck::ID}
     * is one: a consumer selects this check by id rather than by matching its prose.
     */
    public const ID = 'board_tools.lost';

    /**
     * The section that owns the retirement runbook, in the `<path> § <heading>` shape
     * {@see NextSteps::DOC} uses — ONE constant, so the pointer the FAIL
     * line prints is the pointer a test can check against the doc's own heading line. A
     * pointer with no check is a comment.
     */
    public const DOC = 'docs/board-tools.md § Retiring a seat';

    /**
     * The one declaration for both zero-finding paths, and they are one statement rather than
     * two laundered into one: an install with NOTHING recorded and an install where everything
     * recorded is still present are both *"no recorded seat is now without its block"*, which
     * is why the empty population falls through here instead of returning early with a second
     * sentence.
     */
    private const NOTHING_LOST = 'no agent this install has recorded with an enabled board_tools block is now without one, and no config carries a retired key — the scan covers every recorded seat, including a fleet with no board_tools at all';

    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        try {
            // ⚑ MATERIALISED TO PLAIN ARRAYS INSIDE THE ENVELOPE. Eloquent applies its casts
            // LAZILY, on attribute access, so a row read here and a field read at an arm
            // below would put the throw OUTSIDE this try and abort `bridge:check` — the one
            // thing a diagnostic command may not do (CheckRunner deliberately does not
            // catch).
            $seen = [];
            foreach (BoardToolsConfigSeen::query()->get() as $row) {
                $seen[$row->agent] = [
                    'first' => $row->first_seen_at?->toIso8601String(),
                    'last' => $row->last_seen_at?->toIso8601String(),
                    'transport' => $row->transport,
                    'board' => $row->board_id,
                    'swimlane' => $row->swimlane_id,
                    'retired_reason' => $row->retired_reason,
                ];
            }
        } catch (Throwable $e) {
            // Limb (a): the read never completed, so an absence below would be this run's own
            // failure wearing the install's silence. An unmigrated install is the live cause.
            // ⛔ THE LINE NAMES BOTH CONSEQUENCES because ONE read carries both: this table is
            // also where the retirement leg's tombstone lives, so a `retired:` key in config
            // goes unanswered on this path too and the operator must not read the silence as
            // "no retirement to report".
            yield Finding::unvalidated("board_tools: could NOT read the config-seen ledger ({$e->getMessage()}) — a LOST block cannot be detected on this run, and a retired: key in config cannot be confirmed against its tombstone; run migrations");

            return;
        }

        yield from $this->retirements($ctx, $seen);

        // THE POPULATION IS THE RECORDED SEATS THAT WERE SEEN ENABLED. A row with a NULL
        // `last_seen_at` was born by a retirement of a seat this install never saw enabled —
        // there is no window to report and nothing to have lost.
        $recorded = [];
        foreach ($seen as $name => $row) {
            if ($row['last'] !== null) {
                // Cast because PHP silently turns a NUMERIC agent name into an int array
                // key, and every consumer below — the context field, the printed line — is
                // typed and rendered as the agent's NAME.
                $recorded[] = (string) $name;
            }
        }
        sort($recorded);

        if ($recorded !== []) {
            if ($ctx->configDirScanned !== true) {
                // Limb (a) again, and the reason it is a finding rather than a silence: the
                // seats ARE recorded, so this run had a subject and could not look at it.
                // Reporting nothing would be indistinguishable from reporting them all
                // present.
                yield Finding::unvalidated('board_tools: the config dir could not be scanned this run, so '.count($recorded).' recorded seat(s) cannot be checked for a LOST block — see the config-dir line above');

                return;
            }

            $parsed = [];
            foreach ($ctx->configs as $config) {
                $parsed[$config->agentName] = $config;
            }

            // ⭐ ITS OWN NARROW ENVELOPE, AND THE WIDTH IS THE POINT. This read resolves the
            // DL-316 enum, whose unknown backing value throws at the attribute read — and it
            // feeds ONE THING: the EVIDENCE clause of a LOST line (see this class's docblock;
            // the row is never a trigger). Sharing the config-seen ledger's envelope made a
            // throw from HERE print that ledger's message, so an install whose config-seen
            // read had just succeeded was told it had failed and sent to run migrations that
            // could not help — and the retirement leg below the shared envelope's `return`
            // went silent with it. A failure here therefore degrades the CLAUSE and nothing
            // else; the LOST verdict never depended on this table.
            $callsUnreadable = null;
            $calls = [];
            try {
                $calls = ClientHalfLedger::lastSuccesses();
            } catch (Throwable $e) {
                $callsUnreadable = $e->getMessage();
            }

            foreach ($recorded as $name) {
                // The YAML is on disk and did NOT parse: a `fail` on its own leg already, and
                // this run knows nothing about what its board_tools block says. Claiming the
                // block is gone would be a guess about a file it could not read.
                if (! isset($parsed[$name]) && in_array($name, $ctx->agentNames, true)) {
                    continue;
                }
                $config = $parsed[$name] ?? null;
                if ($config !== null && $config->boardTools !== null) {
                    continue;   // present in any form — enabled, disabled, suppressed, retired
                }
                if ($seen[$name]['retired_reason'] !== null) {
                    yield Silence::because('every recorded seat whose block is gone carries an explicit retirement tombstone');

                    continue;
                }

                $ctx->boardToolsLost[] = $name;
                yield Finding::fail($this->lostMessage($name, $seen[$name], $calls[$name] ?? null, $config === null, $callsUnreadable));
            }
        }

        yield Silence::because(self::NOTHING_LOST);
    }

    /**
     * The RETIRED arm — one line per config carrying a `retired:` key.
     *
     * ⛔ THE LINE CONFIRMS THE ROW, NEVER THE CONFIG, and the difference is the operator's
     * next action. The cure this leg prescribes for a deleted seat ends *"run bridge:check
     * once, then delete the YAML"* — so a "recorded" line sourced from the config the operator
     * just wrote would send them to delete the file over a tombstone that was never written,
     * and the seat would come back as a LOST FAIL with nothing left to retire it with. The
     * write is best-effort by construction, so it CAN have failed, and this arm is where that
     * becomes visible.
     *
     * @param  array<string, array{first: ?string, last: ?string, transport: ?string, board: ?int, swimlane: ?int, retired_reason: ?string}>  $seen
     * @return iterable<Finding>
     */
    private function retirements(CheckContext $ctx, array $seen): iterable
    {
        foreach ($ctx->configs as $config) {
            $reason = $config->boardTools?->retiredReason;
            if ($reason === null) {
                continue;
            }
            $name = $config->agentName;
            if (($seen[$name]['retired_reason'] ?? null) !== null) {
                yield Finding::ok("board_tools: agent {$name}: RETIRED — {$reason} (tombstone on record); the lost-block check is silenced for it — remove the retired key and re-add the block to bring it back");

                continue;
            }
            // ⛔ `warn`, NOT `unvalidated`, AND THE DISCRIMINATOR IS THIS LEG'S OWN QUESTION.
            // That question is "is the tombstone ON RECORD?" — the row was read, it is not
            // there, and THIS RUN ALREADY TRIED to write it (the sighting pass runs before the
            // checks), so the answer is measured and it is NO. `unvalidated` means the install
            // stopped the measurement (the Severity rule, limb (a)); what is uncertain here is
            // the FUTURE — whether the decision outlives the file stating it — and
            // world-ambiguity is not measurement-ambiguity, which that rule excludes by name.
            // Not `fail` either: nothing is broken yet, the config still states the decision,
            // and a best-effort audit row that lost a race must not flip the exit code of an
            // otherwise clean install.
            yield Finding::warn("board_tools: agent {$name}: retired in config but the tombstone could NOT be recorded (see the log) — do not delete {$name}.yml until a run prints RETIRED");
        }
    }

    /**
     * The LOST line: what was seen, when, what else this install knows about the seat, and the
     * TWO remedies — because a seat that is genuinely gone and a seat whose block was dropped
     * take opposite actions and only the operator knows which happened.
     *
     * ⚑ THE CLIENT-CALL CLAUSE IS EVIDENCE AND NEVER THE TRIGGER (see this class's docblock),
     * and it prints the TRANSPORT rather than the provenance: the sentence is about which
     * front door served the call, not about how the serving process was started.
     *
     * ⛔ AN UNREADABLE CLIENT-CALL LEDGER IS SAID, NOT SWALLOWED, AND IT IS SAID HERE RATHER
     * THAN AS A SECOND FINDING. The clause is printed only when a call exists, so dropping it
     * silently would let *"could not look"* render exactly like *"this install recorded no
     * call"* — the absence-never-measured this ledger's own reader refuses to hand its callers
     * ({@see ClientHalfLedger::lastSuccesses()} propagates for that reason). The finding stays
     * a `fail`: the leg's question — is a recorded seat's block gone? — was fully measured off
     * the config-seen row and the current config, and neither reads this table. A separate
     * `unvalidated` line would claim *"I should have measured this and the install stopped
     * me"* about a decoration, and would print on runs with no LOST line to decorate.
     *
     * ⛔ A NULL LEFT EDGE PRINTS "seen at", NOT A WINDOW WITH A HOLE IN IT, and this arm stays
     * even though {@see ConfigSeenLedger::recordEnabled()} now stamps the column. Interpolating
     * a row whose `first_seen_at` is NULL and whose `last_seen_at` is not produces *"was seen
     * from  to <last>"*, a malformed sentence on an operator's screen at the exact moment they
     * are being told their install is broken. ⚑ WHY SUCH A ROW IS STILL REACHABLE IS STATED
     * ONCE — on {@see ConfigSeenLedger::recordEnabled()}, the intra-call window between that
     * writer's two non-transactional statements — and is deliberately not restated here. This
     * is the render floor, not the cure; the cure is at the write site.
     *
     * @param  array{first: ?string, last: ?string, transport: ?string, board: ?int, swimlane: ?int, retired_reason: ?string}  $row
     */
    private function lostMessage(string $name, array $row, ?ClientHalfRecord $call, bool $yamlAbsent, ?string $callsUnreadable): string
    {
        $window = $row['first'] === null
            ? "was seen at {$row['last']}"
            : "was seen from {$row['first']} to {$row['last']}";

        $message = "board_tools: agent {$name}: block LOST — an enabled board_tools block {$window} (transport {$row['transport']}, board {$row['board']}, swimlane {$row['swimlane']})";

        if ($call !== null) {
            $message .= '; last successful tools call '.$call->lastSuccessAt->toIso8601String().' over '.$call->transport;
        } elseif ($callsUnreadable !== null) {
            $message .= "; whether this seat ever completed a tools call could NOT be read this run ({$callsUnreadable}), so no call evidence is quoted either way";
        }

        $message .= ', and the current config has no board_tools block. Re-add the block from the deploy\'s source of truth, or retire the seat explicitly: board_tools: {retired: "<ISO date> — <reason>"}';

        if ($yamlAbsent) {
            $message .= " — recreate {$name}.yml holding only that block, run bridge:check once (it prints RETIRED), then delete it";
        }

        return $message.' — '.self::DOC;
    }
}
