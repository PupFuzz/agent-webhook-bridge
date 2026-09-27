<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;

/**
 * The single authority for "which (repo, PR) does a card's payload reference" — the
 * PR-reference precedence shared by every consumer that asks it (bridge:reconcile, the
 * DL-207 promote-on-release scan, the DL-270 corroboration gate, the dependabot handler's
 * repo attribution and the move handler's correlation stamp among them —
 * `grep -rn 'TrackedCardRef::' app/` prints the population). Extracted so the consumers
 * can't diverge on it (canon #5): a card's PR is derived from the SAME reference resolution
 * whichever leg touched it last.
 *
 * ⛔ A PULL REQUEST IS `(repo, number)`, NEVER A NUMBER (DL-429). PR numbers are per-repo
 * counters, and a repo moved to a new org is a fresh history whose numbers restart at 1 — so
 * the new repo's #N and the old repo's #N are two pull requests on the SAME board. A card
 * therefore names a pull request only through a `pr_url` that carries its repo; a bare
 * `pr_number` names a number, and is attributed to no repo on any board. It used to be
 * attributed to a 1:1 board's sole mapping, which is exactly the premise an org move
 * breaks: the sole mapping becomes the new repo, and every old bare-number card silently
 * points at an unrelated new pull request.
 *
 * Pure: no logging, no counters, no I/O — the caller maps {@see TrackedRefKind} onto its
 * own output (reconcile emits a skip line + increments its counter; the handler logs +
 * no-ops). Precedence (most-authoritative first):
 *   1. `pr_url` — repo-qualified, yields BOTH repo + number ⇒ {@see TrackedRefKind::PrUrl}.
 *      Parsed by {@see PrUrlRef}, the shared "which PR does this URL name" primitive. A
 *      `.../pull/0` placeholder (the source-only qualifier `kbcard --pr-url` stamps) is
 *      NOT a real PR: it falls through to `pr_number` — and does NOT qualify it (card#9850:
 *      until DL-429 the stamp wrote `pr_number` add-if-missing while keeping a FOREIGN-repo
 *      placeholder, cards stamped then still carry that shape, and `kbcard --pr` can still
 *      write a bare number, so the placeholder's repo is not evidence of the number's).
 *   2. bare `pr_number` ⇒ {@see TrackedRefKind::BarePrNumber}: the number is recorded for the
 *      caller's report, and no consumer may read it as a pull request.
 *   3. `dl_number` with no PR reference ({@see TrackedRefKind::DlOnly}) — DL→PR resolution
 *      is out of the writeback's PR-driven scope (a documented boundary of BOTH consumers).
 *   4. otherwise ({@see TrackedRefKind::None}) — not a tracked card.
 */
final class TrackedCardRef
{
    public function __construct(
        public readonly TrackedRefKind $kind,
        /** Canonical (lower-cased) `owner/repo` — set for {@see TrackedRefKind::PrUrl} only. */
        public readonly ?string $canonRepo = null,
        /** The PR number — set for {@see TrackedRefKind::PrUrl} and {@see TrackedRefKind::BarePrNumber}. */
        public readonly ?int $prNumber = null,
        /** The raw pr_url — set for {@see TrackedRefKind::PrUrl} only. */
        public readonly ?string $prUrl = null,
        /** The dl_number string — set for {@see TrackedRefKind::DlOnly} only (for the caller's log). */
        public readonly ?string $dl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the card's payload
     */
    public static function fromPayload(array $payload, ExternalReferenceNormalizer $refs): self
    {
        // (1) pr_url — repo + number. A `.../pull/0` placeholder names no PR, so it falls through.
        $pu = $payload['pr_url'] ?? null;
        $url = PrUrlRef::parse($pu, $refs);
        if ($url !== null && $url->namesPr()) {
            return new self(TrackedRefKind::PrUrl, canonRepo: $url->canonRepo, prNumber: $url->number, prUrl: $url->raw);
        }

        // (2) pr_number — repo-unqualified, so it names no pull request on any board (DL-429).
        //
        // WHICH pull request the value names is the normalizer's answer, never a
        // local cast (DL-309): a bare `(int)` truncates, so `1.5` named PR 1 while
        // the kanban server — which derives the card's `github_pr` ref from this
        // same key — derives NO ref at all since its DL-251. One card, one stored
        // value, two authorities, two answers, and PR 1 is a real, unrelated pull
        // request the reconcile would then read and move the card from.
        //
        // Both halves — the POSITIVE-BARE-number admission and the derivation —
        // now live on {@see BareRefNumber}, shared with the corroboration gate and
        // both scan correlations (DL-311). The admission is unchanged from the
        // inline form this replaced, and that is the point of hoisting it rather
        // than re-spelling it: it is what keeps `-5` and `'#85'` naming no PR here,
        // though the server indexes the refs they canonicalize to.
        $ref = BareRefNumber::canonical(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, $payload['pr_number'] ?? null, $refs);
        if ($ref !== null) {
            return new self(TrackedRefKind::BarePrNumber, prNumber: (int) $ref);
        }

        // (3) dl_number only — no PR reference.
        $dl = $payload['dl_number'] ?? null;
        if (is_scalar($dl) && (string) $dl !== '') {
            return new self(TrackedRefKind::DlOnly, dl: (string) $dl);
        }

        // (4) not a tracked card.
        return new self(TrackedRefKind::None);
    }

    /**
     * Does this card name pull request `$eventPr` of `$repo`? True only for a `pr_url` naming
     * that repo (compared canonically — GitHub `owner/repo` is case-insensitive) and that
     * number. A bare `pr_number` names no repo, so it names no pull request here (DL-429).
     * Fail-closed: an `$eventPr` naming no single number is the same as nothing.
     */
    public function namesPr(string $repo, mixed $eventPr, ExternalReferenceNormalizer $refs): bool
    {
        return $this->kind === TrackedRefKind::PrUrl
            && $this->canonRepo === $refs->canonicalizeSource($repo)
            && BareRefNumber::namesSame(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, $this->prNumber, $eventPr, $refs);
    }
}
