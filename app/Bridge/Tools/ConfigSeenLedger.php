<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BoardToolsConfig;
use App\Models\BoardToolsConfigSeen;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Record what this install has SEEN in an agent's `board_tools` block — the durable witness
 * of card#8973 / DL-360, and the half `bridge:check` cannot get from the current config.
 *
 * WHAT A ROW MEANS, and it is the whole design. `bridge:check` reads the config that is
 * there NOW, so an agent whose block was dropped — a home-dir restore that came back without
 * it, a hand edit — is indistinguishable from an agent that never had one: both are silence,
 * both exit 0. A row here says a run of THIS install parsed an ENABLED block for that agent,
 * which is exactly the fact the current config can no longer supply, and it lives in the
 * bridge's own database so a restore of the config tree does not bring it back.
 *
 * ⛔ WHAT A ROW DOES NOT MEAN. It is not evidence the seat ever CALLED — that is
 * {@see ClientHalfLedger}'s row and a different question — and it is not evidence the block
 * was correct, only that it parsed as enabled. It also cannot speak for a seat this install
 * never ran a check against: the witness starts at the first sighting, so a block that
 * vanished BEFORE this release shipped leaves nothing behind (DL-360's stated cost).
 *
 * ⛔ IT IS DELIBERATELY NOT THE CLIENT-CALLS ROW, AND THAT IS AN OPERATOR RULING RATHER THAN
 * AN IMPLEMENTATION CHOICE. A client-calls row records that the DOOR OPENED, which
 * `bridge:check --probe-tools`, `bin/provision-board-tools.py --self-cert` and a hand-run
 * `bridge:tools-call` all stamp indistinguishably — so any agent ever probed and later
 * renamed or removed carries one forever, and treating it as "this seat had a block" would
 * flip `bridge:check`'s exit code on installs nobody had touched. The lost leg quotes that
 * row as EVIDENCE inside its line and never as its trigger.
 *
 * ⚑ RECORDING IS BEST-EFFORT, ALWAYS, exactly as {@see ClientHalfLedger} is. Two of the
 * three callers are a DIAGNOSTIC COMMAND and a live tool call: neither may fail because an
 * audit row could not be written, and `bridge:check` in particular must never abort — a
 * command that dies while reporting on an install is worse than one that reports a gap. So
 * every throw is caught and logged, and the log line names the CONSEQUENCE (the lost check
 * goes blind for that seat) rather than the exception, because the consequence is what the
 * operator has to act on.
 *
 * ⛔ NEVER read-then-write. Two doors can serve one agent concurrently and a check can run
 * beside them, so a `SELECT` followed by an `INSERT` races into a unique violation that costs
 * the very sighting this exists to keep. `upsert()` is a single INSERT … ON CONFLICT on both
 * supported drivers. {@see recordEnabled()} follows it with ONE more statement — a blind,
 * `WHERE first_seen_at IS NULL` UPDATE — which is not a read: the condition is evaluated by
 * the database inside the write, so concurrent runs cannot disagree about it.
 */
final class ConfigSeenLedger
{
    /**
     * Record the sightings in one run's parsed configs: every ENABLED block, and every
     * `retired:` key.
     *
     * THE LOOP LIVES HERE RATHER THAN IN `CheckCommand::handle()` ON PURPOSE. `handle()`'s
     * branch predicates are the population `docs/check-golden-coverage.md` measures and
     * `bin/check-golden-predicates.php` enumerates, so a `foreach` with an `if` inside it
     * would add three predicates to a mutation-measured surface to express one fact. Here it
     * is ordinary code with its own unit tests.
     *
     * ⛔ AN `enabled: false` BLOCK AND A SUPPRESSED ONE RECORD NOTHING, and that is the rule
     * the whole leg turns on. A present block in any form is not lost, so it needs no
     * witness; recording `enabled: false` as a durable decision was tried and withdrawn
     * (DL-360), because it would have made `bridge:check`'s own `no_block` advice — "NO ⇒ put
     * board_tools: with enabled: false" — a permanent silent mute for that seat.
     *
     * @param  list<AgentConfig>  $configs
     */
    public static function recordSightings(array $configs): void
    {
        foreach ($configs as $config) {
            $bt = $config->boardTools;
            if ($bt === null) {
                continue;
            }
            if ($bt->enabled) {
                self::recordEnabled($config->agentName, $bt);

                continue;
            }
            if ($bt->retiredReason !== null) {
                self::recordRetired($config->agentName, $bt->retiredReason);
            }
        }
    }

    /**
     * Stamp `$agent`'s row with "an enabled block was parsed for this agent just now".
     *
     * ⚑ `first_seen_at` IS ABSENT FROM THE UPDATE LIST, DELIBERATELY. It is the left edge of
     * the window the LOST line prints, and a window whose left edge moves every run is not a
     * window — it collapses to "just now" and stops telling the operator whether the seat ran
     * for a day or for a year.
     *
     * ⭐ AND THAT IS WHY A SECOND, GUARDED STATEMENT WRITES IT WHEN IT IS STILL NULL. Absent
     * from the update list, the column is never written on ANY conflict path — so a row born
     * by {@see recordRetired()}, which INSERTs `first_seen_at` as NULL, kept a NULL left edge
     * forever, and the LOST line that seat later produced printed a HEADLESS window: *"was
     * seen from  to <last>"*. The sequence is the one the product prescribes (retire a
     * never-seen seat, then remove the key and re-add the block, which is verbatim what the
     * RETIRED line says to do), so it is reached, not theoretical. ⛔ THE `whereNull` IS WHAT
     * MAKES IT UNABLE TO MOVE AN EXISTING VALUE: a row that already carries a left edge is
     * not in the UPDATE's population at all, on either driver — the guard is the WHERE clause
     * rather than a value comparison, so there is no arm in which an older stamp is
     * overwritten by a newer one. ⚑ IT IS A SECOND STATEMENT RATHER THAN A `COALESCE` IN THE
     * UPSERT BECAUSE ONE STATEMENT CANNOT BE WRITTEN PORTABLY HERE: naming the incoming row
     * inside the update list spells `values(col)` under `MySqlGrammar` and `excluded.col`
     * under `SQLiteGrammar`, so a raw expression would be correct on the driver it was
     * written for and a syntax error on the other. This UPDATE compiles identically on both.
     *
     * ⛔ THE TWO STATEMENTS ARE NOT ONE TRANSACTION, AND THAT — NOT AN OLDER RELEASE — IS THE
     * ONLY THING THAT STILL PRODUCES A ROW WITH A RIGHT EDGE AND NO LEFT ONE. This is the one
     * place that fact is written down; `BoardToolsLostCheck`'s null-left-edge render arm and
     * DL-360 Decision 1 point HERE rather than restating it. **No shipped release can have
     * written such a row** — `board_tools_config_seen` is created by DL-360's own migration and
     * no tag carries it — so "a row an older release wrote" is not a reachability argument for
     * that arm. The reachable window is INSIDE THIS CALL, on the revive path and only there:
     * the upsert commits its update of a RETIREMENT-BORN row — whose `first_seen_at` is NULL by
     * {@see recordRetired()} and is not in the update list — and the guarded UPDATE beneath it
     * then throws (connection loss, lock-wait timeout, deadlock), leaving the row carrying
     * `last_seen_at` with `first_seen_at` still NULL. (An INSERT writes both edges in the one
     * statement, so a first sighting cannot produce that row at all.) ⚑ It is deliberately NOT wrapped
     * in a transaction — the window is SELF-HEALING, since the next enabled sighting's UPDATE
     * finds the column still NULL and stamps it, and this writer is best-effort on a live
     * tool-call path, so a transaction would buy atomicity for a fact the next run re-establishes.
     *
     * ⚑ ONE `$now` FEEDS BOTH STATEMENTS, resolved once. Both bind at SECOND precision, so
     * reading the clock twice let a revive that straddled a second boundary stamp a
     * `first_seen_at` one second AFTER the `last_seen_at` written beside it — an INVERTED
     * window, rendered to the operator as *"was seen from 12:00:19 to 12:00:18"*.
     *
     * ⭐ AN ENABLED SIGHTING CLEARS A TOMBSTONE, and the two NULLs are written EXPLICITLY into
     * the INSERT row rather than being left off it. `MySqlGrammar::compileUpsert()` compiles a
     * list-style update column to `col = values(col)`, which reads the value from the INSERT
     * row — so a column named in the update list but absent from that row would clear to the
     * column DEFAULT rather than to the value this writer intends, and the intent would be
     * carried by a grammar detail instead of by this code. Re-adding a retired block is the
     * operator re-opening the question their retirement closed, so the tombstone has to go.
     */
    public static function recordEnabled(string $agent, BoardToolsConfig $bt): void
    {
        $now = now();

        try {
            BoardToolsConfigSeen::query()->upsert(
                [[
                    'agent' => $agent,
                    'transport' => $bt->transport,
                    'board_id' => $bt->boardId,
                    'swimlane_id' => $bt->swimlaneId,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'retired_seen_at' => null,
                    'retired_reason' => null,
                ]],
                ['agent'],
                ['transport', 'board_id', 'swimlane_id', 'last_seen_at', 'retired_seen_at', 'retired_reason'],
            );

            BoardToolsConfigSeen::query()
                ->where('agent', $agent)
                ->whereNull('first_seen_at')
                ->update(['first_seen_at' => $now]);
        } catch (Throwable $e) {
            Log::warning(
                'agent-tools: recording the board_tools block sighting failed part-way — if the sighting row itself did not land, bridge:check cannot report this seat\'s block as LOST if it later disappears; if only the first_seen_at stamp did not, the sighting STANDS and the LOST line prints "was seen at" until a later sighting stamps the left edge',
                ['agent' => $agent, 'transport' => $bt->transport, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * Stamp `$agent`'s row with the operator's explicit retirement.
     *
     * ⚑ THE SEEN WINDOW SURVIVES: `first_seen_at` / `last_seen_at` are absent from the update
     * list, so retiring a seat does not erase the record that it once ran. On INSERT they are
     * written as NULL, because a retirement can legitimately be the FIRST thing this install
     * ever recorded for an agent — the operator retiring a seat whose block was already gone,
     * which is the very cure the LOST line prescribes. ⭐ A ROW BORN THAT WAY GETS ITS LEFT
     * EDGE FROM THE SIGHTING THAT REVIVES IT, never from here: see
     * {@see recordEnabled()}'s guarded UPDATE. Stamping one here would put a seat this
     * install never saw enabled into the lost leg's population, which reads a non-NULL
     * `last_seen_at` as "seen enabled".
     *
     * ⛔ CALLED ONLY FOR A NON-NULL `retiredReason`. The reason is the operator's own sentence
     * stored VERBATIM, and it is printed back to them: a row with an empty reason would be a
     * tombstone that silences a seat while naming no decision.
     */
    public static function recordRetired(string $agent, string $reason): void
    {
        try {
            BoardToolsConfigSeen::query()->upsert(
                [[
                    'agent' => $agent,
                    'transport' => null,
                    'board_id' => null,
                    'swimlane_id' => null,
                    'first_seen_at' => null,
                    'last_seen_at' => null,
                    'retired_seen_at' => now(),
                    'retired_reason' => $reason,
                ]],
                ['agent'],
                ['retired_seen_at', 'retired_reason'],
            );
        } catch (Throwable $e) {
            Log::warning(
                'agent-tools: the board_tools retirement could not be recorded — bridge:check cannot report this seat\'s block as LOST if it later disappears',
                ['agent' => $agent, 'error' => $e->getMessage()],
            );
        }
    }
}
