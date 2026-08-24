<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\BoardToolsScopeHeader;
use App\Bridge\Tools\ScopeHeaderSpelling;
use Tests\TestCase;

/**
 * The scope header reader and — since card#7325 (DL-304) — its PROVENANCE.
 *
 * ⛔ The provenance is not decoration: it is the measurement the version-skew
 * fallback's removal condition waits on. "No supported install can answer a probe
 * without `configured_board_id`" was an unowned sentence in a docblock; the state it
 * describes is {@see ScopeHeaderSpelling::Legacy} never being observed by a probe, so
 * the value and the spelling have to come out of ONE read of ONE response. A second
 * reader re-deriving "which key did that come from" could disagree with the value it
 * describes, and the disagreement would print as a wrong claim about install versions.
 *
 * Hermetic by construction: this class touches no config, no filesystem and no
 * network — it reads an array literal.
 */
class BoardToolsScopeHeaderTest extends TestCase
{
    /** The current shape: the identity echo under its own name, with the row reading beside it. */
    public function test_a_current_responder_is_read_under_the_configured_key(): void
    {
        $header = BoardToolsScopeHeader::read([
            'board_id' => 20, 'board_observed' => true, 'configured_board_id' => 10, 'swimlane_id' => 4,
        ]);

        $this->assertSame(10, $header->boardId);
        $this->assertSame(4, $header->swimlaneId);
        $this->assertSame(ScopeHeaderSpelling::Configured, $header->boardSpelling);
    }

    /**
     * ⛔ THE POTENCY CASE. `board_id: 20` is a row OBSERVATION and the header is 10 —
     * a reader that preferred the old key, or that reported the spelling from a
     * separate chain, would answer 20 here and call the responder current. That is
     * exactly the conflation DL-302 removed, so this pins both halves against it.
     */
    public function test_the_configured_key_wins_over_a_divergent_row_reading(): void
    {
        $header = BoardToolsScopeHeader::read(['board_id' => 20, 'configured_board_id' => 10]);

        $this->assertSame(10, $header->boardId);
        $this->assertSame(ScopeHeaderSpelling::Configured, $header->boardSpelling);
    }

    /** An EMPTY window answers `board_id: null` and the header still resolves — the DL-302 regression. */
    public function test_an_unobserved_row_board_does_not_make_the_header_legacy(): void
    {
        $header = BoardToolsScopeHeader::read([
            'board_id' => null, 'board_observed' => false, 'configured_board_id' => 10, 'swimlane_id' => 4,
        ]);

        $this->assertSame(10, $header->boardId);
        $this->assertSame(ScopeHeaderSpelling::Configured, $header->boardSpelling);
    }

    /** The pre-DL-302 shape: `board_id` alone, and it IS the echo there. */
    public function test_a_responder_predating_the_rename_is_read_under_the_legacy_key(): void
    {
        $header = BoardToolsScopeHeader::read(['board_id' => 10, 'swimlane_id' => 4]);

        $this->assertSame(10, $header->boardId);
        $this->assertSame(4, $header->swimlaneId);
        $this->assertSame(ScopeHeaderSpelling::Legacy, $header->boardSpelling);
    }

    /**
     * A NULL-valued `configured_board_id` falls through, exactly as an absent one does
     * — `??` never distinguished them and this pins that it still does not. The
     * spelling has to follow the value: reporting `Configured` for a value taken from
     * `board_id` would certify a skewed responder as current.
     */
    public function test_a_null_configured_key_falls_through_to_the_legacy_key(): void
    {
        $header = BoardToolsScopeHeader::read(['configured_board_id' => null, 'board_id' => 10]);

        $this->assertSame(10, $header->boardId);
        $this->assertSame(ScopeHeaderSpelling::Legacy, $header->boardSpelling);
    }

    /** Neither key: no echo to compare, and the probe says so rather than guessing which side is old. */
    public function test_a_response_with_no_board_key_at_all_is_absent(): void
    {
        $header = BoardToolsScopeHeader::read(['cards_by_stage' => []]);

        $this->assertNull($header->boardId);
        $this->assertNull($header->swimlaneId);
        $this->assertSame(ScopeHeaderSpelling::Absent, $header->boardSpelling);
    }

