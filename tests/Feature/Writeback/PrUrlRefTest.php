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
 * The corpus is PUBLISHED, not written here: both data providers read
 * `docs/pr-url-ref-parity-corpus.json`, the file the toolkit vendors for its own copy of the
 * rule (card#10736), so what the bridge tests and what it publishes are one file. This test
 * checks only the bridge's half; the corpus's `not_checked_by_this_repo` names the rest.
 */
class PrUrlRefTest extends TestCase
{
    private ExternalReferenceNormalizer $refs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refs = new ExternalReferenceNormalizer;
    }

    private const CORPUS = 'docs/pr-url-ref-parity-corpus.json';

    /**
     * The published vectors whose `expect` names a pull request.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function namesAPullRequest(): array
    {
        $rows = [];
        foreach (self::vectors() as $name => $v) {
            if ($v['expect'] !== null) {
                $rows[$name] = [$v['input'], $v['expect']['repo'], $v['expect']['number']];
            }
        }

        return $rows;
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
     * The published vectors whose `expect` is null: values naming no pull request.
     *
     * @return array<string, array{mixed}>
     */
    public static function namesNoPullRequest(): array
    {
        $rows = [];
        foreach (self::vectors() as $name => $v) {
            if ($v['expect'] === null) {
                $rows[$name] = [$v['input']];
            }
        }

        return $rows;
    }

    /**
     * The corpus's vectors keyed by their input, so a failing data set names the value.
     *
     * @return array<string, array{input: mixed, expect: array{repo: string, number: int, names_pr: bool}|null}>
     */
    private static function vectors(): array
    {
        $doc = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/'.self::CORPUS), true, flags: JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($doc['vectors'] as $v) {
            $rows[json_encode($v['input'], JSON_UNESCAPED_SLASHES)] = $v;
        }

        return $rows;
    }

    /**
     * `names_pr` is published beside the number, so it is held to `namesPr()` rather than
     * left as a claim only the far end reads; and the file is the one its own `corpus` key
     * names, with a vector on each side of the rule.
     */
    public function test_the_published_corpus_is_the_one_this_test_reads_and_its_names_pr_holds(): void
    {
        $doc = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/'.self::CORPUS), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(self::CORPUS, $doc['authority']['corpus']);
        $this->assertSame(PrUrlRef::class, $doc['authority']['class']);
        $this->assertSame(count($doc['vectors']), count(self::vectors()), 'two vectors share an input, so one of them is never run');
        $this->assertNotSame([], self::namesAPullRequest());
        $this->assertNotSame([], self::namesNoPullRequest());

        foreach (self::vectors() as $name => $v) {
            if ($v['expect'] !== null) {
                $this->assertSame($v['expect']['names_pr'], PrUrlRef::parse($v['input'], $this->refs)?->namesPr(), $name);
            }
        }
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
