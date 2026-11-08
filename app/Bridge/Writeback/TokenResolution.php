<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\PastedSecretShape;

/**
 * The outcome of resolving a GitHub token for one repo (GitHubTokenResolver).
 * Exactly one of {token, problem} is set: a resolved token carries a human-readable
 * `source` label (for diagnostics), and a fail-loud outcome carries a `problem`
 * message. Deliberately non-throwing so bridge:reconcile can map a problem to its
 * loud non-zero exit while bridge:check maps the same problem to a finding — one
 * precedence, two error postures (DL-185).
 *
 * A problem also carries a {@see TokenFileFault}, so a caller that must tell an empty file from one
 * it merely could not read does not parse the prose (card#11201). Both outcomes carry the
 * {@see TokenSource} that answered and the FILE it named, where there is one, so `bridge:check` can
 * say per repo which source resolved and judge the receiver's read of that file (DL-456). The path
 * is never the token: printing it is safe.
 *
 * A store outcome also carries the store KEY that routed the repo, so {@see sourceLabel()} can name
 * the credential an operator has to fix without printing the file it points at (card#12968).
 */
final class TokenResolution
{
    private function __construct(
        public readonly ?string $token,
        public readonly ?string $source,
        public readonly ?string $problem,
        public readonly ?TokenFileFault $fileFault,
        public readonly ?TokenSource $sourceKind,
        public readonly ?string $path,
        public readonly ?string $storeKey = null,
    ) {}

    public static function resolved(string $token, string $source, TokenSource $kind, ?string $path = null): self
    {
        return new self($token, $source, null, null, $kind, $path);
    }

    public static function problem(string $problem, ?TokenFileFault $fileFault = null, ?TokenSource $kind = null, ?string $path = null): self
    {
        return new self(null, null, $problem, $fileFault, $kind, $path);
    }

    /** This outcome, attributed to the `[git-credential-map]` key that routed the repo. */
    public function withStoreKey(string $key): self
    {
        return new self($this->token, $this->source, $this->problem, $this->fileFault, $this->sourceKind, $this->path, $key);
    }

    /**
     * Which source answered, as a log line or a finding may print it: never the token and never a
     * file path — a store key by its display name ({@see PastedSecretShape::displayName()}, which
     * elides a key shaped like a pasted secret).
     */
    public function sourceLabel(): string
    {
        return match ($this->sourceKind) {
            TokenSource::WriteTokenPath => 'write_token_path',
            TokenSource::Store => $this->storeKey === null ? 'coord credential store' : 'store key '.PastedSecretShape::displayName($this->storeKey),
            TokenSource::TokenFile => 'single token file',
            TokenSource::Ambient => 'GH_TOKEN',
            null => 'no token source',
        };
    }

    public function ok(): bool
    {
        return $this->token !== null;
    }
}
