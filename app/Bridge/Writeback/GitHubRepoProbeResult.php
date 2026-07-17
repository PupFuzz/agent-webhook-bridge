<?php

namespace App\Bridge\Writeback;

/**
 * The result of {@see GitHubRepoProbe::probe}: a {@see GitHubRepoProbeKind} plus the
 * fields that case carries. Deliberately non-throwing so each consumer can layer its
 * own error posture — bridge:reconcile maps a problem to a loud non-zero exit,
 * bridge:check to a preflight warn — over ONE resolve+probe+classify decision that
 * therefore can't drift between them (canon #5, DL-185/186).
 */
final class GitHubRepoProbeResult
{
    private function __construct(
        public readonly GitHubRepoProbeKind $kind,
        /** The read client — set for {@see GitHubRepoProbeKind::Ok} only. */
        public readonly ?GitHubReadClient $client = null,
        /**
         * The resolved-token source label (GitHubTokenResolver) — set for Ok / Http /
         * Network (a token resolved, so the leg that won can be named in diagnostics).
         */
        public readonly ?string $source = null,
        /** The resolver's fail-loud message — set for {@see GitHubRepoProbeKind::Unresolvable} only. */
        public readonly ?string $problem = null,
        /** The probe's HTTP status — set for {@see GitHubRepoProbeKind::Http} only. */
        public readonly ?int $status = null,
        /** The canonical status→operator hint ('' when the status has none) — Http only. */
        public readonly ?string $hint = null,
        /** The connection exception message — set for {@see GitHubRepoProbeKind::Network} only. */
        public readonly ?string $networkMessage = null,
    ) {}

    public static function resolved(GitHubReadClient $client, string $source): self
    {
        return new self(GitHubRepoProbeKind::Ok, client: $client, source: $source);
    }

    public static function unresolvable(string $problem): self
    {
        return new self(GitHubRepoProbeKind::Unresolvable, problem: $problem);
    }

    public static function http(int $status, string $hint, string $source): self
    {
        return new self(GitHubRepoProbeKind::Http, source: $source, status: $status, hint: $hint);
    }

    public static function network(string $message, string $source): self
    {
        return new self(GitHubRepoProbeKind::Network, source: $source, networkMessage: $message);
    }

    public function ok(): bool
    {
        return $this->kind === GitHubRepoProbeKind::Ok;
    }
}
