<?php

namespace App\Bridge\Writeback;

/**
 * What one `GET /repos/{repo}` told the token that made it ({@see GitHubReadClient::probeRepo()}):
 * the repo's default branch and visibility, the token's classic scopes, and whether the ACCOUNT's
 * role on the repo is at least Triage. Each is null where the answer did not carry it — `$scopes` is
 * null for a fine-grained or installation token, whose permissions no header reports.
 *
 * ⚠ `$atLeastTriage` is the account's ROLE (`permissions` in the answer), not what a fine-grained
 * token was granted: a token without Issues write on a repo its owner administers still reads
 * `admin: true` there (measured, card#12968). It can rule a label out, never in on its own.
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
        public readonly ?bool $atLeastTriage,
    ) {}
}
