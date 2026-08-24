<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\ExternalReferenceNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parity tests for the vendored normalizer (mirror of kanban-board's
 * App\Services\ExternalReferenceNormalizer). These pin the canonical forms the
 * kanban server uses so the bridge's client-side correlation/derivation agrees
 * with the server's by-ref canonicalization.
 */
class ExternalReferenceNormalizerTest extends TestCase
{
    private function n(): ExternalReferenceNormalizer
    {
        return new ExternalReferenceNormalizer;
    }

    public function test_numeric_ref_canonicalization_collapses_prefix_and_leading_zeros(): void
    {
        $n = $this->n();
        // DL-28 vs DL-028 vs 28 vs #28 all canonicalize to "28".
        $this->assertSame('28', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, 'DL-28'));
        $this->assertSame('28', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, 'DL-028'));
        $this->assertSame('28', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, '28'));
        $this->assertSame('28', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, '#28'));
        $this->assertSame('28', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, 28));
    }

    public function test_github_pr_ref_strips_non_digits_so_hash_85_equals_85(): void
    {
        $n = $this->n();
        // "hash-85" / "#85" / "85" all canonicalize to "85".
        $this->assertSame('85', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, 'hash-85'));
        $this->assertSame('85', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, '#85'));
        $this->assertSame('85', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, '85'));
        $this->assertSame('85', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, 85));
    }

    /**
     * The parity cases the kanban authority added in ITS DL-251 and this mirror had not
     * carried: a correlation value that is not a single decorated integer names no
     * identifier, so it derives NO ref rather than the concatenation of its digit runs —
     * which is a real, DIFFERENT pull request or decision.
     */
    #[DataProvider('severalDigitRunCases')]
    public function test_a_value_carrying_several_digit_runs_names_no_single_identifier(string $in): void
    {
        // Concatenating the runs is what minted a plausible-but-wrong ref: the date below
        // derived "20260823" here while the server derived nothing for the same card.
        $this->assertNull($this->n()->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, $in));
    }

    /** @return array<string, array{string}> */
    public static function severalDigitRunCases(): array
    {
        return [
            'decimal' => ['1.5'],
            'two counts in prose' => ['PR 12 of 34'],
            'version then number' => ['v1.2 #85'],
            'iso date' => ['2026-08-23'],
            'range' => ['12-34'],
        ];
    }

    public function test_a_non_integer_number_derives_no_ref_rather_than_a_different_real_one(): void
    {
        $this->assertNull(
            $this->n()->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, 1.5),
            'a non-integer value names no pull request, so it must derive no ref rather than PR #1'
        );
    }

    public function test_one_stored_value_derives_one_ref_regardless_of_json_type(): void
    {
        // What a card correlates to must be a property of the VALUE, not of the JSON type
        // it arrived as. The float half of this is reachable in the bridge as of DL-309:
        // TrackedCardRef reads `pr_number` straight off a decoded card payload, where a
        // number-typed field arrives as a PHP float.
        $n = $this->n();
        $this->assertSame(
            $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, '1.5'),
            $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, 1.5),
        );
    }

    public function test_an_integral_float_still_derives_its_integer_ref(): void
    {
        // Control, kept deliberately: the refusal is scoped to the non-integral case, not
        // to floats generally.
        $n = $this->n();
        $this->assertSame('85', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, 85.0));
        $this->assertSame('1', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, 1.0));
    }

    public function test_a_non_numeric_system_keeps_its_value_verbatim_and_is_not_truncated(): void
    {
        // The float branch sits BEFORE the system branch, so an unknown system's verbatim
        // contract survives a numeric value instead of truncating it.
        $n = $this->n();
        $this->assertSame('1.5', $n->canonicalize('jira', 1.5));
        $this->assertSame('PROJ-123', $n->canonicalize('jira', 'PROJ-123'));
    }

    public function test_a_float_too_large_to_be_an_integer_identifier_derives_no_ref(): void
    {
        $n = $this->n();
        $this->assertNull($n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, 1.0e20));
        $this->assertSame('1000000000000000', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, 1.0e15));
    }

    public function test_all_zero_numeric_ref_canonicalizes_to_single_zero(): void
    {
        $n = $this->n();
        $this->assertSame('0', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, 'DL-000'));
        $this->assertSame('0', $n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, '0'));
    }

    public function test_numeric_ref_with_no_digits_is_null(): void
    {
        $n = $this->n();
        $this->assertNull($n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, 'no-digits'));
        $this->assertNull($n->canonicalize(ExternalReferenceNormalizer::SYSTEM_DL, ''));
    }

    public function test_unknown_system_is_stored_verbatim_trimmed_and_capped(): void
    {
        $n = $this->n();
        $this->assertSame('Free-Form_Ref', $n->canonicalize('jira', '  Free-Form_Ref  '));
        $this->assertNull($n->canonicalize('jira', '   '));
        $this->assertSame(255, mb_strlen((string) $n->canonicalize('jira', str_repeat('x', 300))));
    }

    public function test_canonicalize_source_trims_lowercases_and_caps(): void
    {
        $n = $this->n();
        $this->assertSame('octo/web', $n->canonicalizeSource('  Octo/Web  '));
        $this->assertSame('octo/web', $n->canonicalizeSource('OCTO/WEB'));
        $this->assertNull($n->canonicalizeSource('   '));
        $this->assertSame(255, mb_strlen((string) $n->canonicalizeSource(str_repeat('a', 300))));
    }

    public function test_repo_from_github_url_parses_all_path_kinds_case_insensitively(): void
    {
        $n = $this->n();
        $this->assertSame('octo/web', $n->repoFromGitHubUrl('https://github.com/Octo/Web/pull/12'));
        $this->assertSame('octo/web', $n->repoFromGitHubUrl('https://github.com/Octo/Web/issues/5'));
        $this->assertSame('octo/web', $n->repoFromGitHubUrl('https://github.com/Octo/Web/commit/abc123'));
        $this->assertSame('octo/web', $n->repoFromGitHubUrl('https://github.com/Octo/Web/tree/main'));
        $this->assertSame('octo/web', $n->repoFromGitHubUrl('https://github.com/Octo/Web/blob/main/README.md'));
        // `.git` suffix is stripped (non-greedy capture).
        $this->assertSame('octo/web', $n->repoFromGitHubUrl('https://github.com/Octo/Web.git/pull/12'));
        // Not a parseable repo URL.
        $this->assertNull($n->repoFromGitHubUrl('https://github.com/Octo/Web'));
        $this->assertNull($n->repoFromGitHubUrl('https://example.com/foo/bar/pull/1'));
    }

    public function test_source_for_prefers_explicit_repo_then_url_keys_then_external_link(): void
    {
        $n = $this->n();
        // 1. explicit payload.repo (with separator) wins.
        $this->assertSame('octo/web', $n->sourceFor(['repo' => 'Octo/Web', 'pr_url' => 'https://github.com/Other/Repo/pull/1']));
        // a short adapter alias (no separator) does NOT win — falls through to URL keys.
        $this->assertSame('other/repo', $n->sourceFor(['repo' => 'DEV', 'pr_url' => 'https://github.com/Other/Repo/pull/1']));
        // 2. URL keys in preference order (pr_url > issue_url > html_url).
        $this->assertSame('a/b', $n->sourceFor(['issue_url' => 'https://github.com/A/B/issues/3', 'html_url' => 'https://github.com/C/D/pull/4']));
        // 3. top-level external_link fallback.
        $this->assertSame('e/f', $n->sourceFor([], 'https://github.com/E/F/pull/9'));
        // none → null.
        $this->assertNull($n->sourceFor([], null));
        $this->assertNull($n->sourceFor(['repo' => 'DEV'], null));
    }

    public function test_system_for_payload_key_maps_the_closed_set(): void
    {
        $n = $this->n();
        $this->assertSame(ExternalReferenceNormalizer::SYSTEM_DL, $n->systemForPayloadKey('dl_number'));
        $this->assertSame(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, $n->systemForPayloadKey('pr_number'));
        $this->assertNull($n->systemForPayloadKey('unrelated_key'));
    }
}
