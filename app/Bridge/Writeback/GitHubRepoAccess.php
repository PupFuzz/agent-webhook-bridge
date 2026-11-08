<?php

namespace App\Bridge\Writeback;

/**
 * What {@see GitHubRepoProbe::access()} measured for one repo with the token the receiver resolves
 * for it: which source answered ({@see TokenResolution::sourceLabel()}, never a path or the token)
 * and one {@see GitHubProbedLeg} per leg asked (card#12968).
 */
final class GitHubRepoAccess
{
    /**
     * @param  list<GitHubProbedLeg>  $legs
     */
    public function __construct(
        public readonly string $source,
        public readonly array $legs,
    ) {}

    /**
     * The legs with verdict `$access`.
     *
     * @return list<GitHubProbedLeg>
     */
    public function with(GitHubAccess $access): array
    {
        return array_values(array_filter($this->legs, fn (GitHubProbedLeg $leg): bool => $leg->access === $access));
    }
}
