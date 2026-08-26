<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\ClientHalfLedger;
use App\Models\BoardToolsClientCall;
use Throwable;

/**
 * Does the CALLING SEAT's half of the board-tools door work? (card#7756 / DL-313)
 *
 * WHAT THIS CLOSES. Every other board-tools leg observes the BRIDGE side — the pinned
 * `authorized_keys` line, the flipped transport default, the writeback token's board
 * window. None of them can see the seat: its keypair, its seeded `known_hosts`, the
 * `BRIDGE_TOOLS_*` entries in its own `.mcp.json`, its deployed channel server. So a seat
 * that was NEVER WIRED client-side and a seat whose pinned line is merely absent printed
 * the same lines — and the two take OPPOSITE remedies. An operator acted on the wrong one
 * and spent a privileged remediation window on it.
 *
 * ⛔ THE BRIDGE MAY NOT SIMPLY GO AND LOOK. Reading `~<ssh_account>/.mcp.json` is the
 * obvious mechanism and is refused: an account may only read its own files. It is the same
 * rule that keeps `channel.server_path` an operator DECLARATION rather than an inference
 * off the agent's MCP config (DL-229), and the same one that stops `bridge:check` executing
 * the seat's channel server (DL-237). So the seat SELF-REPORTS — and it already does,
 * without a new tool or a single seat-side change: a successful board-tools call is the
 * report ({@see ClientHalfLedger}).
 *
 * ⭐ TWO OUTCOMES, NOT THREE, AND "NEVER WIRED" IS NOT ONE OF THEM. A seat that can report
 * is BY DEFINITION wired, so "never wired" is unobservable from here — it is the same
 * absence as "wired, and quiet". The leg therefore reports:
 *   - a fresh successful call ⇒ `ok`. POSITIVE PROOF: reaching {@see BoardToolDispatcher}
 *     requires the entire client chain, so the row is evidence rather than an inference
 *     from config.
 *   - no record, or one older than the freshness window ⇒ `unvalidated`. The leg did not
 *     answer its own question ({@see Severity} limb (a)/(c)) and NOTHING here
 *     distinguishes never-wired from merely-idle.
 * NEVER `fail` AND NEVER `warn`, on both halves of the rule: a `fail` would exit non-zero
 * over a seat that is idle by choice, and a `warn` would tell an operator something is
 * wrong when the honest statement is that this run learned nothing. The MESSAGE carries
 * that bound rather than leaving the severity to imply it — the remedy for an UNREPORTED
 * seat is to ASK THE SEAT, never to re-provision it.
 *
 * ⚑ THE AGE IS ALWAYS PRINTED, INCLUDING ON THE GREEN LINE. The TTL exists only to stop an
 * ancient stamp reading as current; an operator judging "3h ago" for themselves is worth
 * more than a boolean they have to trust, and it is what makes a seat drifting toward
 * silence visible BEFORE it crosses the window.
 */
