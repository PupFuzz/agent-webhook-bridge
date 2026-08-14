<?php

namespace App\Bridge\Writeback;

/**
 * What a co-present `card#` token says about a DL that RESOLVED — the
 * discriminated result of the writeback classifier's shared token predicate,
 * consumed identically by its three sites (the move path, the branch-create
 * `started` push, and the draft overlay).
 *
 * The card token is THREE-STATE, not two (card#6027 / DL-287): absent, PARSED,
 * or present-but-UNREADABLE (a card-shaped spelling the grammar rejects). The
 * predicate this enum answers for used to take a `?int` token, which read
 * "unreadable" as "absent" and switched the DL-218 conflict guard off for
 * exactly the subjects that needed it.
 */
enum CardTokenVerdict
{
    /** No card token at all, or a PARSED one naming a card the DL resolved to — the DL wins, nothing is dropped. */
    case DlWins;
    /** A PARSED `card#` naming a card the DL did NOT resolve to — the explicit token is authoritative (DL-218). */
    case CardTokenAuthoritative;
    /** An UNREADABLE card token that names a card the DL resolved to anyway — redundant, so the DL still wins. */
    case NearMissRedundant;
    /** An UNREADABLE card token that does not name a card the DL resolved to — the move is refused (DL-287). */
    case NearMissRefusal;
}
