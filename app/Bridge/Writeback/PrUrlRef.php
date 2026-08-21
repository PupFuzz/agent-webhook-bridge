<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;

/**
 * The single definition of "which pull request does this `pr_url` name" — the parse
 * ({@see ExternalReferenceNormalizer::repoFromGitHubUrl} for the repo, `/pull/<n>` for
 * the number) that {@see TrackedCardRef::fromPayload} resolves a card's tracked PR with,
 * and that the move handler's add-if-missing stamp compares an offered `pr_url` against.
 *
 * Extracted rather than copied (canon #5, and the same move `pr_number` already made onto
 * {@see CardTokenCorroboration::tracksPr}): a `pr_url` is the one correlation ref whose
 * value has MANY spellings for ONE pull request — the repo's case (GitHub's `owner/repo`
 * is case-insensitive, which is why `canonicalizeSource` lower-cases), a trailing
 * `/files` or `#discussion_r…`, the `.git` suffix — so comparing the BYTES calls one pull
 * request two and reports a second PR correlating to a card that has only ever had one
 * (card#7064). A second implementation of the parse would let the writeback's two readers
 * disagree about one card and one URL.
 *
 * The `.../pull/0` PLACEHOLDER is part of the identity question, not a caller's special
 * case: it is the source-only qualifier `bridge:check` tells operators to stamp so a
 * card on a shared board carries a derivable `source` (`WritebackSourceCoverageCheck`),
 * and it names no pull request at all. {@see number} is 0 for it and {@see namesPr} is
 * false, so neither consumer can read it as a PR: `TrackedCardRef` falls through to
 * `pr_number`, and the stamp treats the card as carrying no `pr_url` yet.
 */
final class PrUrlRef
{
    private function __construct(
        /** The URL exactly as it was stored/offered — the caller's record of it, unnormalized. */
        public readonly string $raw,
        /** Canonical (lower-cased) `owner/repo`. */
        public readonly string $canonRepo,
        /** The `/pull/<n>` number — 0 for the source-only placeholder. */
        public readonly int $number,
    ) {}

    /**
     * The `(repo, number)` this value names, or null when it is not a GitHub pull-request
     * URL at all (absent, non-string, an issue URL, an operator's free text). A null is
     * "this says nothing about a pull request" and must never be read as "no pull request
     * is tracked" — {@see namesPr} answers that.
     */
    public static function parse(mixed $url, ExternalReferenceNormalizer $refs): ?self
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $repo = $refs->repoFromGitHubUrl($url);
        if ($repo === null || preg_match('#/pull/(\d+)#', $url, $m) !== 1) {
            return null;
        }

        return new self($url, $repo, (int) $m[1]);
    }

    /** Does this name a real pull request (i.e. is it not the `.../pull/0` placeholder)? */
    public function namesPr(): bool
    {
        return $this->number > 0;
    }

    /** The source-only qualifier `.../pull/0`: a repo, deliberately no pull request. */
    public function isSourceOnlyPlaceholder(): bool
    {
        return $this->number === 0;
    }

    /** Do these two URLs name the SAME pull request? Null (unparseable) is same as nothing. */
    public function sameAs(?self $other): bool
    {
        return $other !== null && $this->canonRepo === $other->canonRepo && $this->number === $other->number;
    }
}
