<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempPath;

/**
 * The random part of a test temp path carries no digit and no hex letter, so a negative
 * assertion for a numeric or hex needle over output that prints the path cannot match inside it.
 * The alphabet is the whole property: a helper that went back to `uniqid()` or `bin2hex()` reds
 * here on every run, not only on the run whose draw happens to contain the needle.
 */
class TempPathTest extends TestCase
{
    public function test_the_random_part_is_drawn_from_letters_that_no_digit_or_hex_needle_can_match(): void
    {
        $prefix = sys_get_temp_dir().'/wbexp-';

        for ($i = 0; $i < 50; $i++) {
            $path = TempPath::unique('wbexp');

            $this->assertStringStartsWith($prefix, $path);
            $this->assertMatchesRegularExpression('/^[g-z]{12}$/', substr($path, strlen($prefix)));
        }
    }

    public function test_two_draws_name_two_paths(): void
    {
        $this->assertNotSame(TempPath::unique('wbexp'), TempPath::unique('wbexp'));
    }
}
