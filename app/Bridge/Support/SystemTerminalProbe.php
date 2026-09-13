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
 *
 * ⚠ WHAT THIS MEASURES IS THE PROCESS'S fd 1, AND THE QUESTION GOES TO THE COMMAND'S
 * `$this->output`. Under `artisan` those are the same stream — `artisan` hands the
 * Application an `ArgvInput` and the `OutputStyle` is built around the resulting
 * `ConsoleOutput` — which is why `hasScreen()` is a sound gate TODAY and why no caller
 * sources the probe from the output object. They diverge under `Artisan::call()`, where the
 * output is a `BufferedOutput` while fd 1 may still be a tty: the gate would pass, the
 * question would land in the buffer, and `confirm()` would block on the real STDIN. ⛔ NO
 * SUCH CALLER EXISTS — `Artisan::call`, `Schedule::command` and `$this->call(` of the
 * offering command are absent from `app/ routes/ bootstrap/ bin/`, and defending against a
 * state nothing can reach is its own defect (canon #6), so nothing here guards it.
 * ⭐ IT BECOMES REACHABLE THE MOMENT A PROGRAMMATIC CALLER APPEARS — card#9255's migration of
 * `bridge:jobs install-tick` onto the shared predicate is the specific one on the table. That
 * is when to source the screen half from `$this->output->getOutput()` instead of the `STDOUT`
 * constant; `App\Console\Commands\Bridge\JobsCommand::stderr()` already does exactly that,
 * for exactly this reason, and its docblock names `Artisan::call()` as the live case.
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
