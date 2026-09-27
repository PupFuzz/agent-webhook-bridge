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
 * names none. A card names a pull request only through a `pr_url` naming a real one
 * ({@see StoredPrUrlKind::NamesPr}); every wording that asserts a pull request reads that case.
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
        $url = match (true) {
            TrackedCardRef::fromPayload($payload, $refs)->kind === TrackedRefKind::PrUrl => StoredPrUrlKind::NamesPr,
            ($storedUrl ?? '') === '' => StoredPrUrlKind::None,
            $parsedUrl === null => StoredPrUrlKind::NotAPrUrl,
            $parsedUrl->canonRepo === $refs->canonicalizeSource($repo) => StoredPrUrlKind::PlaceholderThisRepo,
            default => StoredPrUrlKind::PlaceholderOtherRepo,
        };

        $storedPr = $payload['pr_number'] ?? null;
        $number = match (true) {
            ($storedPr ?? '') === '' => StoredPrNumberKind::None,
            BareRefNumber::canonical(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, $storedPr, $refs) === null => StoredPrNumberKind::NamesNoPr,
            CardTokenCorroboration::tracksPr($storedPr, $eventPr) => StoredPrNumberKind::SameNumber,
            default => StoredPrNumberKind::DifferentNumber,
        };

        return new self($url, $number);
    }

    /** Does the card name a pull request at all — a `pr_url` naming a real one, of any repo? */
    public function namesPr(): bool
    {
        return $this->url === StoredPrUrlKind::NamesPr;
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
}
