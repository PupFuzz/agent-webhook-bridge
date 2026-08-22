<?php

namespace App\Bridge\Tools;

/**
 * The SCOPE HEADER of a `board_my_cards` result — the (board, swimlane) pair that
 * says which agent's window a probe was answered from — read in ONE place for the
 * two live probes that certify it (`bridge:check --probe-tools` over HTTP and
 * `--probe-tools-ssh` over the forced command).
 *
 * ⛔ THE HEADER IS AN IDENTITY ECHO, NOT AN OBSERVATION, and a probe that treats it
 * as one is claiming something it did not measure. It reports the config of
 * whichever agent the presented bearer/key RESOLVED to, so comparing it against the
 * agent the operator meant to probe answers exactly one question — "did my bearer
 * reach the agent I think it did?" (a token collision or a mis-pinned key is what
 * makes that fail). It cannot answer "did the bridge-side row filter run", because
 * config matching config is true whatever the rows contained. Since DL-302 the
 * measured half is reported separately, in `board_id`/`board_observed`.
 *
 * ⚑ WHY THE BOARD HAS TWO SPELLINGS. Before DL-302 the header's board sat on
 * `board_id`; that key now carries the board the returned ROWS are on (null when no
 * row was read), and the header moved to `configured_board_id`. Both are read here,
 * newest first, because NEITHER probe is guaranteed to be talking to the install it
 * is running inside, so both can meet a responder predating DL-302: `--probe-tools`
 * POSTs to an operator-supplied vhost, and this repo's per-agent installation model
 * (CLAUDE.md rule 7) puts prod and dev installs on ONE box at independent versions,
 * both behind the loopback gate; `--probe-tools-ssh=<user@host-A>` round-trips to a
 * different HOST outright. Read strictly, such a responder answers no
 * `configured_board_id` at all and the probe would report an identity mismatch for a
 * version skew — a specific wrong cause rather than an honest one. Drop the fallback
 * once no supported install can answer a probe without `configured_board_id`.
 */
final class BoardToolsScopeHeader
{
    /**
     * The board the answering agent is CONFIGURED for, or null when the response
     * carries no readable one under either spelling.
     *
     * @param  array<string, mixed>  $result  the `result` object of a board_my_cards envelope
     */
    public static function boardId(array $result): ?int
    {
        $header = $result['configured_board_id'] ?? $result['board_id'] ?? null;

        return is_numeric($header) ? (int) $header : null;
    }

    /**
     * The swimlane the answering agent is CONFIGURED for. Unchanged by DL-302: the
     * lane axis was never restated wrongly, because every returned row is filtered
     * against this value before it is projected.
     *
     * @param  array<string, mixed>  $result  the `result` object of a board_my_cards envelope
     */
    public static function swimlaneId(array $result): ?int
    {
        $lane = $result['swimlane_id'] ?? null;

        return is_numeric($lane) ? (int) $lane : null;
    }
}