final class BoardToolsClientHalfCheck implements PerAgentCheck
{
    public function id(): string
    {
        return 'board_tools.client_half';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $bt = $config->boardTools;
        // The slot's loop runs over `$ctx->boardToolsEnabled`, so the caller never asks
        // this about a disabled agent. The guard is here because THIS CHECK's subject is
        // the seat behind an ENABLED block: an agent with no block has no client half, and
        // a declared silence is how that is said (a suppressed block is reported by its own
        // check, and an undeclared silence would read as this leg falling off its end).
        if ($bt === null || ! $bt->enabled) {
            yield Silence::because('this agent has no enabled board_tools block, so there is no client half to report on — a suppressed block is reported by its own check');

            return;
        }

        $name = $config->agentName;
        $ttl = self::ttlSeconds();

        try {
            $row = BoardToolsClientCall::query()->where('agent', $name)->first();
        } catch (Throwable $e) {
            // Limb (a): the read never completed, so the absence below would be this run's
            // failure rather than the seat's silence. An unmigrated install is the live
            // cause — `bridge:check` must not ABORT on it (CheckRunner deliberately does
            // not catch), and must not report a green or a red it did not measure.
            yield Finding::unvalidated("board_tools: agent {$name}: could NOT read the client-half record — {$e->getMessage()}. This run says nothing about the seat's board-tools client half in either direction. If this install has not run `php artisan migrate` since the upgrade that added board_tools_client_calls, run it and re-run bridge:check.");

            return;
        }

        // ⛔ PLAIN TEXT, NO GLYPHS, AND NOT AS A STYLE PREFERENCE. `laravel/pao` binds its own
        // `OutputStyle` when it detects an AI AGENT running the command (never under
        // `runningUnitTests()`), and its `OutputCleaner` DELETES a fixed set of characters —
        // `⚠ ✔ ✖ → ● ▶` among them — collapses runs of spaces, and rewrites `...` to `..`.
        // A golden fixture is captured under the test path, so it would keep a glyph the
        // agent reading this line never receives: the emphasis would be asserted and absent
        // on exactly the surface that matters most here. Uppercase survives both readers.
        $remedy = 'ASK THE SEAT to make one board-tools call (board_my_cards) and re-run bridge:check — do NOT re-provision the seat on the strength of this line.';
        $blind = 'the bridge cannot tell a seat that was never wired from one that is simply idle — it may not read the seat\'s own keypair or .mcp.json (an account may only read its own files), so the seat has to tell it, and calling IS the telling.';

        if ($row === null) {
            yield Finding::unvalidated("board_tools: agent {$name}: client half UNREPORTED — this install has recorded no successful board-tools call from that seat. THIS IS NOT EVIDENCE THE SEAT IS UNWIRED: {$blind} {$remedy}");

            return;
        }

        // Carbon 3 returns a SIGNED float here, and both facts matter: the cast is what
        // keeps `humanAge()` integral, and the floor at zero is for a stamp in the future —
        // a clock stepping backwards on this host, which would otherwise render as a
        // negative age on a green line.
        $age = (int) max(0, $row->last_success_at->diffInSeconds(now()));
        if ($age > $ttl) {
            yield Finding::unvalidated("board_tools: agent {$name}: client half UNREPORTED — the seat's last successful board-tools call was ".self::humanAge($age).' ago (over '.$row->transport.'), older than the '.self::humanAge($ttl)." freshness window, so this run says nothing about whether it still works. THIS IS NOT EVIDENCE THE SEAT IS UNWIRED: {$blind} {$remedy}");

            return;
        }

        yield Finding::ok("board_tools: agent {$name}: client half WIRED — the seat's last successful board-tools call was ".self::humanAge($age).' ago, over '.$row->transport.'. Reaching this bridge takes the seat\'s whole client chain (keypair, known_hosts, BRIDGE_TOOLS_* in its .mcp.json, a deployed channel server, and on ssh the pinned forced command), so the call is proof rather than an inference.');
    }

    /**
     * How old a stamp may be and still read as current, in seconds.
     *
     * A non-positive value would make every stamp stale the instant it was written — an
     * operator's typo turning a green fleet plain — so it falls back to the shipped
     * default rather than being obeyed. That is not a silent correction of a meaningful
     * setting: there is no legitimate zero-second freshness window.
     */
    private static function ttlSeconds(): int
    {
        $configured = (int) config('bridge.board_tools.client_half_ttl');

        return $configured > 0 ? $configured : 7 * 86400;
    }

    /**
     * A duration an operator reads at a glance — `45s`, `12m`, `3h`, `9d`.
     *
     * FLOORED, NEVER ROUNDED, so the printed number is a lower bound on the real age and
     * "3h" can never be read off something that happened four hours ago. The unit is the
     * largest whole one, because the question this answers is "recent, or not really", and
     * a seat that last called `9d` ago is not made clearer by 217 hours.
     */
    private static function humanAge(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.'s',
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h',
            default => intdiv($seconds, 86400).'d',
        };
    }
}
