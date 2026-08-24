<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Writeback\BareRefNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BareRefNumber — the one predicate answering "which pull request / issue does this bare
 * correlation number name" (DL-311), shared by TrackedCardRef, the corroboration gate and
 * both KanbanClient scan correlations. These pin BOTH halves separately, because they are
 * what the four sites were hoisted out of and mixing them is the mistake DL-309 avoided:
 * the ADMISSION decides which raw values count as a bare number at all, the DERIVATION
 * decides which identifier an admitted one names.
 */
class BareRefNumberTest extends TestCase
{
    private ExternalReferenceNormalizer $refs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refs = new ExternalReferenceNormalizer;
    }

    private function canon(mixed $value, string $system = ExternalReferenceNormalizer::SYSTEM_GITHUB_PR): ?string
    {
        return BareRefNumber::canonical($system, $value, $this->refs);
    }

    /**
     * DERIVATION — the defect this class exists to end: these values are all NUMERIC, so
     * every `is_numeric() && (int)` site admitted them and then named a real, unrelated
     * pull request. The kanban server derives no ref from any of them (its DL-251).
     */
    #[DataProvider('namesNoSinglePullRequest')]
    public function test_a_numeric_value_naming_no_single_integer_names_no_pull_request(mixed $value): void
    {
        $this->assertTrue(is_numeric($value), 'the point of this case is that the OLD admission let it through');
        $this->assertNull($this->canon($value));
    }

    public static function namesNoSinglePullRequest(): array
    {
        return [
            'decimal string' => ['1.5'],        // (int) → 1
            'decimal float' => [1.5],           // (int) → 1
            'exponent string' => ['1e3'],       // (int) → 1000
            'fraction under one' => [0.5],      // (int) → 0
        ];
    }

    /** DERIVATION — a value carrying no digits at all was already refused, and still is. */
    public function test_a_non_numeric_value_names_no_pull_request(): void
    {
        $this->assertNull($this->canon('TBD'));
        $this->assertNull($this->canon('2026-08-23'));
        $this->assertNull($this->canon('PR 12 of 34'));
        $this->assertNull($this->canon(null));
        $this->assertNull($this->canon(['oops']));
        $this->assertNull($this->canon(''));
    }

    /**
     * ADMISSION — deliberately NOT widened to everything the normalizer canonicalizes.
     * `-5` and `'#85'` canonicalize to `"5"` / `"85"`, refs the kanban server really does
     * index; admitting them here would start correlating cards off values nobody meant as
     * a PR number. (Drop the `> 0` term ⇒ the first two go green as "5"/"85" ⇒ RED.)
     */
    public function test_admission_is_a_positive_bare_number_and_nothing_wider(): void
    {
        $this->assertNull($this->canon(-5));
        $this->assertNull($this->canon('-5'));
        $this->assertNull($this->canon(0));
        $this->assertNull($this->canon('#85'));     // decorated: not numeric, so not admitted
        $this->assertNull($this->canon('PR-85'));
    }

    /**
     * CONTROLS — the spellings one pull request legitimately arrives in must be
     * BYTE-IDENTICAL to what the old `(int)` cast answered. A refusal that also refused
     * these would be a narrowing nobody approved, not a fix.
     */
    public function test_the_legitimate_spellings_of_one_pull_request_all_name_it(): void
    {
        foreach ([85, '85', '085', 85.0, ' 85 '] as $value) {
            $this->assertSame('85', $this->canon($value), 'spelling: '.var_export($value, true));
        }
    }

    public function test_names_same_is_fail_closed_on_both_sides(): void
    {
        $same = fn (mixed $a, mixed $b) => BareRefNumber::namesSame(ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, $a, $b, $this->refs);

        $this->assertTrue($same('085', 85));
        $this->assertTrue($same(85.0, '85'));
        $this->assertFalse($same('1.5', 1));      // the class defect: `(int)` said TRUE
        $this->assertFalse($same('1.5', '1.5'));  // two values naming nothing are not "the same PR"
        $this->assertFalse($same(null, null));
        $this->assertFalse($same('TBD', 'TBD'));
        $this->assertFalse($same(85, 86));
    }

    /** The issue key is the same question over another system — one predicate, not two. */
    public function test_the_github_issue_system_answers_identically(): void
    {
        $this->assertNull($this->canon('1.5', ExternalReferenceNormalizer::SYSTEM_GITHUB_ISSUE));
        $this->assertSame('85', $this->canon('085', ExternalReferenceNormalizer::SYSTEM_GITHUB_ISSUE));
    }
}
