<?php

namespace App\Bridge\Writeback;

/**
 * The outcome of resolving a GitHub read token for one repo (GitHubTokenResolver).
 * Exactly one of {token, problem} is set: a resolved token carries a human-readable
 * `source` label (for diagnostics), and a fail-loud outcome carries a `problem`
 * message. Deliberately non-throwing so bridge:reconcile can map a problem to its
 * loud non-zero exit while bridge:check maps the same problem to a warn — one
 * precedence, two error postures (DL-185).
 *
 * A problem from the token-FILE legs also carries a {@see TokenFileFault}, so a caller that must
 * tell an empty file from one it merely could not read does not parse the prose (card#11201).
 */
final class TokenResolution
{
    private function __construct(
        public readonly ?string $token,
        public readonly ?string $source,
        public readonly ?string $problem,
        public readonly ?TokenFileFault $fileFault,
    ) {}

    public static function resolved(string $token, string $source): self
    {
        return new self($token, $source, null, null);
    }

    public static function problem(string $problem, ?TokenFileFault $fileFault = null): self
    {
        return new self(null, null, $problem, $fileFault);
    }

    public function ok(): bool
    {
        return $this->token !== null;
    }
}
