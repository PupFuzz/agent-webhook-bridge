<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Writeback\PrUrlRef;
use App\Bridge\Writeback\TrackedCardRef;
use App\Bridge\Writeback\TrackedRefKind;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PrUrlRef — which pull request a `pr_url` names (card#10735). The repo and the number come
 * from ONE match: the first GitHub URL in the value, the one
 * {@see ExternalReferenceNormalizer::repoFromGitHubUrl} reads the repo from. It names a pull
 * request only when that URL's own segment is `pull` followed by digits.
 *
 * The toolkit's `KB_JQ_PR_URL_REF` mirrors this rule (card#10736); these two corpora are the
 * ones its side mirrors, so an edit to either list is an edit to both.
 */
class PrUrlRefTest extends TestCase
{
    private ExternalReferenceNormalizer $refs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refs = new ExternalReferenceNormalizer;
    }

    /**
     * The spellings of one pull request that are accepted, before and after card#10735.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function namesAPullRequest(): array
    {
        return [
            'plain' => ['https://github.com/owner/repo/pull/179', 'owner/repo', 179],
            'owner and repo case' => ['https://github.com/Owner/Repo/pull/179', 'owner/repo', 179],
            'host case' => ['https://GitHub.com/owner/repo/pull/179', 'owner/repo', 179],
            '.git' => ['https://github.com/owner/repo.git/pull/179', 'owner/repo', 179],
            '.GIT' => ['https://github.com/owner/repo.GIT/pull/179', 'owner/repo', 179],
            'trailing /files' => ['https://github.com/owner/repo/pull/179/files', 'owner/repo', 179],
            'trailing /commits/<sha>' => ['https://github.com/owner/repo/pull/179/commits/abc123', 'owner/repo', 179],
            'trailing #discussion_r' => ['https://github.com/owner/repo/pull/179#discussion_r123456', 'owner/repo', 179],
            'www.' => ['https://www.github.com/owner/repo/pull/179', 'owner/repo', 179],
            'http' => ['http://github.com/owner/repo/pull/179', 'owner/repo', 179],
            'leading zero' => ['https://github.com/owner/repo/pull/0179', 'owner/repo', 179],
            'text before the URL' => ['PR: https://github.com/owner/repo/pull/179', 'owner/repo', 179],
            'a second pull URL after the first' => ['https://github.com/a/x/pull/5 https://github.com/b/y/pull/179', 'a/x', 5],
            'placeholder' => ['https://github.com/owner/repo/pull/0', 'owner/repo', 0],
            // Classifies differently from before card#10735: the number used to be the first
            // `/pull/<digits>` anywhere, here the non-GitHub URL's 7.
            'a non-GitHub /pull/ before the GitHub URL' => ['https://example.com/pull/7 https://github.com/o/r/pull/9', 'o/r', 9],
            'a non-GitHub owner/repo/pull/ before the GitHub URL' => ['https://example.com/x/pull/9 https://github.com/acme/widget/pull/179', 'acme/widget', 179],
        ];
    }

    #[DataProvider('namesAPullRequest')]
    public function test_a_pull_request_url_names_its_repo_and_number(string $url, string $repo, int $number): void
    {
        $ref = PrUrlRef::parse($url, $this->refs);

        $this->assertNotNull($ref);
        $this->assertSame([$repo, $number, $url], [$ref->canonRepo, $ref->number, $ref->raw]);
        $this->assertSame($number > 0, $ref->namesPr());
    }

    /**
     * Values naming no pull request. The first group classified differently before
     * card#10735, when the repo came from the first GitHub URL and the number from the first
     * `/pull/<digits>` anywhere in the value, so the two could come from different URLs.
     *
     * @return array<string, array{mixed}>
     */
    public static function namesNoPullRequest(): array
    {
        return [
            // Before card#10735 each of these named a pull request.
            'issue URL then another repo\'s pull URL' => ['https://github.com/a/x/issues/5 https://github.com/b/y/pull/179'],
            '/tree/main/pull/179' => ['https://github.com/o/r/tree/main/pull/179'],
            '/blob/main/pull/179' => ['https://github.com/o/r/blob/main/pull/179'],
            '/commit/<sha>/pull/179' => ['https://github.com/o/r/commit/abc123/pull/179'],
            'numberless pull URL then a numbered one' => ['https://github.com/o/r/pull/ https://github.com/o/r/pull/5'],
            'upper-case PULL URL then a lower-case one' => ['https://github.com/o/r/PULL/1 https://github.com/o/r/pull/5'],
            // These named none before either.
            'issue URL' => ['https://github.com/o/r/issues/179'],
            'upper-case PULL' => ['https://github.com/o/r/PULL/179'],
            'pull with no digits' => ['https://github.com/o/r/pull/abc'],
            'bare repo URL' => ['https://github.com/o/r'],
            'not GitHub' => ['https://gitlab.com/o/r/pull/179'],
            'free text' => ['see the linked PR'],
            'empty' => [''],
            'null' => [null],
            'a number' => [179],
        ];
    }

    #[DataProvider('namesNoPullRequest')]
    public function test_a_value_whose_first_github_url_is_not_a_pull_url_names_no_pull_request(mixed $url): void
    {
        $this->assertNull(PrUrlRef::parse($url, $this->refs));
    }

    /**
     * `PrUrlRef::PATTERN` must select the match `repoFromGitHubUrl` takes the repo from, or the
     * repo and the number come from two URLs again. The normalizer mirrors kanban's rule, so
     * its pattern is not shared; this holds the two copies to one match over both corpora.
     */
    public function test_the_pattern_selects_the_same_first_match_as_repo_from_github_url(): void
    {
        $checked = 0;
        foreach ([...self::namesAPullRequest(), ...self::namesNoPullRequest()] as $name => [$url]) {
            if (! is_string($url) || ($repo = $this->refs->repoFromGitHubUrl($url)) === null) {
                continue;
            }
            $this->assertSame(1, preg_match(PrUrlRef::PATTERN, $url, $m), $name);
            $this->assertSame($repo, $this->refs->canonicalizeSource($m[1]), $name);
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }

    /**
     * The measured case on the card, end to end through the card-level reader. The value used
     * to read as `a/x#179`, a pull request neither URL names, so the card was reconciled and
     * promoted by it. Now the `pr_url` names no pull request, so the card's `pr_number` is a
     * bare number, and every consumer skips it as one.
     */
    public function test_a_card_whose_pr_url_mixes_two_urls_tracks_no_pull_request(): void
    {
        $ref = TrackedCardRef::fromPayload(
            ['pr_url' => 'https://github.com/a/x/issues/5 https://github.com/b/y/pull/179', 'pr_number' => 179],
            $this->refs,
        );

        $this->assertSame(TrackedRefKind::BarePrNumber, $ref->kind);
        $this->assertNull($ref->canonRepo);
        $this->assertFalse($ref->namesPr('a/x', 179, $this->refs));
    }

    /**
     * The card's by-ref `source` is not changed by card#10735: it is still the first GitHub
     * URL's repo, which is also the repo PrUrlRef now reads the number beside.
     */
    public function test_the_source_of_a_two_url_value_is_still_its_first_urls_repo(): void
    {
        $this->assertSame('a/x', $this->refs->repoFromGitHubUrl('https://github.com/a/x/issues/5 https://github.com/b/y/pull/179'));
        $this->assertSame('a/x', $this->refs->sourceFor(['pr_url' => 'https://github.com/a/x/issues/5 https://github.com/b/y/pull/179']));
    }
}
