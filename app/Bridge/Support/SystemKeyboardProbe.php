<?php

namespace App\Bridge\Support;

/**
 * The real-host {@see KeyboardProbe}: Laravel's own `defined('STDIN') &&
 * stream_isatty(STDIN)` (`ConfiguresPrompts::configurePrompts`).
 *
 * `defined('STDIN')` is not decoration — the constant exists only under the CLI SAPI, and
 * an artisan command reached from a web context (or from a harness that closed the
 * descriptors) has no STDIN to test at all. Absent ⇒ no keyboard, which is the safe
 * direction for every caller: they refuse to ask rather than assume someone is there.
 */
final class SystemKeyboardProbe implements KeyboardProbe
{
    public function hasKeyboard(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }
}
