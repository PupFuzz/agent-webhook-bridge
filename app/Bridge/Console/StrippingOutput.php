<?php

namespace App\Bridge\Console;

use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ⛔ THE OUTPUT CHOKE (card#9251, DL-393): a decorator every console output of this app is
 * wrapped in before any command sees it. Every write is formatted HERE, undecorated, then put
 * through {@see TerminalSafeText::strip()}, and only then handed to the real output as
 * `OUTPUT_RAW`. No producer declares anything — a value nobody thought to escape is treated
 * exactly like this install's own prose.
 *
 * ⭐ DECORATION IS OFF AND STAYS OFF. `setDecorated(true)` — which is what `--ansi` calls — is
 * ignored, and a formatter handed to `setFormatter()` is undecorated before it is installed.
 * That is the operator decision on card#9251 (gate 1, option a), not a side effect: with
 * decoration on, a foreign `<options=conceal>`, an unclosed `<fg=black;bg=black>` or an
 * `<href=…>` would become a real escape sequence the install's own formatter emitted, which no
 * byte strip can tell from the install's own colour. Undecorated, the formatter swallows the
 * tag and the text survives.
 *
 * ⚠ THE FORMATTING IS DONE HERE, NOT BY THE INNER OUTPUT, because the strip has to see the
 * string AFTER formatting and immediately before the stream, and the inner output's `doWrite()`
 * is not reachable from outside it. What the inner output receives is already final.
 *
 * Built by {@see self::wrap()}, which keeps the wrapped output's `ConsoleOutputInterface`-ness:
 * code that asks `instanceof ConsoleOutputInterface` for a separate error stream
 * (`SignCommand::errorStream()`, `JobsCommand::stderr()`, Symfony's `QuestionHelper`) gets the
 * same answer it got before.
 */
class StrippingOutput implements OutputInterface
{
    private const TYPES = self::OUTPUT_NORMAL | self::OUTPUT_RAW | self::OUTPUT_PLAIN;

    private const VERBOSITIES = self::VERBOSITY_QUIET | self::VERBOSITY_NORMAL | self::VERBOSITY_VERBOSE
        | self::VERBOSITY_VERY_VERBOSE | self::VERBOSITY_DEBUG;

    /**
     * An incomplete UTF-8 sequence a write ended on, held for the next write instead of being
     * scrubbed to U+FFFD: a producer that streams chunks (`queue:listen` forwarding a child's
     * pipe reads) splits a character at a read boundary. At most three bytes, never below
     * 0x80, so no control byte is ever held. A write that ends a line releases it scrubbed, and
     * so does destruction. It lives on THIS object: a split across two separately fetched
     * `getErrorOutput()` wrappers, or inside a section, is still two U+FFFD (DL-393 bound).
     */
    private string $carry = '';

    protected function __construct(private readonly OutputInterface $inner)
    {
        $inner->setDecorated(false);
    }

    public static function wrap(OutputInterface $output): OutputInterface
    {
        if ($output instanceof self) {
            return $output;
        }

        return $output instanceof ConsoleOutputInterface ? new StrippingConsoleOutput($output) : new self($output);
    }

    /**
     * `Output::write()`'s own contract, reproduced so the verbosity gate runs BEFORE the
     * formatter: a suppressed message must not touch the formatter's style stack, exactly as
     * it does not today.
     *
     * @param  string|iterable<string>  $messages
     */
    public function write(string|iterable $messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void
    {
        $type = self::TYPES & $options ?: self::OUTPUT_NORMAL;
        $verbosity = self::VERBOSITIES & $options ?: self::VERBOSITY_NORMAL;
        if ($verbosity > $this->getVerbosity()) {
            return;
        }

        $formatter = $this->inner->getFormatter();
        $safe = [];
        foreach (is_iterable($messages) ? $messages : [$messages] as $message) {
            $formatted = $this->carry.match ($type) {
                self::OUTPUT_RAW => $message,
                self::OUTPUT_PLAIN => strip_tags((string) $formatter->format($message)),
                default => (string) $formatter->format($message),
            };
            $this->carry = $newline ? '' : self::incompleteUtf8Tail($formatted);
            $safe[] = TerminalSafeText::strip(substr($formatted, 0, strlen($formatted) - strlen($this->carry)));
        }

        $this->inner->write($safe, $newline, self::OUTPUT_RAW | $verbosity);
    }

    /**
     * A carry nobody completed is still scrubbed and stripped on its way out: it is at most
     * three bytes of 0x80 and above, so it can only ever become U+FFFD.
     */
    public function __destruct()
    {
        if ($this->carry !== '') {
            $this->inner->write(TerminalSafeText::strip($this->carry), false, self::OUTPUT_RAW | self::VERBOSITY_QUIET);
        }
    }

    /**
     * The trailing bytes of $text that begin a UTF-8 sequence without finishing it: a lead byte
     * among the last three, followed by fewer continuation bytes than it announces. Anything
     * else — ASCII, a finished sequence, a run of continuation bytes with no lead — is ''.
     */
    private static function incompleteUtf8Tail(string $text): string
    {
        $length = strlen($text);
        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($text[$length - $back]);
            if ($byte < 0x80) {
                return '';
            }
            if ($byte >= 0xC0) {
                $announced = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                return $back < $announced ? substr($text, -$back) : '';
            }
        }

        return '';
    }

    /** @param  string|iterable<string>  $messages */
    public function writeln(string|iterable $messages, int $options = self::OUTPUT_NORMAL): void
    {
        $this->write($messages, true, $options);
    }

    public function setVerbosity(int $level): void
    {
        $this->inner->setVerbosity($level);
    }

    public function getVerbosity(): int
    {
        return $this->inner->getVerbosity();
    }

    public function isSilent(): bool
    {
        return $this->inner->isSilent();
    }

    public function isQuiet(): bool
    {
        return $this->inner->isQuiet();
    }

    public function isVerbose(): bool
    {
        return $this->inner->isVerbose();
    }

    public function isVeryVerbose(): bool
    {
        return $this->inner->isVeryVerbose();
    }

    public function isDebug(): bool
    {
        return $this->inner->isDebug();
    }

    /** Ignored — see the class docblock. `--ansi` arrives here. */
    public function setDecorated(bool $decorated): void {}

    public function isDecorated(): bool
    {
        return false;
    }

    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        $formatter->setDecorated(false);
        $this->inner->setFormatter($formatter);
    }

    public function getFormatter(): OutputFormatterInterface
    {
        return $this->inner->getFormatter();
    }
}
