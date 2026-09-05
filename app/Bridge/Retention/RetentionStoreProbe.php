<?php

namespace App\Bridge\Retention;

/**
 * The seam behind `bridge:check`'s retention COST line (card#8374) — the same shape as
 * the ssh / serving-process / channel-endpoint seams `BridgeServiceProvider` binds, and
 * bound alongside them.
 *
 * IT EXISTS BECAUSE THE SUBJECT IS THE STORAGE ENGINE, NOT THE FIXTURE. The golden
 * corpus is byte-identical output, and the suite runs against SQLite AND the MariaDB
 * matrix, so a line carrying "the database is 1.2 GiB" reports a different number on
 * every job — a host input of exactly the class `PinnedHost` exists to eliminate,
 * arriving as an unfixable golden diff rather than as a red assertion. The harness binds
 * a pinned answer; the default implementation queries the live store.
 *
 * MEASUREMENT FAULTS ARE THROWN, NOT ENCODED. A probe that returned "I could not read the
 * store" as a footprint would give every consumer a second empty-store shape to tell
 * apart from a real one — the collapse {@see RetentionFootprint} exists to prevent. The
 * caller catches and reports; that keeps the operator's vocabulary at the caller, where
 * `RetentionService::parseDays()` already puts it.
 */
interface RetentionStoreProbe
{
    public function measure(): RetentionFootprint;
}
