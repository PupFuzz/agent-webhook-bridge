<?php

namespace App\Bridge\Check\DeliveryHistory;

/**
 * Where a declared github scope's own delivery record puts it, at one instant (DL-382).
 *
 * ⭐ CUT ON TWO AXES, and the second axis is what the leg is for: *is the silence past the threshold*, and *could a
 * threshold be derived at all*. A record too short to derive from is its own state on BOTH sides of the floor, never
 * folded into a healthy one — a check that could not say what routine silence looks like for a scope has not
 * established that the scope is fine.
 */
enum DeliveryHistoryState: string
{
    /** The retained record holds no delivery for the scope, spelled exactly as declared. */
    case NeverDelivered = 'never_delivered';

    /** A threshold was derived and the silence since the last delivery does not exceed it. */
    case WithinThreshold = 'within_threshold';

    /** A threshold was derived and the silence since the last delivery exceeds it. */
    case PastThreshold = 'past_threshold';

    /** No threshold could be derived, and the silence already exceeds the floor every threshold starts from. */
    case UnderivedPastFloor = 'underived_past_floor';

    /** No threshold could be derived, and the silence is inside the floor — which says nothing about this scope. */
    case UnderivedWithinFloor = 'underived_within_floor';

    /**
     * Whether this state names a scope that has gone quiet — a `warn` and a NEXT STEPS entry.
     *
     * ⛔ `UnderivedWithinFloor` IS NOT LOUD AND IS NOT HEALTHY: the check renders it `unvalidated`.
     */
    public function isLoud(): bool
    {
        return match ($this) {
            self::NeverDelivered, self::PastThreshold, self::UnderivedPastFloor => true,
            self::WithinThreshold, self::UnderivedWithinFloor => false,
        };
    }
}
