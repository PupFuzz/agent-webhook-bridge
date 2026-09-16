<?php

namespace App\Bridge\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * {@see StrippingOutput} over an output with a separate error stream and sections. Both of
 * the two ways a `ConsoleOutput` writes around its own `write()` are covered:
 *
 * - `getErrorOutput()` always answers a WRAPPED stream — including after `setErrorOutput()`
 *   has installed an unwrapped one, because the wrap happens on the way out, not on the way in.
 * - `section()` answers a {@see StrippingSectionOutput}. `ConsoleOutput::section()` builds its
 *   section directly on the RAW stream, so a delegating `section()` would have been a writer
 *   outside the choke.
 */
final class StrippingConsoleOutput extends StrippingOutput implements ConsoleOutputInterface
{
    /** @var array<ConsoleSectionOutput> */
    private array $sections = [];

    private readonly ConsoleOutputInterface $console;

    protected function __construct(ConsoleOutputInterface $console)
    {
        parent::__construct($console);
        $this->console = $console;
    }

    public function getErrorOutput(): OutputInterface
    {
        return StrippingOutput::wrap($this->console->getErrorOutput());
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->console->setErrorOutput($error);
    }

    /**
     * The inner output builds the section so its stream is the one this output writes to;
     * the section that is handed back is a stripping one over that same stream. The inner's
     * own section object is never written to.
     */
    public function section(): ConsoleSectionOutput
    {
        $raw = $this->console->section();

        return new StrippingSectionOutput($raw->getStream(), $this->sections, $this->getVerbosity(), $raw->getFormatter());
    }
}
