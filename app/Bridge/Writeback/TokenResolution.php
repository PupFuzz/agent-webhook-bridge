<?php

namespace App\Bridge\Writeback;

/**
 * The outcome of resolving a GitHub read token for one repo (GitHubTokenResolver).
 * Exactly one of {token, problem} is set: a resolved token carries a human-readable
 * `source` label (for diagnostics), and a fail-loud outcome carries a `problem`
 * message. Deliberately non-throwing so bridge:reconcile can map a problem to its
 * loud non-zero exit while bridge:check maps the same problem to a warn — one
 * precedence, two error postures (DL-185).
 */
final class TokenResolution
{
    private function __construct(
        public readonly ?string $token,
        public readonly ?string $source,
        public readonly ?string $problem,
    ) {}

    public static function resolved(string $token, string $source): self
    {
        return new self($token, $source, null);
    }

    public static function problem(string $problem): self
    {
        return new self(null, null, $problem);
    }

    public function ok(): bool
    {
        return $this->token !== null;
    }
}
