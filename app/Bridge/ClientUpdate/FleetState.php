<?php

namespace App\Bridge\ClientUpdate;

/**
 * A seat's client-update state, as {@see ClientFleet} derives it (card#10567 B4).
 *
 * ⭐ ORDERED AND TOTAL. {@see ClientFleet::stateOf()} tests the cases in declaration order and the
 * first that holds is the state; {@see self::Unverified} holds when none does, so every seat has
 * exactly one. The order is the precedence: a broken log outranks everything, because every other
 * state is read off that log (design review r2 m-1; r3-m3 made each definition single).
 * `ClientFleetStateTest` holds this order to the enum's own and puts one seat in every state.
 */
enum FleetState: string
{
    /** The current install's stored log is not a whole, unaltered chain: a gap, a broken link, or a seq re-sent with different bytes. */
    case LogDiscontinuity = 'log_discontinuity';

    /** The agent requires approval and the build it installed or runs has no approval of its content. */
    case UnapprovedInstall = 'unapproved_install';

    /**
     * The seat's most recent update failed — a background check, or a launch's install of the
     * staged release (DL-476) — and no later launch has been seen.
     */
    case UpdateFailed = 'update_failed';

    /** The agent requires approval, the published pack's content is not approved for it, and it does not have that pack. */
    case ApprovalOwed = 'approval_owed';

    /**
     * The seat's latest board-tools call carried no launch identity — a client not started by the
     * updater — and no launch or successful install was reported after it (card#11761: evidence
     * is ordered by when the bridge received it).
     */
    case OffUpdatePath = 'off_update_path';

    /**
     * No board-tools call from the seat's client and no successful install reported, since this
     * bridge started its fleet ledger: never seen, seen only by probes, or a bootstrap that reported
     * and did not complete.
     */
    case NeedsBootstrap = 'needs_bootstrap';

    /** Nothing from the seat — no call, no report — within {@see ClientFleet::STALE_AFTER_DAYS} days. */
    case Stale = 'stale';

    /** The launch the seat runs is the published release. */
    case Current = 'current';

    /**
     * The seat will run the published release at its next launch: it launched before that release
     * was published, or already installed it, or its background check has staged it (DL-476), or
     * installed a release that no launch has called the
     * bridge from yet — including since a launch or install reported after a call with no launch
     * identity (card#11761).
     */
    case AppliesNextLaunch = 'applies_next_launch';

    /** The seat launched after the release was published and still runs an older one, with no stage, failure or approval reported. */
    case Behind = 'behind';

    /**
     * The seat's release cannot be placed against the published one: the publication record is
     * unreadable, nothing is published, or the seat runs a release newer than the published one —
     * each named in the reason — or, last, a combination the derivation does not name.
     */
    case Unverified = 'unverified';

    /** The operator's name for the state, in `bridge:client-fleet` and `bridge:check`. */
    public function label(): string
    {
        return match ($this) {
            self::LogDiscontinuity => 'INSTALL LOG BROKEN',
            self::UnapprovedInstall => 'UNAPPROVED CLIENT',
            self::UpdateFailed => 'UPDATE FAILED',
            self::ApprovalOwed => 'APPROVAL OWED',
            self::OffUpdatePath => 'OFF THE UPDATE PATH',
            self::NeedsBootstrap => 'NEEDS BOOTSTRAP',
            self::Stale => 'not seen recently',
            self::Current => 'current',
            self::AppliesNextLaunch => 'restart owed',
            self::Behind => 'BEHIND',
            self::Unverified => 'UNVERIFIED',
        };
    }
}
