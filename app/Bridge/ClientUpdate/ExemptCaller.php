<?php

namespace App\Bridge\ClientUpdate;

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
     * The one request body a bridge-side probe sends: a real `board_my_cards`, declared as a probe.
     *
     * @return array{tool: string, args: object, caller: string}
     */
    public static function probeBody(): array
    {
        return ['tool' => 'board_my_cards', 'args' => (object) [], 'caller' => self::Probe->value];
    }
}
