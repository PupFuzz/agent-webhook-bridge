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
 *
 * ⭐ A MESSAGE IS EITHER A STRING OR A LIST OF SEGMENTS, and the list is how a producer
 * declares which parts of its own sentence it did not author (card#9121, DL-366). A
 * segment is either prose this install wrote or an {@see Untrusted} span; `$message` is
 * their plain concatenation and is byte-identical to the interpolated string the same
 * producer used to pass, so `--format=json` moves not one byte. Read {@see Untrusted} for
 * why the span carries a POSITION and not a value to be searched for.
 */
final class Finding
{
    /**
     * The finding as a machine consumer reads it — the segments, concatenated, escaped
     * NOWHERE.
     *
     * ⛔ ITS ONE READER IN `app/` IS `CheckJsonRenderer::finding()`. That is the write
     * contract this whole design is shaped around: the JSON document carries these bytes
     * verbatim to consumers already parsing them. The TERMINAL renderer does not read this
     * field at all — it walks {@see self::$segments}, which is the only place the seam
     * between prose and foreign text still exists.
     */
    public readonly string $message;

    /**
     * PRIVATE SINCE DL-251, so the four named factories are the only door.
     *
     * It was public, and three checks used it to re-scope a finding another probe had
     * already produced (`new Finding($f->severity, "board_tools ssh: ".$f->message)`) —
     * near-identical edits through one primitive, which is {@see self::scoped()}'s job now.
     * The consequence that mattered is that `Finding::unvalidated(` was NOT the only way to
     * construct one: a fourth such site could have minted the severity with nothing keyed
     * on the factory name able to see it. Closing the door makes the construction-site pin
     * in `UnvalidatedCallSiteTest` exhaustive BY CONSTRUCTION rather than by grep coverage.
     *
     * @param  list<string|Untrusted>  $segments  this install's prose, and the spans it did not author, IN ORDER
     */
    private function __construct(
        public readonly Severity $severity,
        public readonly array $segments,
    ) {
        $flat = '';
        foreach ($segments as $segment) {
            $flat .= $segment instanceof Untrusted ? $segment->raw : $segment;
        }
        $this->message = $flat;
    }

    /**
     * @param  string|list<string|Untrusted>  $message
     */
    public static function ok(string|array $message): self
    {
        return new self(Severity::Ok, self::segments($message));
    }

    /**
     * @param  string|list<string|Untrusted>  $message
     */
    public static function warn(string|array $message): self
    {
        return new self(Severity::Warn, self::segments($message));
    }

    /**
     * @param  string|list<string|Untrusted>  $message
     */
    public static function unvalidated(string|array $message): self
    {
        return new self(Severity::Unvalidated, self::segments($message));
    }

    /**
     * @param  string|list<string|Untrusted>  $message
     */
    public static function fail(string|array $message): self
    {
        return new self(Severity::Fail, self::segments($message));
    }

    /**
     * The same finding, re-scoped for the operator's line — `<scope>: <message>`, severity
     * untouched.
     *
     * A finding carries no scope field (stage 1 folded the render-time prefix into the
     * message), so a `Check` wrapping a probe's output has to re-prefix it. Three checks did
     * that by re-invoking the constructor with the source finding's severity; this names the
     * operation once, and makes severity-preservation a property of the primitive instead of
     * something three call sites each get right. (`Check` is NAMED, never `{@see}`-linked:
     * pint's docblock fixer turns a fully-qualified `{@see}` into a real `use`, and an import
     * here would invert the layer — this primitive must not depend on its consumer.)
     *
     * ⛔ IT PREPENDS A SEGMENT AND NEVER TOUCHES `$message`. Re-scoping used to read the flat
     * string and re-declare the span list beside it, which meant the scope prefix could shift
     * a span's position without the declaration knowing. A prefix segment cannot: the spans
     * are still exactly where they were, one place further down a list.
     *
     * NOT A CONSTRUCTION SITE. Every severity this can carry was decided by whichever
     * factory built the finding being re-scoped, which is what keeps the `unvalidated`
     * call-site pin complete while this exists.
     */
    public function scoped(string $scope): self
    {
        return new self($this->severity, [$scope.': ', ...$this->segments]);
    }

    /**
     * The segment list a factory was given — a lone prose segment when the producer passed a
     * plain string, which is every finding that declares nothing.
     *
     * ⚑ NO `array_values()` HERE. The parameter type IS `list<…>`, checked at every call
     * site, so re-keying would be a defence against a state the type system already rules
     * out (canon #6) — and phpstan says so out loud.
     *
     * @param  string|list<string|Untrusted>  $message
     * @return list<string|Untrusted>
     */
    private static function segments(string|array $message): array
    {
        return is_string($message) ? [$message] : $message;
    }
}
