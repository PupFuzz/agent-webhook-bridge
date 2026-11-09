<?php

namespace App\Bridge\Writeback;

/**
 * One leg of a {@see GitHubRepoAccess}: what was asked (`$subject` — `'repo'`, a
 * {@see GitHubReadLeg} or a {@see GitHubWriteLeg}), how a finding names it and what it needs, the
 * verdict, and what GitHub answered (naming the thread a write was measured on).
 */
final class GitHubProbedLeg
{
    public function __construct(
        public readonly string|GitHubReadLeg|GitHubWriteLeg $subject,
        public readonly string $what,
        public readonly string $needs,
        public readonly GitHubAccess $access,
        public readonly string $answer,
    ) {}
}
