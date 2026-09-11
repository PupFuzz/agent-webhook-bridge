<?php

namespace App\Bridge\Support;

/**
 * The real-host {@see KeyboardProbe}: Laravel's own `defined('STDIN') &&
 * stream_isatty(STDIN)` (`ConfiguresPrompts::configurePrompts`).
 *
 * ⚠ No `function_exists('stream_isatty')` guard, unlike `InboxCommand`'s sibling read — and
 * the asymmetry is deliberate rather than overlooked: the function is core since PHP 7.2 and
 * this app requires far newer, so that guard's false branch is unreachable. Named here so the
 * next reader does not "fix" one to match the other.
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
