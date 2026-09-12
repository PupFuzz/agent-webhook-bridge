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
     */
    private function __construct(
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
     * NOT A CONSTRUCTION SITE. Every severity this can carry was decided by whichever
     * factory built the finding being re-scoped, which is what keeps the `unvalidated`
     * call-site pin complete while this exists.
     */
    public function scoped(string $scope): self
    {
        return new self($this->severity, $scope.': '.$this->message);
    }
}
