<?php

namespace App\Bridge\Writeback;

/**
 * What {@see GitHubRepoProbe::access()} measured for one repo with the token the receiver resolves
 * for it: which source answered ({@see TokenResolution::sourceLabel()}, never a path or the token)
 * and one verdict per leg asked, with the `subject` it answers for — `'repo'`, a
 * {@see GitHubReadLeg} or a {@see GitHubWriteLeg} (card#12968).
 */
final class GitHubRepoAccess
{
    /**
     * @param  list<array{subject: string|GitHubReadLeg|GitHubWriteLeg, what: string, needs: string, access: GitHubAccess, answer: string}>  $legs
     */
    public function __construct(
        public readonly string $source,
        public readonly array $legs,
    ) {}

    /**
     * The legs with verdict `$access`.
     *
     * @return list<array{subject: string|GitHubReadLeg|GitHubWriteLeg, what: string, needs: string, access: GitHubAccess, answer: string}>
     */
    public function with(GitHubAccess $access): array
    {
        return array_values(array_filter($this->legs, fn (array $leg): bool => $leg['access'] === $access));
    }
}
