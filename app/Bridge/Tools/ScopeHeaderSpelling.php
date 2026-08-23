<?php

namespace App\Bridge\Tools;

/**
 * WHICH KEY a `board_my_cards` response answered its scope header under — the
 * provenance of {@see BoardToolsScopeHeader::$boardId}, reported by both live probes
 * so the version-skew fallback's removal condition is a state an operator can READ
 * off a probe rather than a sentence someone has to re-reason (card#7325, DL-304).
 *
 * ⛔ THE TWO SPELLINGS DO NOT MEAN THE SAME THING ON A CURRENT RESPONDER. Since
 * DL-302 `configured_board_id` is the identity echo and `board_id` carries where the
 * returned ROWS are; before DL-302 `board_id` WAS the echo. So the fallback that
 * reads the old key is right for an old responder and a conflation for a new one,
 * and nothing in a response tells the two apart except the presence of the new key
 * — which makes {@see self::Legacy} exactly the observable the removal condition
 * needs: the fallback may go once no probed install answers this way.
 *
 * WHY A PROVENANCE AND NOT A VERSION. The removal condition was originally written
 * as a version compare — "the oldest bridge version any probed install still runs" ≥
 * "the release that first emitted `configured_board_id`". The first half is not
 * answerable over the wire: the board-tools envelope is `{ok, tool, result}` (see
 * {@see DispatchOutcome::body}) and carries no version, and a version field added
 * now would be missing on precisely the responders the question is about. The
 * spelling is the predicate that compare was a proxy for, measured directly, on the
 * round trip the probe already makes.
 */
enum ScopeHeaderSpelling
{
    /** The response named the identity echo itself — a DL-302-or-later responder. */
    case Configured;

    /** No `configured_board_id`; the header came from `board_id` — the pre-DL-302 shape. */
    case Legacy;

    /** Neither key answered: this response carries no identity echo at all. */
    case Absent;

    /**
     * The operator-facing sentence for this provenance.
     *
     * Owned HERE because both live probes print it and there is no version of this
     * text that is worth having twice — the identity-echo caveat beside it is already
     * stated once per probe, and DL-302's own review found the drift that produces
     * (a first pass corrected one copy and left the other saying the false thing).
     */
    public function note(): string
    {
        return match ($this) {
            self::Configured => 'Header spelling: `configured_board_id` — this responder names the identity echo itself (DL-302 or later).',
            self::Legacy => '⚠ VERSION SKEW — this responder answered NO `configured_board_id`, so the header was read under the legacy `board_id` spelling (the pre-DL-302 shape, where that key WAS the echo). On a DL-302-or-later responder the same key carries where the returned ROWS are, so the value compared here is an observation being read under an identity\'s name — correct for this old responder, and the one place the conflation DL-302 removed still survives. Upgrade the responding install past DL-302; until no probed install answers this way the fallback stays, and this line is the measurement it waits on (card#7325, DL-304).',
            self::Absent => 'Header spelling: NEITHER — this responder answered no board header under `configured_board_id` or the legacy `board_id`, so there was no identity echo to compare.',
        };
    }
}
