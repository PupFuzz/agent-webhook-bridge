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
            // ⚑ MATERIALISED TO PLAIN ARRAYS INSIDE THE ENVELOPE, and both halves of that
            // matter. Eloquent applies its casts LAZILY, on attribute access, so a row read
            // here and a field read at an arm below would put the throw OUTSIDE this try and
            // abort `bridge:check` — the one thing a diagnostic command may not do
            // (CheckRunner deliberately does not catch). The
            // client-half reader is inside for the same reason: it resolves the DL-316 enum,
            // whose unknown backing value throws at the read.
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
            $calls = ClientHalfLedger::lastSuccesses();
        } catch (Throwable $e) {
            // Limb (a): the read never completed, so an absence below would be this run's own
            // failure wearing the install's silence. An unmigrated install is the live cause.
            yield Finding::unvalidated("board_tools: could NOT read the config-seen ledger ({$e->getMessage()}) — a LOST block cannot be detected on this run; run migrations");

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
                yield Finding::fail($this->lostMessage($name, $seen[$name], $calls[$name] ?? null, $config === null));
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
            // Limb (a): the tombstone write did not complete, so this run cannot promise the
            // decision will outlive the file that states it.
            yield Finding::unvalidated("board_tools: agent {$name}: retired in config but the tombstone could NOT be recorded (see the log) — do not delete {$name}.yml until a run prints RETIRED");
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
     * @param  array{first: ?string, last: ?string, transport: ?string, board: ?int, swimlane: ?int, retired_reason: ?string}  $row
     */
    private function lostMessage(string $name, array $row, ?ClientHalfRecord $call, bool $yamlAbsent): string
    {
        $message = "board_tools: agent {$name}: block LOST — an enabled board_tools block was seen from {$row['first']} to {$row['last']} (transport {$row['transport']}, board {$row['board']}, swimlane {$row['swimlane']})";

        if ($call !== null) {
            $message .= '; last successful tools call '.$call->lastSuccessAt->toIso8601String().' over '.$call->transport;
        }

        $message .= ', and the current config has no board_tools block. Re-add the block from the deploy\'s source of truth, or retire the seat explicitly: board_tools: {retired: "<ISO date> — <reason>"}';

        if ($yamlAbsent) {
            $message .= " — recreate {$name}.yml holding only that block, run bridge:check once (it prints RETIRED), then delete it";
        }

        return $message.' — '.self::DOC;
    }
}
