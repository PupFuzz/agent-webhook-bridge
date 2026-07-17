<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\BridgePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * sanitizeAgent already had the stricter (dot-stripping, run-collapsing,
 * non-empty) semantics that the consolidation adopts fleet-wide, so its output
 * must stay byte-identical — the per-agent inbox/seen files it names are live
 * on-disk state (a change would orphan them). This locks that no-change.
 */
class BridgePathsSanitizeAgentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function agents(): iterable
    {
        yield 'plain agent name preserved' => ['kanban-solo', 'kanban-solo'];
        yield 'underscore preserved' => ['sola_pm', 'sola_pm'];
        yield 'dot stripped' => ['foo.bar', 'foo_bar'];
        yield 'slash sanitized' => ['a/b', 'a_b'];
        yield 'dotdot neutralized' => ['A..B', 'A_B'];
        yield 'empty falls back to agent' => ['', 'agent'];
        yield 'all-invalid falls back to agent-safe token' => ['///', '_'];
    }

    #[DataProvider('agents')]
    public function test_sanitize_agent_output_is_unchanged(string $input, string $expected): void
    {
        $this->assertSame($expected, BridgePaths::sanitizeAgent($input));
    }
}
