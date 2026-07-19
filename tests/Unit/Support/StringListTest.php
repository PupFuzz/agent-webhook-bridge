<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\StringList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StringListTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function values(): iterable
    {
        yield 'strings preserved in order' => [['b', 'a', 'c'], ['b', 'a', 'c']];
        yield 'int keys reindexed' => [[5 => 'x', 9 => 'y'], ['x', 'y']];
        yield 'string keys dropped' => [['k' => 'v'], ['v']];
        yield 'int element stringified' => [[1, 2], ['1', '2']];
        yield 'float element stringified' => [[1.5], ['1.5']];
        yield 'bool element stringified' => [[true, false], ['1', '']];
        yield 'nested array element becomes empty string' => [[['x'], 'y'], ['', 'y']];
        yield 'null element becomes empty string' => [[null, 'y'], ['', 'y']];
        yield 'empty array yields empty list' => [[], []];
        // Tolerant container guard: a non-array yields [] rather than throwing.
        yield 'non-array string yields empty list' => ['nope', []];
        yield 'non-array int yields empty list' => [42, []];
        yield 'null yields empty list' => [null, []];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('values')]
    public function test_coerces_to_a_list_of_strings(mixed $input, array $expected): void
    {
        $this->assertSame($expected, StringList::coerce($input));
    }
}
