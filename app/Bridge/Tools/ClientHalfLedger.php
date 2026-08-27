<?php

namespace App\Bridge\Tools;

use App\Models\BoardToolsClientCall;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Record that the board-tools door was successfully opened for ONE agent — the durable
 * half of card#7756 / DL-313.
 *
 * WHAT A RECORD MEANS, and it is the whole design. A seat reaching
 * {@see BoardToolDispatcher::dispatch()}'s success point has exercised its entire client
 * chain: its keypair, its seeded `known_hosts`, the `BRIDGE_TOOLS_*` entries in its own
 * `.mcp.json`, a deployed channel server, and — on the ssh door — the pinned forced
 * command. So a call from the seat IS the seat's self-report that its half is wired, and it
 * needs no new tool, no new protocol, and nothing at all on the seat side.
 *
 * ⛔ WHAT A RECORD DOES NOT MEAN, and this row cannot tell the two apart. A seat is not the
 * only thing that reaches that success point: `bridge:check --probe-tools` POSTs a real
 * `board_my_cards` with the agent's own bearer, `bin/provision-board-tools.py --self-cert`
 * fires a real ssh round-trip, and an operator can run `bridge:tools-call --agent=X` on the
 * bridge host. Each stamps this row indistinguishably. The row therefore says the DOOR
 * opened for that agent — never that the SEAT opened it — and the reading check's `ok` line
 * carries that bound to the operator rather than letting the row imply more than it holds.
 *
 * ⛔ THE BRIDGE CANNOT ASK THE QUESTION ANY OTHER WAY. Reading `~<ssh_account>/.mcp.json`
 * was the obvious mechanism and is refused: an account may only read its own files. That is
 * the same rule that makes `channel.server_path` an operator DECLARATION rather than an
 * inference (DL-229) — an inference the bridge is not entitled to make produces a confident
 * wrong answer, which is worse than the gap.
 *
 * ⚑ RECORDING IS BEST-EFFORT, ALWAYS. The dispatcher's job is the CALL; this is an
 * observation ABOUT the call, and it runs after the tool has already read or written the
 * board. A failure here — an unmigrated install with no such table, a DB that went away
 * between the tool's query and this write — must therefore cost the caller nothing: the
 * response is already correct and re-running it would re-do the board work to fix an audit
 * row. So every throw is caught and logged, exactly as {@see
 * \App\Bridge\Writeback\BoardDivergenceLedger} does for its own mid-writeback row, and the
 * caller never learns this happened.
 *
 * ⭐ SINCE card#7836 THE ROW ALSO CARRIES HOW THE SERVING PROCESS WAS STARTED
 * ({@see CallProvenance}), which NARROWS the second paragraph without repealing it. ⭐ WHAT
 * A `sshd` STAMP RULES OUT, AND THE TWO THINGS IT DOES NOT, ARE OWNED BY {@see
 * CallProvenance} and are not restated here — this class's own subject is the WRITE.
 * The caller STATES its provenance rather than this class measuring one — the http door has
 * nothing to measure (`LoopbackOnly` makes probe and seat identical by construction) and
 * would read an inherited `SSH_CONNECTION` as the seat if it tried.
 *
 * ⛔ NAMES AND TIMESTAMPS ONLY — never a token, a secret, or a config VALUE. The row is
 * printed verbatim into a `bridge:check` line, so anything stored here is disclosed. That
 * governs the provenance too: what is written is the enum's NAME, never `SSH_CONNECTION`'s
 * contents, which are a client IP, a client port and this host's own address and port.
 */
final class ClientHalfLedger
{
    /**
     * Stamp `$agent`'s row with "reached the dispatcher just now, over `$transport`".
     *
     * ⚑ ONE STATEMENT, NOT read-then-write. Two doors can serve one agent concurrently, so
     * a `SELECT` followed by an `INSERT` races into a unique violation that costs the very
     * stamp this exists to keep. `upsert()` is a single INSERT … ON CONFLICT on both
     * supported drivers, which makes the concurrent case a plain UPDATE.
     *
     * ⚑ `last_success_at` IS PASSED EXPLICITLY rather than left to Eloquent. The model has
     * no `updated_at`, so nothing would maintain the stamp unless the write states it.
     *
     * ⚑ `call_provenance` IS IN THE UPDATE COLUMN LIST, not only the insert row, and both
     * halves matter. A seat that moves between doors must not leave the OLD door's
     * provenance standing — a stale `sshd` beside a fresh http call would be the exact
     * over-read card#7836 exists to remove — and it is written as the enum's `->value`
     * because `upsert()` is a raw builder write that never runs the model's casts.
     *
     * ⛔ THE PARAMETER IS REQUIRED, with no default. A default would make the strong verdict
     * depend on a caller REMEMBERING to opt in, and the failure mode of forgetting is
     * silent: the row still writes, the check still reports, and a genuine ssh call reads as
     * unproven forever. Every door states what it knows.
     */
    public static function record(string $agent, string $transport, CallProvenance $provenance): void
    {
        try {
            BoardToolsClientCall::query()->upsert(
                [['agent' => $agent, 'transport' => $transport, 'call_provenance' => $provenance->value, 'last_success_at' => now()]],
                ['agent'],
                ['transport', 'call_provenance', 'last_success_at'],
            );
        } catch (Throwable $e) {
            Log::warning(
                'agent-tools: the successful call could not be recorded — bridge:check will report this seat\'s client half as UNREPORTED until a later call lands',
                ['agent' => $agent, 'transport' => $transport, 'call_provenance' => $provenance->value, 'error' => $e->getMessage()],
            );
        }
    }
}
