<?php

namespace App\Bridge\Writeback;

/**
 * What a card's stored `pr_number` is, relative to this event's — {@see StoredPrRef}'s number
 * axis. A number never names a pull request on its own (DL-429 Decision 1): which repo it
 * belongs to is the url axis's question.
 */
enum StoredPrNumberKind
{
    /** No `pr_number` (absent or empty). */
    case None;
    /** Not a pull-request number at all (`0`, free text, `#148` — DL-309). */
    case NamesNoPr;
    /** The same number as this event's. */
    case SameNumber;
    /** A number that is not this event's (or the event carries none). */
    case DifferentNumber;
}
