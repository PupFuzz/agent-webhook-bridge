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
            self::Legacy => '⚠ Header spelling: LEGACY — this responder answered NO `configured_board_id`, so the header was read under the legacy `board_id` spelling, which on a DL-302-or-later responder carries where the returned ROWS are: the value compared here is an observation read under an identity\'s name. Likeliest cause is a responder predating DL-302 — upgrade it and re-probe; a relay, or a responder that emits the header conditionally, reads the same way. Until no probed install answers this way the fallback stays, and this line is the measurement it waits on (card#7325, DL-304).',
            self::Absent => 'Header spelling: NEITHER — this responder answered no board header under `configured_board_id` or the legacy `board_id`, so there was no identity echo to compare.',
        };
    }

    /**
     * The CAUSE half of an `IDENTITY MISMATCH` tail — owned HERE for the same reason
     * {@see self::note()} is, and read off the same one provenance, because the cause a
     * mismatch HAS depends on which spelling answered: a responder that identified
     * itself and answered someone else's scope is a credential fault, and one that
     * identified itself not at all is not (card#7325, DL-304).
     *
     * ⛔ THE CREDENTIAL CAUSE IS NOT AVAILABLE ON {@see self::Absent}. Asserting it there
     * sends the operator at a token path that may be doing its job — the reachable states
     * are a wrong vhost, a relay, or a JSON service that is not this tool at all — and the
     * spelling sentence printed beside it denies the claim in the same string.
     *
     * @param  string  $credential  what the probe presented, named as the finding names it
     * @param  string  $credentialFix  where to look when the responder DID identify itself
     * @param  string  $routeFix  where to look when it did NOT — nothing about the
     *                            credential is shown by a response that echoes no identity
     */
    public function mismatchCause(string $credential, string $credentialFix, string $routeFix): string
    {
        return match ($this) {
            self::Configured, self::Legacy => "The scope header is an identity echo, so what this shows is that {$credential} resolved to a DIFFERENT agent's window — {$credentialFix}.",
            self::Absent => "The scope header is an identity echo and this response carries none, so it does not show which agent {$credential} reached — {$routeFix}.",
        };
    }
}