    /**
     * A PRESENT-but-unreadable `configured_board_id` does NOT fall through to the row
     * reading: the responder named the header key, so the header is what it says, and
     * an unreadable one is a null board (→ a mismatch), never the observation
     * underneath it silently standing in.
     */
    public function test_an_unreadable_configured_key_does_not_fall_through(): void
    {
        $header = BoardToolsScopeHeader::read(['configured_board_id' => 'ten', 'board_id' => 20]);

        $this->assertNull($header->boardId);
        $this->assertSame(ScopeHeaderSpelling::Configured, $header->boardSpelling);
    }

    /** Numeric strings are what a JSON responder can legitimately send; a non-numeric lane is null. */
    public function test_numeric_strings_are_read_and_a_non_numeric_lane_is_null(): void
    {
        $this->assertSame(10, BoardToolsScopeHeader::read(['configured_board_id' => '10'])->boardId);
        $this->assertSame(4, BoardToolsScopeHeader::read(['swimlane_id' => '4'])->swimlaneId);
        $this->assertNull(BoardToolsScopeHeader::read(['swimlane_id' => 'four'])->swimlaneId);
    }

    /**
     * The three notes are distinct, and the LEGACY one states what was READ and offers
     * the version cause as the likely one — this string is the operator-facing
     * measurement the removal condition is read off, so an edit that empties it or drops
     * the card reference reds here rather than silently un-owning the fallback again.
     *
     * ⛔ It does not ASSERT the version cause. An absent `configured_board_id` is all the
     * read establishes, and DL-304 names two other live producers of that state (a relay,
     * a responder that emits the header conditionally) for which "upgrade the install" is
     * the wrong instruction.
     */
    public function test_the_legacy_note_states_the_reading_and_offers_the_version_cause_as_likely(): void
    {
        $legacy = ScopeHeaderSpelling::Legacy->note();

        $this->assertStringContainsString('Header spelling: LEGACY', $legacy);
        $this->assertStringContainsString('configured_board_id', $legacy);
        $this->assertStringContainsString('Likeliest cause', $legacy);
        $this->assertStringContainsString('relay', $legacy);
        $this->assertStringContainsString('card#7325', $legacy);
        $this->assertStringNotContainsString('Header spelling: LEGACY', ScopeHeaderSpelling::Configured->note());
        $this->assertStringNotContainsString('Header spelling: LEGACY', ScopeHeaderSpelling::Absent->note());
        $this->assertNotSame(ScopeHeaderSpelling::Configured->note(), ScopeHeaderSpelling::Absent->note());
    }

    /**
     * ⛔ THE CAUSE FOLLOWS THE SPELLING, AND THE UNIDENTIFIED BRANCH GETS NEITHER THE
     * CREDENTIAL CAUSE NOR THE CREDENTIAL REMEDIATION. A mismatch tail that names a
     * DIFFERENT agent has said the responder identified itself as someone else; on
     * {@see ScopeHeaderSpelling::Absent} nothing identified it at all, so that sentence
     * is a specific wrong cause and its remediation points at a healthy token.
     *
     * Both directions are pinned per branch: a fix that hard-wired either arm's text
     * would pass one half and fail the other.
     */
    public function test_the_mismatch_cause_follows_the_spelling(): void
    {
        $args = ['the presented bearer', 'look at the token path', 'look at the endpoint'];

        foreach ([ScopeHeaderSpelling::Configured, ScopeHeaderSpelling::Legacy] as $identified) {
            $cause = $identified->mismatchCause(...$args);

            $this->assertStringContainsString("resolved to a DIFFERENT agent's window", $cause);
            $this->assertStringContainsString('look at the token path', $cause);
            $this->assertStringNotContainsString('look at the endpoint', $cause);
        }

        $absent = ScopeHeaderSpelling::Absent->mismatchCause(...$args);

        $this->assertStringNotContainsString('DIFFERENT agent', $absent);
        $this->assertStringNotContainsString('look at the token path', $absent);
        $this->assertStringContainsString('look at the endpoint', $absent);
        $this->assertStringContainsString('the presented bearer', $absent);
    }
}
