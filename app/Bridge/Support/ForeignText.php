<?php

namespace App\Bridge\Support;

/**
 * A STRING A FOREIGN PRINCIPAL WROTE, in a type that CANNOT BE PRINTED BY ACCIDENT
 * (card#9200, DL-366).
 *
 * ⭐ WHY A TYPE AND NOT A CALL TO {@see UntrustedText::forOperator()} AT THE PRODUCER. Where a
 * foreign value is display-only, the producer escapes it on the way out and its field stays a
 * plain `string` that now MEANS "safe to print" — no call site can get that wrong, today or
 * ever, and this class is not needed. This class is for the other shape: a value the bridge
 * must keep RAW because its own logic matches on it, and which is ALSO printed. Escaping it
 * at the producer would break the matching; leaving it a `string` leaves every print site to
 * remember. So the raw bytes are kept and the TYPE is what stops them reaching a sink.
 *
 * ⛔ THERE IS NO `__toString()`, AND ITS ABSENCE IS THE WHOLE MECHANISM. Interpolating one of
 * these — `"… {$pr['head_ref']} …"` — is BOTH a phpstan level-7 error at build time AND a
 * runtime `Error` at the interpolation itself, so an omission is a broken build rather than a
 * line on an operator's terminal that nobody notices. ⚑ DO NOT ADD ONE, not even "just for
 * tests": a `__toString` would make every one of those sites compile and run again, silently,
 * which is exactly the state card#9200 records.
 * ⭐ IT FIRES AT THE VALUE, NOT AT THE SINK, so it fires identically in `$this->line()`,
 * `$this->error()`, `Finding::warn()`, a `Log::` context and a sink nobody has written yet. A
 * chokepoint placed on `Finding` would have covered only the sinks that build a `Finding`.
 *
 * ⚠ {@see self::rawForMatching()} IS AN EXIT AND IS NAMED LIKE ONE. It is interpolatable, so
 * it is not a boundary the language enforces — what it is instead is GREPPABLE and DELIBERATE:
 * a reviewer can enumerate every site that asks for raw bytes, and `ForeignTextRawUseTest`
 * pins that enumeration so a NEW one reds rather than being noticed or not. Its legitimate
 * uses take raw text to a MATCHER; none of them takes it to an output stream.
 */
final class ForeignText
{
    private function __construct(private readonly string $raw) {}

    /**
     * Declare that `$raw` is text this install did not author.
     */
    public static function of(string $raw): self
    {
        return new self($raw);
    }

    /**
     * The bytes, made safe for an operator's terminal — {@see UntrustedText} owns the rule.
     */
    public function forOperator(): string
    {
        return UntrustedText::forOperator($this->raw);
    }

    /**
     * ⚠ THE RAW BYTES, FOR A MATCHER AND NEVER FOR A SINK. Every caller is pinned by
     * `ForeignTextRawUseTest`; adding one means adding it there with its reason.
     */
    public function rawForMatching(): string
    {
        return $this->raw;
    }

    /**
     * Whether the foreign value is empty — asked without unwrapping it, because "did the
     * answer carry a ref at all" is a question about the value and not about its bytes.
     */
    public function isEmpty(): bool
    {
        return $this->raw === '';
    }
}
