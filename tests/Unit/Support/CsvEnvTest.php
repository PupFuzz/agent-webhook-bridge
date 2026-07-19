<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\CsvEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvEnvTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function values(): iterable
    {
        yield 'trims each and drops empties' => ['  a , b ,,  ,c ', ['a', 'b', 'c']];
        yield 'single value' => ['/usr/bin/php', ['/usr/bin/php']];
        yield 'preserves order' => ['c,a,b', ['c', 'a', 'b']];
        yield 'whitespace-only yields empty list' => ['   ', []];
        yield 'empty string yields empty list' => ['', []];
        yield 'only separators yields empty list' => [',,,', []];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('values')]
    public function test_splits_trims_and_drops_empties(string $csv, array $expected): void
    {
        $this->assertSame($expected, CsvEnv::parse($csv));
    }
}
