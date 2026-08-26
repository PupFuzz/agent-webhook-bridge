<?php

namespace App\Bridge\Tools;

use App\Models\BoardToolsClientCall;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Record that ONE agent's seat successfully reached the board-tools dispatcher — the
 * durable half of card#7756 / DL-313.
 *
 * WHAT A RECORD MEANS, and it is the whole design. Nothing reaches
 * {@see BoardToolDispatcher::dispatch()}'s success point without the CALLING SEAT's entire
 * client chain already working: its keypair, its seeded `known_hosts`, the `BRIDGE_TOOLS_*`
 * entries in its own `.mcp.json`, a deployed channel server, and — on the ssh door — the
 * pinned forced command. So a successful call IS the seat's self-report that its half is
 * wired, and it needs no new tool, no new protocol, and nothing at all on the seat side.
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
 * ⛔ NAMES AND TIMESTAMPS ONLY — never a token, a secret, or a config VALUE. The row is
 * printed verbatim into a `bridge:check` line, so anything stored here is disclosed.
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
     * no `updated_at`, so nothing would maintain it — and the near-miss to avoid is
     * `updateOrCreate()`, which issues no UPDATE at all when every filled attribute already
     * matches: a seat calling repeatedly with an unchanged transport would keep its FIRST
     * stamp forever and age into the check's UNREPORTED arm while calling every hour.
     */
    public static function record(string $agent, string $transport): void
    {
        try {
            BoardToolsClientCall::query()->upsert(
                [['agent' => $agent, 'transport' => $transport, 'last_success_at' => now()]],
                ['agent'],
                ['transport', 'last_success_at'],
            );
        } catch (Throwable $e) {
            Log::warning(
                'agent-tools: the successful call could not be recorded — bridge:check will report this seat\'s client half as UNREPORTED until a later call lands',
                ['agent' => $agent, 'transport' => $transport, 'error' => $e->getMessage()],
            );
        }
    }
}
