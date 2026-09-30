<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\BoardCallRefusal;
use App\Bridge\Tools\BoardReadRoute;
use Tests\TestCase;

/**
 * {@see BoardCallRefusal::readCause()} — the per-route-per-status CAUSE a named read refusal
 * states, pinned combination by combination. What a cause claims is a live operator instruction
 * ("go look at membership", "go look at the trash"), so a false claim here sends an operator to
 * audit the wrong thing by name — worse than the retryable 502 the named refusal replaces.
 *
 * `MembershipStatus` (`status.json`, the membership control's own read — card#10856 review) is
 * the case this file exists to pin: it shares `BoardScoped`'s board-itself authorization, but NOT
 * its 404 claim, because `status.json` alone resolves a TRASHED board (`->withTrashed()`) — so a
 * trashed-board 404 cause, true for `preload.json` / `by-ref.json`, is FALSE for `status.json`.
 */
class BoardCallRefusalReadCauseTest extends TestCase
{
    public function test_401_is_route_independent_and_names_a_bad_token(): void
    {
        foreach (BoardReadRoute::cases() as $route) {
            $cause = BoardCallRefusal::readCause($route, 401);
            $this->assertStringContainsString('not accepted at all', $cause, $route->name);
            $this->assertStringContainsString('revoked, rotated or replaced', $cause, $route->name);
        }
    }

    public function test_search_403_names_abilities_and_says_membership_cannot_produce_it(): void
    {
        $cause = BoardCallRefusal::readCause(BoardReadRoute::Search, 403);

        $this->assertStringContainsString('lacks `read`', $cause);
        $this->assertStringContainsString('does NOT produce a 403', $cause);
    }

    public function test_search_404_names_the_api_surface_never_the_card(): void
    {
        $cause = BoardCallRefusal::readCause(BoardReadRoute::Search, 404);

        $this->assertStringContainsString('API-surface fault', $cause);
        $this->assertStringNotContainsString('trash', $cause);
    }

    public function test_board_scoped_403_names_both_gates(): void
    {
        $cause = BoardCallRefusal::readCause(BoardReadRoute::BoardScoped, 403);

        $this->assertStringContainsString('abilities', $cause);
        $this->assertStringContainsString('membership', $cause);
        $this->assertStringContainsString('TWO independent gates', $cause);
    }

    /** `preload.json` / `by-ref.json` do NOT resolve a trashed board, so trash is a live candidate. */
    public function test_board_scoped_404_does_claim_the_trash(): void
    {
        $cause = BoardCallRefusal::readCause(BoardReadRoute::BoardScoped, 404);

        $this->assertStringContainsString('trash', $cause);
    }

    /**
     * ⭐ THE FIX THIS FILE PINS (card#10856 review). `status.json` DOES resolve a trashed board
     * (its owner reads `data.status: "trashed"`, 200), so its 404 means no board carries the id
     * at all — trash is never a candidate cause here, unlike {@see BoardScoped} above.
     */
    public function test_membership_status_404_does_not_claim_the_trash(): void
    {
        $cause = BoardCallRefusal::readCause(BoardReadRoute::MembershipStatus, 404);

        $this->assertStringNotContainsString('trash', $cause);
        $this->assertStringContainsString('does not resolve to any board', $cause);
    }

    /**
     * Unreachable through any tool — `KanbanClient::boardReadable` consumes the control's own 403
     * itself and never lets it throw — but the match arm exists for exhaustiveness and is pinned
     * here as the only place that can see it.
     */
    public function test_membership_status_403_names_both_gates_too(): void
    {
        $cause = BoardCallRefusal::readCause(BoardReadRoute::MembershipStatus, 403);

        $this->assertStringContainsString('abilities', $cause);
        $this->assertStringContainsString('membership', $cause);
    }
}
