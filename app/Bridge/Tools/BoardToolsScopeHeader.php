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
 * version skew — a specific wrong cause rather than an honest one.
 *
 * ⚠ THE FALLBACK'S REMOVAL CONDITION IS NOT AN INSTRUCTION IN THIS DOCBLOCK — it is
 * **card#7325 / DL-304**, which owns it, and the condition is MEASURED rather than
 * argued: every read reports {@see $boardSpelling}, both probes print it, and the
 * fallback may be dropped once no probed install has been observed answering under
 * {@see ScopeHeaderSpelling::Legacy}. `docs/board-tools.md` § *Which spelling the
 * probe read* states the derivation an operator runs. Do not delete the fallback on
 * the strength of this file alone: the reading it tolerates is a REMOTE install's,
 * which this repo cannot see.
 */
final class BoardToolsScopeHeader
{
    /**
     * @param  ?int  $boardId  the board the answering agent is CONFIGURED for, or null when
     *                         the response carries no readable one under either spelling
     * @param  ?int  $swimlaneId  the swimlane the answering agent is CONFIGURED for.
     *                            Unchanged by DL-302: the lane axis was never restated
     *                            wrongly, because every returned row is filtered against
     *                            this value before it is projected.
     * @param  ScopeHeaderSpelling  $boardSpelling  which key $boardId came from
     */
    private function __construct(
        public readonly ?int $boardId,
        public readonly ?int $swimlaneId,
        public readonly ScopeHeaderSpelling $boardSpelling,
    ) {}

    /**
     * Read the header out of a response ONCE, provenance included.
     *
     * One reader, not one per value: a second entry point re-deriving the same
     * newest-first chain to answer "which key did that come from" could disagree with
     * the value it describes, and the disagreement would surface as a probe line that
     * names the wrong spelling — i.e. as a wrong statement about install versions.
     *
     * @param  array<string, mixed>  $result  the `result` object of a board_my_cards envelope
     */
    public static function read(array $result): self
    {
        $configured = $result['configured_board_id'] ?? null;
        $legacy = $result['board_id'] ?? null;

        [$header, $spelling] = match (true) {
            $configured !== null => [$configured, ScopeHeaderSpelling::Configured],
            $legacy !== null => [$legacy, ScopeHeaderSpelling::Legacy],
            default => [null, ScopeHeaderSpelling::Absent],
        };
        $lane = $result['swimlane_id'] ?? null;

        return new self(
            is_numeric($header) ? (int) $header : null,
            is_numeric($lane) ? (int) $lane : null,
            $spelling,
        );
    }
}
