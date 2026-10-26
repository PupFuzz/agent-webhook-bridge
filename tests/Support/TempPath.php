<?php

namespace Tests\Support;

/**
 * A fresh, test-owned path under the system temp dir: `<tmp>/<prefix>-<12 letters>`. The caller
 * creates it and deletes it in `tearDown()`.
 *
 * ⛔ The random part is drawn from `g`–`z` only: no digit, and no hex letter. `uniqid()` and
 * `bin2hex()` emit hex, and a test that prints its own temp path and then asserts a NUMBER is
 * absent from that output reds whenever the random part happens to contain the number
 * (`wbexp-6adf29002cf99` contains `9002`). With this alphabet no digit or hex needle can match
 * inside the random part, so a negative assertion over such output can only fail on the value it
 * is about.
 *
 * ⚑ What this does NOT cover: the `sys_get_temp_dir()` prefix is the host's (`TMPDIR`), and a
 * host whose temp dir itself contains the needle still reds such a test, on every run. And a
 * needle made only of `g`–`z` letters (`only`, `worm`) CAN match inside the random part, so an
 * absence assertion on such a word over output carrying this path is still a flake.
 */
final class TempPath
{
    private const ALPHABET = 'ghijklmnopqrstuvwxyz';

    private const LENGTH = 12;

    public static function unique(string $prefix): string
    {
        $suffix = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return sys_get_temp_dir().'/'.$prefix.'-'.$suffix;
    }
}
