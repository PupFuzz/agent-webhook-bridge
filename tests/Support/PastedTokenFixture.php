<?php

namespace Tests\Support;

/**
 * A token-SHAPED value for the tests that prove a pasted credential is never printed. Built at
 * runtime from parts, so no committed line carries a literal the secret scanner (gitleaks'
 * `github-pat`) would read as a credential — and so the one place that decides what such a value
 * looks like is this file, not a copy per test.
 *
 * It is synthetic and authenticates as nothing. Its shape is a classic PAT's: the `ghp_` prefix
 * the framework's `looks_like_pasted_secret` recognises, then 36 characters.
 */
final class PastedTokenFixture
{
    public static function value(): string
    {
        return implode('', ['gh', 'p_']).str_repeat('Syn7hetic', 4);
    }
}
