<?php

namespace Tests\Support;

use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;

/**
 * Build a {@see Finding} of a severity chosen at RUNTIME, for the tests that iterate
 * `Severity::cases()` to assert a renderer property holds for the whole vocabulary.
 *
 * IT EXISTS BECAUSE `Finding`'s CONSTRUCTOR IS PRIVATE (DL-251) and must stay that way:
 * the named factories being the only door is what makes the `unvalidated` construction-site
 * pin exhaustive by construction. A test-only `Finding::of(Severity, string)` was rejected on
 * the class itself — it would have re-opened exactly that door for production code — so the
 * parameterised form lives here instead, where nothing in `app/` can reach it.
 *
 * The `match` is EXHAUSTIVE on purpose: a fifth severity is a static-analysis error here,
 * which is the same guarantee the renderer's own `match`es carry.
 */
trait FindingFactories
{
    private static function findingOf(Severity $severity, string $message): Finding
    {
        return match ($severity) {
            Severity::Fail => Finding::fail($message),
            Severity::Warn => Finding::warn($message),
            Severity::Unvalidated => Finding::unvalidated($message),
            Severity::Ok => Finding::ok($message),
        };
    }
}
