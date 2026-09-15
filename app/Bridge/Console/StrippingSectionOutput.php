<?php

namespace App\Bridge\Console;

use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;

/**
 * A console section inside the output choke (card#9251, DL-393): permanently undecorated, and
 * every byte it writes or records as content is stripped first.
 *
 * Undecorated, Symfony's section never rewrites the screen — `clear()` and `overwrite()` fall
 * back to plain writes — so what reaches the stream is exactly the stripped content.
 *
 * ⚠ ONE BOUND, stated: `setMaxHeight()` on a section that has already RECORDED lines writes
 * Symfony's own cursor-up / erase-below sequence through a private path this class cannot
 * reach. Undecorated, a section records lines only through `addContent()` (stripped here),
 * the sequence carries no foreign byte, and nothing in `app/` sets a max height.
 */
final class StrippingSectionOutput extends ConsoleSectionOutput
{
    /**
     * @param  resource  $stream
     * @param  array<ConsoleSectionOutput>  $sections
     */
    public function __construct($stream, array &$sections, int $verbosity, OutputFormatterInterface $formatter)
    {
        parent::__construct($stream, $sections, $verbosity, false, $formatter);
    }

    public function setDecorated(bool $decorated): void {}

    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        $formatter->setDecorated(false);
        parent::setFormatter($formatter);
    }

    public function addContent(string $input, bool $newline = true): int
    {
        return parent::addContent(TerminalSafeText::strip($input), $newline);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite(TerminalSafeText::strip($message), $newline);
    }
}
