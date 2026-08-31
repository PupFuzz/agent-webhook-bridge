<?php

namespace Tests\Support\CheckGolden;

use PHPUnit\Framework\Assert;

/**
 * The one invocation of `bin/check-golden-predicates.php` the currency guards share.
 *
 * WHY IT IS SHARED RATHER THAN COPIED. Two guards now make statements about the same
 * subject — the predicate set `bin/check-golden-mutate.php` measured — and they must not
 * be able to disagree about what "live" means. A second invocation, however small, is a
 * second chance to decode differently, tolerate a different failure, or drift when the
 * enumerator's interface moves; the guards would then red on each other rather than on
 * the tree. One primitive, both callers.
 *
 * NON-VACUOUS BY CONSTRUCTION. A script that fails to run, or prints something that is
 * not a JSON list, fails HERE rather than returning a plausible empty list that would let
 * a caller's comparison pass by accident on some branch.
 *
 * @phpstan-type Predicate array{id: string, kind: string, line: int, start: int, end: int, source: string}
 */
trait ReadsGoldenPredicates
{
    /**
     * Every branch predicate the generator would measure, from the generator's own enumerator.
     *
     * @return list<array<string, mixed>>
     */
    protected function livePredicates(): array
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('bin/check-golden-predicates.php')).' --json 2>/dev/null';
        exec($cmd, $lines, $code);

        Assert::assertSame(0, $code, 'bin/check-golden-predicates.php did not exit 0, so this run measured nothing about the doc.');
        $decoded = json_decode(implode("\n", $lines), true);
        Assert::assertIsArray($decoded, 'bin/check-golden-predicates.php --json did not produce a list.');
        Assert::assertNotEmpty($decoded, 'the predicate enumeration came back EMPTY — an empty result is a measurement that never happened.');

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }
}
