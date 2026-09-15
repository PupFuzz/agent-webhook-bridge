<?php

namespace App\Bridge\Console;

use Illuminate\Foundation\Console\Kernel;
use Symfony\Component\Console\Command\Command;
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
        $this->callBuffer = $outputBuffer ?? new BufferedOutput;

        return parent::call($command, $parameters, StrippingOutput::wrap($this->callBuffer));
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
