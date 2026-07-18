<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\PathHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathHelperSanitizeSegmentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function segments(): iterable
    {
        yield 'alnum preserved' => ['abc123', 'abc123'];
        yield 'case preserved' => ['AbC', 'AbC'];
        yield 'allowed dash/underscore preserved' => ['a-b_c', 'a-b_c'];
        yield 'slash sanitized (no dir escape)' => ['org/repo#1', 'org_repo_1'];
        // Stricter than the pre-consolidation charset: dots are stripped, not kept.
        yield 'dot stripped' => ['foo.bar', 'foo_bar'];
        yield 'version dots stripped' => ['v1.2.3', 'v1_2_3'];
        // Traversal neutralized: a bare '..' can never survive as a path component.
        yield 'dotdot neutralized' => ['..', '_'];
        yield 'dotdot in context' => ['a..b', 'a_b'];
        yield 'leading/trailing dots' => ['.foo.', '_foo_'];
        // Runs of invalid chars collapse to ONE underscore (was N underscores).
        yield 'run of slashes collapses' => ['a///b', 'a_b'];
        yield 'mixed invalid run collapses' => ['a @#b', 'a_b'];
        yield 'all-invalid non-empty collapses to single underscore' => ['@#$', '_'];
    }

    #[DataProvider('segments')]
    public function test_sanitizes_to_a_filesystem_safe_segment(string $input, string $expected): void
    {
        $this->assertSame($expected, PathHelper::sanitizeSegment($input));
    }

    public function test_empty_input_uses_the_default_fallback(): void
    {
        $this->assertNotSame('', PathHelper::sanitizeSegment(''));
    }

    public function test_empty_input_uses_a_caller_supplied_fallback(): void
    {
        $this->assertSame('agent', PathHelper::sanitizeSegment('', 'agent'));
    }

    public function test_a_non_empty_input_never_hits_the_fallback(): void
    {
        // Only a genuinely empty result falls back; an all-invalid input collapses
        // to a single underscore, which is non-empty.
        $this->assertSame('_', PathHelper::sanitizeSegment('///', 'agent'));
    }

    public function test_result_is_never_a_traversal_component(): void
    {
        foreach (['.', '..', '../..', '%2e%2e', './'] as $hostile) {
            $out = PathHelper::sanitizeSegment($hostile);
            $this->assertNotSame('.', $out);
            $this->assertNotSame('..', $out);
            $this->assertStringNotContainsString('/', $out);
        }
    }
}
