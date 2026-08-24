<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;

/**
 * The card-side corroboration gate for an UNCORROBORATED title-only `card#` token
 * (card#5287 / DL-270, extended to the draft overlay by card#5953) — shared by the
 * move handler (KanbanMoveCardHandler) and the draft-overlay handler
 * (KanbanBlockReasonHandler) so both settle the identical residual identically.
 *
 * The classifier found the token in the PR TITLE with nothing agreeing in the head
 * branch — the only surface this install mints itself — so the PR's prose is the sole
 * claim that this PR is work on this card. A descriptive citation of ANOTHER card
 * ("… — supersedes card#5139") is lexically identical to that claim, and when the cited
 * card is on the SAME mapped board every other guard passes: the read succeeds, the
 * board matches, and somebody else's card is written to. So corroborate with evidence
 * the card ALREADY carries — no read either handler does not already make, which is
 * precisely why accept-if-the-card-tracks-no-other-PR was the approved residual answer
 * rather than refuse-every-title-only-token.
 *
 * Extracted rather than copied (canon #5): the emptiness test, the numeric-string
 * comparison and the fail-closed rules are subtle enough that a second implementation
 * would diverge, and the two consumers must never disagree about whether one card
 * corroborates one PR.
 */
final class CardTokenCorroboration
{
    /**
     * Must this write be refused? True only when the classifier flagged the token
     * uncorroborated AND the card already answers to a DIFFERENT PR — a card that
     * already tracks another PR is overwhelmingly the foreign card a title cited
     * descriptively, and that is the single case where taking the title's word for it
     * writes to somebody else's work. False when the card tracks NO PR yet (the normal
     * shape of a card this PR is legitimately the first to touch) or already tracks
     * THIS PR (a redelivery, or a later action on the same PR).
     *
     * "Tracks no PR" uses the same emptiness test as the move handler's add-if-missing
     * correlation-ref stamp, so the two can never disagree about whether a card carries
     * a PR ref. A numeric string compares equal to its int (kanban's payload and the
     * durable inbox JSON round-trip each produce either). Fail-closed on anything else:
     * an event with no PR number, a non-numeric `pr_number` on the card, or one that is
     * numeric but names no single pull request (`'1.5'`) corroborates nothing.
     *
     * @param  mixed  $uncorroborated  the target payload's `card_token_uncorroborated` flag
     * @param  array<string, mixed>  $card  the card as already read by getCard()
     * @param  mixed  $eventPr  this event's PR number, as the caller's payload spells it
     */
    public static function refuses(mixed $uncorroborated, array $card, mixed $eventPr): bool
    {
        if ($uncorroborated !== true) {
            return false;
        }

        $cardPr = self::cardPr($card);
        if (($cardPr ?? '') === '') {
            return false;   // tracks no PR → this PR may be its first
        }

        return ! self::tracksPr($cardPr, $eventPr);
    }

    /**
     * Does this card's stored `pr_number` name the SAME pull request as $eventPr? The one
     * definition of "same PR" the writeback has: a numeric string compares equal to its int
     * (kanban's payload and the durable inbox JSON round-trip each produce either), and a
     * value naming no pull request on either side compares equal to nothing — fail-closed,
     * so a caller can never read "unknown" as "same".
     *
     * WHICH pull request each side names is {@see BareRefNumber}'s answer, never a local
     * cast (DL-311). The comparison ran its own `(int)`, and the fail-closed claim above
     * was true only of NON-numeric values: `'1.5'` IS numeric, so it truncated to PR 1 —
     * and since {@see refuses} returns `! tracksPr(...)`, a false "same PR" here does not
     * refuse a write, it ALLOWS one. A card storing `'1.5'` corroborated an event for PR 1
     * and took the title's word for it, which is the one thing this gate exists to stop.
     *
     * Public because {@see refuses} is not the only consumer: the move handler's stamp
     * decides whether an offered `pr_number` is a REPLAY of the card's own PR (stay silent)
     * or a SECOND, dropped PR (record it), which is the same question asked for a different
     * purpose. A second copy of the comparison would let the two disagree about one card and
     * one PR.
     */
    public static function tracksPr(mixed $cardPr, mixed $eventPr): bool
    {
        return BareRefNumber::namesSame(
            ExternalReferenceNormalizer::SYSTEM_GITHUB_PR,
            $cardPr,
            $eventPr,
            new ExternalReferenceNormalizer,
        );
    }

    /**
     * The card's tracked `pr_number` as kanban returned it, or null when absent — the
     * boundary-safe read (a kanban card is a system boundary; `payload` may be
     * non-array). Untyped on purpose: the value is numeric-string OR int depending on
     * whether it came straight from kanban or back through the durable inbox, and
     * {@see refuses} owns which of those compare equal. Both refusal sites log it, so
     * it is public rather than a third copy of the same `is_array` dance.
     *
     * @param  array<string, mixed>  $card
     */
    public static function cardPr(array $card): mixed
    {
        $current = is_array($card['payload'] ?? null) ? $card['payload'] : [];

        return $current['pr_number'] ?? null;
    }
}
