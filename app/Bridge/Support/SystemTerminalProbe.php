<?php

namespace App\Bridge\Support;

/**
 * The real-host {@see TerminalProbe}. The stdin half is Laravel's own spelling
 * (`ConfiguresPrompts::configurePrompts` gates `Prompt::interactive()` on
 * `defined('STDIN') && stream_isatty(STDIN)`); the stdout half is the same test applied to
 * the stream the question is actually written to.
 *
 * ⚠ No `function_exists('stream_isatty')` guard, unlike `InboxCommand`'s sibling read — and
 * the asymmetry is deliberate rather than overlooked: the function is core since PHP 7.2 and
 * this app requires far newer, so that guard's false branch is unreachable. Named here so the
 * next reader does not "fix" one to match the other.
 *
 * `defined(...)` is not decoration — the constants exist only under the CLI SAPI, and an
 * artisan command reached from a web context (or from a harness that closed the descriptors)
 * has no stream to test at all. Absent ⇒ no terminal, which is the safe direction for every
 * caller: they refuse to ask rather than assume someone is there.
 */
final class SystemTerminalProbe implements TerminalProbe
{
    public function hasKeyboard(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    public function hasScreen(): bool
    {
        return defined('STDOUT') && stream_isatty(STDOUT);
    }
}
