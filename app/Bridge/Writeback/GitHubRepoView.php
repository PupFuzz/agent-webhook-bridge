<?php

namespace App\Bridge\Writeback;

/**
 * What one `GET /repos/{repo}` told the token that made it ({@see GitHubReadClient::probeRepo()}):
 * the repo's default branch and visibility, and the token's classic scopes. Each is null where the
 * answer did not carry it — `$scopes` is null for a fine-grained or installation token, whose
 * permissions no header reports (card#12968).
 */
final class GitHubRepoView
{
    /**
     * @param  ?list<string>  $scopes
     */
    public function __construct(
        public readonly ?string $defaultBranch,
        public readonly ?bool $private,
        public readonly ?array $scopes,
    ) {}
}
