<?php

namespace App\Bridge\Retention;

/**
 * What the retained store is HOLDING right now — the cost half of the retention posture
 * (card#8374).
 *
 * `RetentionConfig` answers *what will this install do*; this answers *what is it sitting
 * on*. They are separate types because they have separate failure modes: a config is read
 * from `config()` and cannot fail, while every field here is a query against a live
 * database that can be absent, unsupported or unreadable.
 *
 * ⛔ A NULL FIELD MEANS NOT MEASURED, NEVER ZERO, and the two are never allowed to
 * collapse — a store whose payload bytes could not be summed must not read as a store
 * holding no payloads. That is DL-306's *report only what is measured* applied here: the
 * consumer prints an ABSENCE for a null and a figure for an int, and has no third
 * option because there is no sentinel to confuse.
 *
 * `rows` / `rowsWithPayload` are NOT nullable, and that asymmetry is the design: their
 * SQL is portable, so a driver that cannot answer them cannot answer anything and the
 * probe throws instead of returning a half-built footprint. The nullable three are each
 * a fact one engine reports and another does not.
 */
final class RetentionFootprint
{
    public function __construct(
        /** `webhook_events` rows retained right now. */
        public readonly int $rows,
        /** How many of them still carry a payload (the rest are nulled or never had one). */
        public readonly int $rowsWithPayload,
        /** Bytes of stored payload, or null where the driver has no portable byte length. */
        public readonly ?int $payloadBytes,
        /**
         * The whole database's size as the ENGINE reports it — not a sum over the bridge's
         * own tables. Null where this driver reports none. It is the engine's own
         * accounting (SQLite's page count; MariaDB's `information_schema` estimate), which
         * is what an operator comparing it against `du` is looking at.
         */
        public readonly ?int $storeBytes,
        /** Age of the oldest retained row in days; null when there are no rows. */
        public readonly ?float $oldestRowAgeDays,
    ) {}
}
