<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;

/**
 * The single authority for "which (repo, PR) does a card's payload reference" — the
 * PR-reference precedence shared by bridge:reconcile (ReconcileCommand::resolveTracked)
 * and the DL-207 promote-on-release board scan (KanbanPromoteReleasedHandler). Extracted
 * so the two consumers can't diverge on it (canon #5): a card's stage is derived from the
 * SAME reference resolution whether the reconcile or the promote leg touched it last.
 *
 * Pure: no logging, no counters, no I/O — the caller maps {@see TrackedRefKind} onto its
 * own output (reconcile emits a skip line + increments its counter; the handler logs +
 * no-ops). Precedence (most-authoritative first), mirroring resolveTracked:
 *   1. `pr_url` — repo-qualified, yields BOTH repo + number ⇒ {@see TrackedRefKind::PrUrl}.
 *      Parsed by {@see PrUrlRef}, the shared "which PR does this URL name" primitive. A
 *      `.../pull/0` placeholder (the source-only qualifier `kbcard --pr-url` stamps) is
 *      NOT a real PR: it falls through to `pr_number`.
 *   2. bare `pr_number` — needs the repo. Unambiguous only on a 1:1 board
 *      ({@see TrackedRefKind::PrNumber}); on a board SHARED by >1 repo mapping the number
 *      can't be attributed to a repo ({@see TrackedRefKind::Ambiguous}).
 *   3. `dl_number` with no PR reference ({@see TrackedRefKind::DlOnly}) — DL→PR resolution
 *      is out of the writeback's PR-driven scope (a documented boundary of BOTH consumers).
 *   4. otherwise ({@see TrackedRefKind::None}) — not a tracked card.
 */
final class TrackedCardRef
{
    public function __construct(
        public readonly TrackedRefKind $kind,
        /** Canonical `owner/repo` — set for {@see TrackedRefKind::PrUrl} only. */
        public readonly ?string $canonRepo = null,
        /** The PR number — set for {@see TrackedRefKind::PrUrl} and {@see TrackedRefKind::PrNumber}. */
        public readonly ?int $prNumber = null,
        /** The raw pr_url — set for {@see TrackedRefKind::PrUrl} only. */
        public readonly ?string $prUrl = null,
        /** The dl_number string — set for {@see TrackedRefKind::DlOnly} only (for the caller's log). */
        public readonly ?string $dl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the card's payload
     * @param  bool  $isShared  whether the card's board is mapped by >1 repo (WritebackConfig::boardIsShared)
     */
    public static function fromPayload(array $payload, bool $isShared, ExternalReferenceNormalizer $refs): self
    {
        // (1) pr_url — repo + number. A `.../pull/0` placeholder names no PR, so it falls through.
        $pu = $payload['pr_url'] ?? null;
        $url = PrUrlRef::parse($pu, $refs);
        if ($url !== null && $url->namesPr()) {
            return new self(TrackedRefKind::PrUrl, canonRepo: $url->canonRepo, prNumber: $url->number, prUrl: $url->raw);
        }

        // (2) pr_number — repo-unqualified; usable only on a 1:1 board.
        //
        // WHICH pull request the value names is the normalizer's answer, never a
        // local cast (DL-309): a bare `(int)` truncates, so `1.5` named PR 1 while
        // the kanban server — which derives the card's `github_pr` ref from this
        // same key — derives NO ref at all since its DL-251. One card, one stored
        // value, two authorities, two answers, and PR 1 is a real, unrelated pull
        // request the reconcile would then read and move the card from.
        //
        // The ADMISSION test does not move: a POSITIVE BARE number, exactly as
        // before (`> 0` on the raw value, so a negative keeps naming no PR rather
        // than acquiring the ref its digits canonicalize to; a decorated `#85`,
        // which the server does index, has never been tracked here and is not
        // widened in either). The only behavior change is the refusal of an
        // admitted number that names no single integer.
        $pn = $payload['pr_number'] ?? null;
        $ref = is_numeric($pn) && (float) $pn > 0
            ? $refs->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, $pn)
            : null;
        if ($ref !== null) {
            return $isShared
                ? new self(TrackedRefKind::Ambiguous, prNumber: (int) $ref)
                : new self(TrackedRefKind::PrNumber, prNumber: (int) $ref);
        }

        // (3) dl_number only — no PR reference.
        $dl = $payload['dl_number'] ?? null;
        if (is_scalar($dl) && (string) $dl !== '') {
            return new self(TrackedRefKind::DlOnly, dl: (string) $dl);
        }

        // (4) not a tracked card.
        return new self(TrackedRefKind::None);
    }
}
