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
 * newest first, because the ssh probe can round-trip to a bridge install that is NOT
 * this one (`--probe-tools-ssh=<user@host-A>` is run from a host that can reach A) —
 * a responder predating DL-302 would otherwise answer a null board and the probe
 * would report an ISOLATION failure for a version skew, which is a specific wrong
 * cause rather than an honest one. Drop the fallback once no supported install can
 * answer a probe without `configured_board_id`.
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
