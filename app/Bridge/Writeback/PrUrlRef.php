<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;

/**
 * The single definition of "which pull request does this `pr_url` name" — the parse that
 * {@see TrackedCardRef::fromPayload} resolves a card's tracked PR with, and that the move
 * handler's add-if-missing stamp compares an offered `pr_url` against.
 *
 * ⛔ THE REPO AND THE NUMBER COME FROM ONE MATCH (card#10735): the value's FIRST GitHub URL
 * ({@see PATTERN}) — the same match {@see ExternalReferenceNormalizer::repoFromGitHubUrl}
 * derives the card's by-ref `source` from — names a pull request only when its own segment
 * is `pull` followed by digits. The
 * number used to be the first `/pull/<digits>` ANYWHERE in the value, so
 * `…/a/x/issues/5 …/b/y/pull/179` read as `a/x#179`, a pull request neither URL names, and
 * `…/o/r/tree/main/pull/179` as `o/r#179`. The toolkit's `KB_JQ_PR_URL_REF` mirrors this
 * rule (card#10736).
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
    /**
     * The first GitHub web URL in a value, matched case-insensitively and unanchored: group 1
     * is `owner/repo` (a trailing `.git` trimmed), group 2 the segment as spelled, group 3 the
     * digit run right after the segment, possibly empty.
     *
     * ⚠ Up to group 2 this is `ExternalReferenceNormalizer::repoFromGitHubUrl`'s pattern with
     * the segment captured, so both select the same first match; group 3 only reads further
     * along it. That class mirrors kanban-board's rule and is held to it by
     * `docs/external-reference-parity-corpus.json`, so the pattern is not hoisted onto it.
     * `PrUrlRefTest` holds the two to the same match over its corpus.
     */
    public const PATTERN = '#github\.com/([^/]+/[^/]+?)(?:\.git)?/(pull|issues|commit|tree|blob)/(\d*)#i';

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

        // ONE match gives both halves (card#10735): {@see PATTERN} selects the same first match
        // `repoFromGitHubUrl` takes the repo from. The segment is compared case-sensitively, as
        // the number always was: `.../PULL/179` names no pull request.
        $repo = $refs->repoFromGitHubUrl($url);
        if ($repo === null || preg_match(self::PATTERN, $url, $m) !== 1 || $m[2] !== 'pull' || $m[3] === '') {
            return null;
        }

        return new self($url, $repo, (int) $m[3]);
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
