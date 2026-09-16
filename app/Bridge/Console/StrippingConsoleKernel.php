<?php

namespace App\Bridge\Console;

use Illuminate\Foundation\Console\Kernel;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ⛔ WHERE THE OUTPUT CHOKE IS INSTALLED (card#9251, DL-393): the console kernel, at BOTH of its
 * entries into Artisan, so that no command ever holds an output the choke does not wrap.
 *
 * - `handle()` is the `php artisan` entry. The output is wrapped HERE, before Artisan runs,
 *   because the same object is what `Kernel::handle()`'s own catch renders an uncaught
 *   exception onto (`Illuminate\Foundation\Exceptions\Handler::renderForConsole()`, on
 *   stdout). A wrap installed later — in a command's `run()`, or on `OutputStyle` — never
 *   sees that render.
 * - `call()` is the `Artisan::call()` / test-harness entry, which never reaches `handle()` and
 *   builds its own buffer. The caller's buffer is wrapped; the kernel keeps the UNWRAPPED
 *   buffer so {@see self::output()} can still read it back.
 *
 * ⭐ EVERY ARTISAN COMMAND, not only `bridge:*`. A filter on the command name is defeated by
 * Symfony's abbreviation matching — `php artisan b:sign` runs `bridge:sign` — so the choke
 * covers the kernel, and framework commands lose their colour too.
 *
 * Registered in `bootstrap/app.php`, never a service provider: `Application::handleCommand()`
 * resolves the kernel before providers register, so a provider re-bind is silently inert.
 */
final class StrippingConsoleKernel extends Kernel
{
    private ?OutputInterface $callBuffer = null;

    /**
     * @param  InputInterface  $input
     * @param  OutputInterface|null  $output
     * @return int
     */
    public function handle($input, $output = null)
    {
        self::disableInteractiveEscapes();

        return parent::handle($input, StrippingOutput::wrap($output ?? new ConsoleOutput));
    }

    /**
     * @param  Command|string  $command
     * @param  array<array-key, mixed>  $parameters
     * @param  OutputInterface|null  $outputBuffer
     * @return int
     */
    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        self::disableInteractiveEscapes();

        $this->callBuffer = $outputBuffer ?? new BufferedOutput;

        return parent::call($command, $parameters, StrippingOutput::wrap($this->callBuffer));
    }

    /**
     * ⛔ THE PLAIN-TEXT CONSOLE (card#9251, operator decision 2026-09-15, Option 1): no
     * terminal control sequence leaves the process at all, with no per-writer exception.
     * Colour is one source of those (Decision 4); the other two are Laravel Prompts and
     * Symfony's `QuestionHelper` autocomplete, which draw with cursor-movement and
     * erase sequences of their OWN that the strip in {@see TerminalSafeText} cannot
     * distinguish from a foreign payload without also destroying them — undecorating the
     * formatter (Decision 4) does not touch either, because neither goes through a
     * formatter tag. Both are switched off through a SUPPORTED seam, not a vendor patch,
     * and both are process-wide statics, so calling them once per entry — before Artisan
     * or a command exists — covers every command without a per-call-site change:
     *
     * - `Prompt::fallbackWhen(true)` sets a static every `Laravel\Prompts\*` prompt class
     *   inherits (none of them redeclare it), OR'd with whatever `configurePrompts()`
     *   passes later (`windows_os() || runningUnitTests()`), so it only WIDENS. A prompt
     *   renders through its line-based fallback — registered per-command by
     *   `Illuminate\Console\Concerns\ConfiguresPrompts::configurePrompts()`, which every
     *   `Illuminate\Console\Command::run()` calls — the moment one exists for that class;
     *   `confirm()`/`select()`/etc. do, because Laravel registers one for each. A Prompt
     *   subclass with NO registered fallback (`Note`, `Callout`, `Table`, `Spinner`,
     *   `Progress`, `Title`, `Clear`, `DataTable`) is unaffected: `shouldFallback()`
     *   requires `isset($fallbacks[static::class])`, so the flag alone does not divert
     *   it. `app/` calls none of them (DL-393 Decision 5's Prompts population).
     * - `QuestionHelper::disableStty()` — a public method, documented "Prevents usage of
     *   stty" — makes `doAsk()` skip the autocomplete branch (`null === $autocomplete ||
     *   !self::$stty || ...`) for every question, whether or not it carries an
     *   autocompleter. `Command::choice()` always builds a `ChoiceQuestion`, and
     *   `ChoiceQuestion`'s OWN constructor sets one over its choices — Laravel never
     *   asks for it. The same flag also skips `getHiddenResponse()`'s stty-masked read for
     *   ANY hidden question, and under it the NON-fallback question is the safe one — it
     *   throws. The hazardous one is the fallback-TRUE question, which is Symfony's own
     *   default and what `$this->secret()` passes: `doAsk()` SWALLOWS the failure and reads
     *   the answer back as a PLAIN, VISIBLE read, echoed to the screen and the scrollback
     *   (bound (12)). `app/` calls no `choice()` and no hidden read today, and that absence
     *   is CHECKED rather than read once — `ConsoleBypassCensusTest`'s HIDDEN INPUT category
     *   reds on one anywhere in `app/`, over method names it DERIVES from vendor on every
     *   run rather than recalls, so a future one arrives as a red test with a ruling to
     *   write. What that derivation cannot reach is stated as its own bound, there.
     */
    private static function disableInteractiveEscapes(): void
    {
        Prompt::fallbackWhen(true);
        QuestionHelper::disableStty();
    }

    /**
     * ⛔ The base kernel discovers `app/Console/Commands` only when `get_class($this)` IS the
     * base class — a subclass is assumed to register its own. This one registers none, so
     * without this override every `bridge:*` command vanishes from Artisan, silently.
     *
     * @return bool
     */
    protected function shouldDiscoverCommands()
    {
        return true;
    }

    /**
     * `Illuminate\Console\Application::output()`'s own rule, read from the buffer the caller
     * built rather than from the wrapper Artisan was handed (which has no `fetch()`).
     *
     * @return string
     */
    public function output()
    {
        $this->bootstrap();

        return $this->callBuffer !== null && method_exists($this->callBuffer, 'fetch') ? $this->callBuffer->fetch() : '';
    }
}
