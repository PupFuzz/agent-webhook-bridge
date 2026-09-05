<?php

namespace Tests\Support;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A buffered output that also HAS a second stream — which a plain `BufferedOutput` does not.
 *
 * ⭐ WITHOUT IT, EVERY STREAM ASSERTION IN THIS SUITE IS VACUOUS. `Artisan::call()` and
 * `$this->artisan()` hand a command a plain buffer with no error half, so the "write it to
 * stderr" seam correctly falls back to the one stream and stdout-vs-stderr output is
 * byte-identical whichever channel the code chose. A test through the normal harness
 * therefore cannot fail when the choice is wrong, which makes its pass evidence of nothing.
 */
class SplitConsoleOutput extends BufferedOutput implements ConsoleOutputInterface
{
    public BufferedOutput $errors;

    public function __construct()
    {
        parent::__construct();
        $this->errors = new BufferedOutput;
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errors;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        // Never called by anything under test; the property above is the seam.
    }

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('sections are not part of what this instrument measures');
    }
}
