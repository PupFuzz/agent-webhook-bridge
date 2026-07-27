<?php

namespace App\Bridge\Support;

/**
 * One `bridge:check` probe finding — the single shared primitive for what used to be
 * an `array{severity: string, message: string}` re-declared as a docblock literal at
 * every producer and consumer, and constructed by a private helper triple duplicated
 * per probe (card 5178).
 *
 * The named constructors replace those per-probe helpers 1:1, so construction stays
 * as cheap as the array literal was, while {@see Severity} makes an unknown severity
 * unrepresentable rather than something the renderer decides how to print.
 */
final class Finding
{
    public function __construct(
        public readonly Severity $severity,
        public readonly string $message,
    ) {}

    public static function ok(string $message): self
    {
        return new self(Severity::Ok, $message);
    }

    public static function warn(string $message): self
    {
        return new self(Severity::Warn, $message);
    }

    public static function unvalidated(string $message): self
    {
        return new self(Severity::Unvalidated, $message);
    }

    public static function fail(string $message): self
    {
        return new self(Severity::Fail, $message);
    }
}
