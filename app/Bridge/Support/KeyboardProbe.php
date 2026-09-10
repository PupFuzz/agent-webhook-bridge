<?php

namespace App\Bridge\Support;

/**
 * ONE host fact: is there a keyboard on this process's stdin — a human who can answer a
 * question — or is stdin a pipe, a file, `/dev/null`, or a closed descriptor?
 *
 * ⛔ WHY THIS IS NOT `$input->isInteractive()`, WHICH IS THE PREDICATE THAT LOOKS RIGHT.
 * Symfony sets `isInteractive()` false for `--no-interaction` / `-n` / `-q` and NOTHING
 * ELSE — there is no tty branch in `Application::configureIO`. So under a pipe it stays
 * TRUE, and `QuestionHelper::ask`, which branches only on it, goes on to READ STDIN:
 * `printf 'yes\n' | php artisan …` answers the question with nobody present, and a pipe
 * that is open but never writes blocks the command indefinitely. Measured both ways on
 * this repo's own bootstrap (DL-369 / card#9141 fix round).
 *
 * ⚑ THE PREDICATE IS LARAVEL'S OWN, for this exact question: `ConfiguresPrompts` gates
 * `Prompt::interactive()` on `$input->isInteractive() && defined('STDIN') &&
 * stream_isatty(STDIN)`. The framework already knows `isInteractive()` alone is not the
 * question; only the `confirm()`/`QuestionHelper` path predates that knowledge.
 *
 * ⚠ IT IS A SEAM BECAUSE IT IS NOT CONSTRUCTIBLE IN-PROCESS. A test cannot give phpunit's
 * own stdin a terminal, and whether it HAS one differs between a developer's shell and
 * CI — so a command that read the real predicate directly would be green locally and take
 * the other branch in CI, or the reverse. Bound in `BridgeServiceProvider`; replaced with
 * `$this->app->instance()` in tests, exactly as {@see ChannelProbeEnvironment} is.
 */
interface KeyboardProbe
{
    public function hasKeyboard(): bool;
}
