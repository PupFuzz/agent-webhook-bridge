<?php

namespace App\Bridge\Writeback;

/**
 * Which kind of GitHub thread a write lands on. GitHub lists the comment and label endpoints under
 * BOTH the Issues and the Pull requests fine-grained permissions, so a token may hold write for one
 * kind and not the other: a write is only measured on the kind it actually lands on.
 */
enum GitHubThreadKind
{
    case PullRequest;
    case Issue;

    /** As a finding names a thread: "#12, a pull request". */
    public function describe(): string
    {
        return match ($this) {
            self::PullRequest => 'a pull request',
            self::Issue => 'an issue',
        };
    }

    public function noun(): string
    {
        return match ($this) {
            self::PullRequest => 'pull request',
            self::Issue => 'issue',
        };
    }
}
