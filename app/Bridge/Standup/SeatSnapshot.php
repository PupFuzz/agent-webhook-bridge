<?php

namespace App\Bridge\Standup;

/**
 * One seat's row in the standup digest (DL-306).
 *
 * ⛔ `lastDeliveryAt` IS A DELIVERY TIME AND THE KEY SAYS SO. The bridge records when
 * it PUSHED an event at a seat; nothing records whether the seat read it, acted on it,
 * or is mid-turn on something else. A `last_activity` key over this value would teach
 * its reader to treat a push timestamp as evidence a seat is alive, which is the one
 * inference the bridge has no signal for — so the key is `last_delivery_at` and there
 * is no activity/liveness/context-% sibling to omit it in favour of.
 *
 * NULL means the ledger holds no DELIVERED dispatch for this seat, and it renders as an
 * ABSENT key rather than a null, an epoch, or "never" ({@see SeatSnapshot::toArray()}). The three
 * things that produce it are not distinguishable from here and the digest does not
 * pretend otherwise: the seat has genuinely never been delivered to; its rows aged out
 * of the retention window (`bridge.retention.older_than`, 30d by default); or its only
 * rows predate DL-036, whose NULL `outcome` cannot say whether a processed dispatch was
 * a delivery or a gate-drop.
 */
final class SeatSnapshot
{
    public function __construct(
        public readonly string $agent,
        public readonly ?string $lastDeliveryAt,
        public readonly int $unseenInboxIntents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = ['agent' => $this->agent];

        // Key-ABSENT, not null-valued: a consumer reading `last_delivery_at` out of this
        // payload must get "the bridge is not answering that for this seat", and a null
        // in a JSON object is a value that arithmetic and templating both flatten to 0/"".
        if ($this->lastDeliveryAt !== null) {
            $row['last_delivery_at'] = $this->lastDeliveryAt;
        }

        // The zero IS printed, unlike the key above: the inbox was read and held nothing
        // unseen. That is a measurement, and collapsing it into an absent key would make
        // "this seat is caught up" indistinguishable from "nothing looked".
        $row['unseen_inbox_intents'] = $this->unseenInboxIntents;

        return $row;
    }
}
