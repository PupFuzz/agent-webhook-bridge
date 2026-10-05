<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Tools\ClientHalfLedger;

/**
 * A board-tools caller that is NOT a seat's channel server, declared by the call's optional
 * `caller` key (card#10567 B4). Such a call proves the door opens, and says nothing about which
 * client the seat runs, so the fleet ledger stamps its time and nothing else
 * ({@see SeatClientLedger::recordCall()}) — it never overwrites what the seat's own calls reported
 * (design review r2 M-3).
 *
 * ⛔ EVERY PROGRAM IN THIS REPO THAT CALLS THE DOOR WITHOUT BEING A CHANNEL SERVER SENDS ONE.
 * `ExemptCallerSendersTest` derives that population from the source and reds on a sender that
 * does not (design review r3-M3 found the third one by that derivation).
 *
 * ⛔ SELF-DECLARED, LIKE `client_version`: it can never refuse a call, and a value that is not one
 * of these cases is read as absent — a seat's call. Nothing here is authentication.
 */
enum ExemptCaller: string
{
    /** `bridge:check --probe-tools` and `--probe-tools-ssh`. */
    case Probe = 'probe';

    /** `bin/provision-board-tools.py --self-cert`. */
    case SelfCert = 'self-cert';

    /** A person running `bridge:tools-call` by hand, who says so. */
    case Operator = 'operator';

    /**
     * The seat's `bridge-board-call` CLI (card#11151 / DL-451): a hook or script calling one tool
     * with the seat's own credential. It ships in the client, so it is not the seat's channel
     * server and must not overwrite what that server reports.
     */
    case Script = 'script';

    /**
     * The one request body a bridge-side probe sends: a real `board_my_cards`, declared as a probe.
     *
     * @return array{tool: string, args: object, caller: string}
     */
    public static function probeBody(): array
    {
        return ['tool' => 'board_my_cards', 'args' => (object) [], 'caller' => self::Probe->value];
    }

    /** The repo a scope-less probe names: shaped like a repo, and owned by nobody. */
    public const SCOPELESS_PROBE_REPO = 'bridge-probe/no-such-repo';

    /**
     * The probe body for a SCOPE-LESS agent (card#11283), which is refused `board_my_cards`: a
     * real `ci_await_cancel` through the dispatcher, on a head no seat can await (the all-zero
     * SHA of a repo nobody owns), declared as a probe. It WRITES NOTHING to the await store —
     * cancelling an absent await answers `cancelled: false` — and leaves exactly what any
     * successful call leaves: the client-half row ({@see ClientHalfLedger}),
     * the config-seen sighting, and this caller's own probe column in the fleet ledger, never the
     * seat's report of its client.
     *
     * @return array{tool: string, args: array{repo: string, head_sha: string}, caller: string}
     */
    public static function scopelessProbeBody(): array
    {
        return ['tool' => 'ci_await_cancel', 'args' => ['repo' => self::SCOPELESS_PROBE_REPO, 'head_sha' => str_repeat('0', 40)], 'caller' => self::Probe->value];
    }
}
