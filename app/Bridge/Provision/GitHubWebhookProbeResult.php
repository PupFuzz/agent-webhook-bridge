<?php

namespace App\Bridge\Provision;

/**
 * The result of {@see GitHubWebhookProbe::probe}: a {@see GitHubWebhookProbeKind} plus the
 * fields that case carries (card#9150).
 *
 * ⛔ IT CARRIES NO URL FROM THE UPSTREAM ANSWER, and that omission is the fleet-leak
 * boundary rather than an oversight. The repo's hook list holds every OTHER install's
 * receiver endpoint; the match is made inside `GitHubReadClient::hasRepoWebhookFor` and only
 * a verdict comes out, so no consumer of this object can put another install's endpoint into
 * an operator log, a finding or a traceback even by accident.
 *
 * Non-throwing, for the reason {@see GitHubRepoProbeResult} is: the consumer layers its own
 * severity posture over one resolve+read+classify decision.
 */
final class GitHubWebhookProbeResult
{
    private function __construct(
        public readonly GitHubWebhookProbeKind $kind,
        /**
         * The resolved-token source label (GitHubTokenResolver) — set wherever a token
         * resolved, so a diagnostic can name the leg that won without naming its value.
         */
        public readonly ?string $source = null,
        /** The resolver's fail-loud message — {@see GitHubWebhookProbeKind::Unresolvable} only. */
        public readonly ?string $problem = null,
        /** The read's HTTP status — {@see GitHubWebhookProbeKind::Http} only. */
        public readonly ?int $status = null,
        /** The canonical status→operator hint ('' when the status has none) — Http only. */
        public readonly ?string $hint = null,
        /** Why nothing was read — {@see GitHubWebhookProbeKind::Unreadable} only. */
        public readonly ?string $reason = null,
    ) {}

    public static function present(string $source): self
    {
        return new self(GitHubWebhookProbeKind::Present, source: $source);
    }

    public static function absent(string $source): self
    {
        return new self(GitHubWebhookProbeKind::Absent, source: $source);
    }

    public static function unresolvable(string $problem): self
    {
        return new self(GitHubWebhookProbeKind::Unresolvable, problem: $problem);
    }

    public static function http(int $status, string $hint, string $source): self
    {
        return new self(GitHubWebhookProbeKind::Http, source: $source, status: $status, hint: $hint);
    }

    public static function unreadable(string $reason, string $source): self
    {
        return new self(GitHubWebhookProbeKind::Unreadable, source: $source, reason: $reason);
    }
}
