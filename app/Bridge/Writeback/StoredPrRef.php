<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;

/**
 * What the card's STORED pull-request refs actually name, relative to this event (DL-429 r4) —
 * the one answer every surface that reports on them reads: the stamp's drop note, the
 * corroboration refusal's note and log line, and the pull-request comment.
 *
 * Until this existed each of them re-answered the question through its own boolean flags,
 * threaded from the handler in a fixed order, and three of them said "a different pull
 * request" of a card that carried only a bare `pr_number` — which, under DL-429 Decision 1,
 * names none. A card names a pull request only through a `pr_url` naming a real one — this
 * event's ({@see StoredPrUrlKind::NamesThisPr}) or another ({@see StoredPrUrlKind::NamesOtherPr}) —
 * and only the second is "a different pull request": every wording that asserts one reads that
 * case (DL-429 r5 split the two, after a card whose `pr_url` names THIS pull request was told it
 * stays correlated to "the pull request it already names" as though that were another).
 *
 * TWO AXES, NOT ONE ENUM, because the card's two refs vary independently and a surface can owe
 * a sentence about each: a foreign repo's `.../pull/0` placeholder beside an unconfirmed equal
 * number is one card, and the pull-request comment names both reasons. A single flattened enum
 * would have to pick one and silently drop the other.
 *
 * Classified with the writeback's existing primitives, never re-derived: {@see TrackedCardRef}
 * for whether the `pr_url` names a pull request, {@see PrUrlRef} for the placeholder's repo,
 * {@see BareRefNumber::canonical} for whether the `pr_number` is a pull-request number at all,
 * and {@see CardTokenCorroboration::tracksPr} for "same number". Emptiness is the stamp's own
 * test (`($value ?? '') === ''`), so a state here and an arm of the stamp can never disagree.
 */
final class StoredPrRef
{
    private function __construct(
        public readonly StoredPrUrlKind $url,
        public readonly StoredPrNumberKind $number,
        /** @see otherPrSameRepoWithMatchingNumber — meaningless (and false) off `NamesOtherPr`. */
        private readonly bool $otherPrSameRepo = false,
    ) {}

    /**
     * @param  array<string, mixed>  $card  the card as already read by getCard()
     * @param  string  $repo  the repo this event's pull request lives in
     * @param  mixed  $eventPr  this event's PR number, as the caller's payload spells it
     */
    public static function of(array $card, string $repo, mixed $eventPr): self
    {
        $refs = new ExternalReferenceNormalizer;
        $payload = is_array($card['payload'] ?? null) ? $card['payload'] : [];

        $storedUrl = $payload['pr_url'] ?? null;
        $parsedUrl = PrUrlRef::parse($storedUrl, $refs);
        $tracked = TrackedCardRef::fromPayload($payload, $refs);
        $url = match (true) {
            $tracked->namesPr($repo, $eventPr, $refs) => StoredPrUrlKind::NamesThisPr,
            $tracked->kind === TrackedRefKind::PrUrl => StoredPrUrlKind::NamesOtherPr,
            ($storedUrl ?? '') === '' => StoredPrUrlKind::None,
            $parsedUrl === null => StoredPrUrlKind::NotAPrUrl,
            $parsedUrl->canonRepo === $refs->canonicalizeSource($repo) => StoredPrUrlKind::PlaceholderThisRepo,
            default => StoredPrUrlKind::PlaceholderOtherRepo,
        };
        // `NamesOtherPr` covers a real pull request in ANY repo, not just this event's (DL-429
        // r9): a card correctly tracking `oldorg/repo#7` reads `NamesOtherPr` against an event
        // for `owner/repo#7` too, because it is not THIS (repo, number). Whether the other PR's
        // OWN repo is this event's is captured here, once, so `otherPrSameRepoWithMatchingNumber`
        // below and every mirror of it read one answer.
        $otherPrSameRepo = $url === StoredPrUrlKind::NamesOtherPr
            && $parsedUrl !== null
            && $parsedUrl->canonRepo === $refs->canonicalizeSource($repo);

        $storedPr = $payload['pr_number'] ?? null;
        $number = match (true) {
            ($storedPr ?? '') === '' => StoredPrNumberKind::None,
            BareRefNumber::canonical(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, $storedPr, $refs) === null => StoredPrNumberKind::NamesNoPr,
            CardTokenCorroboration::tracksPr($storedPr, $eventPr) => StoredPrNumberKind::SameNumber,
            default => StoredPrNumberKind::DifferentNumber,
        };

        return new self($url, $number, $otherPrSameRepo);
    }

    /** Does the card name a pull request at all — a `pr_url` naming a real one, this event's or another? */
    public function namesPr(): bool
    {
        return $this->url === StoredPrUrlKind::NamesThisPr || $this->url === StoredPrUrlKind::NamesOtherPr;
    }

    /**
     * The card's `pr_number` EQUALS this event's and no `pr_url` of the card's own names a real
     * pull request to confirm which repo it belongs to: the stamp drops such a match rather than
     * trusting it, and a refusal of such a card is reported as unconfirmed, not as "different".
     */
    public function numberUnconfirmed(): bool
    {
        return $this->number === StoredPrNumberKind::SameNumber && ! $this->namesPr();
    }

    /** The card's `pr_number` differs from this event's and nothing attributes it to a repo. */
    public function numberIsBare(): bool
    {
        return $this->number === StoredPrNumberKind::DifferentNumber && ! $this->namesPr();
    }

    /**
     * The card's `pr_url` names ANOTHER pull request of THIS EVENT'S OWN repo, and the card's
     * bare `pr_number` happens to equal this event's number too — the card's own two refs
     * disagree with EACH OTHER, not merely with this event, and kanban's by-ref derivation
     * (repo from `pr_url`, number from `pr_number`) can index the card under this very
     * `(repo, number)` even though nothing was written (DL-429 r9). FALSE for a card whose
     * `pr_url` names a same-numbered pull request of a DIFFERENT repo (a repo that moved to a
     * new GitHub org restarts its numbers, so `oldorg/repo#7` and `owner/repo#7` sharing a
     * number is coincidence, not disagreement) — the derived ref there uses the OTHER repo,
     * so under a REPO-QUALIFIED by-ref lookup, on a card with no explicit `payload.repo`
     * override, a lookup for this event never lands on the card, and the generic `NamesOtherPr`
     * heading's "not reachable" claim stays true of it in that scope (DL-429 r10 bound: an
     * explicit `payload.repo` naming this event's repo would make `ExternalReferenceNormalizer
     * ::sourceFor` prefer that override over the `pr_url`-derived repo, `bridge:check`'s
     * `source` field is unqualified on a 1:1 board mapping per DL-174, and this class does not
     * read `payload.repo` at all — neither is what this predicate answers).
     */
    public function otherPrSameRepoWithMatchingNumber(): bool
    {
        return $this->otherPrSameRepo && $this->number === StoredPrNumberKind::SameNumber;
    }
}
