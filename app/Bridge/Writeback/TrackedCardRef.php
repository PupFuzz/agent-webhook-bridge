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
 *      A `.../pull/0` placeholder (the source-only qualifier `kbcard --pr-url` stamps) is
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
        // (1) pr_url — repo + number. A `.../pull/0` placeholder falls through.
        $pu = $payload['pr_url'] ?? null;
        if (is_string($pu) && $pu !== '') {
            $repo = $refs->repoFromGitHubUrl($pu);
            if ($repo !== null && preg_match('#/pull/(\d+)#', $pu, $m) === 1 && (int) $m[1] > 0) {
                return new self(TrackedRefKind::PrUrl, canonRepo: $repo, prNumber: (int) $m[1], prUrl: $pu);
            }
        }

        // (2) pr_number — repo-unqualified; usable only on a 1:1 board.
        $pn = $payload['pr_number'] ?? null;
        if (is_numeric($pn) && (int) $pn > 0) {
            return $isShared
                ? new self(TrackedRefKind::Ambiguous, prNumber: (int) $pn)
                : new self(TrackedRefKind::PrNumber, prNumber: (int) $pn);
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
