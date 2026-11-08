<?php

namespace App\Bridge\Writeback;

/**
 * A GitHub WRITE a runtime leg makes with its repo's token, as
 * {@see GitHubTokenFileConsumer::fileTokenWrites()} declares it, with the thread kinds it lands on.
 * The two need different things: a comment needs the token's write for the thread's kind; a label
 * needs that AND an account role of at least Triage on the repo — so `bridge:check` judges each on
 * its own evidence, on the kinds it writes to.
 */
enum GitHubWriteLeg
{
    /** {@see GitHubWriteClient::createIssueComment()} on a pull request — the correlation comment. */
    case PullRequestComment;

    /** {@see GitHubWriteClient::addLabels()} on the thread a comment was made on — issue or pull request. */
    case Label;

    /** What the write does, as a finding names it. */
    public function describe(): string
    {
        return match ($this) {
            self::PullRequestComment => 'create pull-request comments',
            self::Label => 'add labels to issues and pull requests',
        };
    }

    /** What the write needs — GitHub's REST reference for each endpoint. */
    public function permission(): string
    {
        return match ($this) {
            self::PullRequestComment => 'Pull requests WRITE',
            self::Label => 'Issues WRITE and Pull requests WRITE, and an account role of at least Triage on the repo',
        };
    }

    /** @return list<GitHubThreadKind> the kinds of thread the write lands on */
    public function threadKinds(): array
    {
        return match ($this) {
            self::PullRequestComment => [GitHubThreadKind::PullRequest],
            self::Label => [GitHubThreadKind::Issue, GitHubThreadKind::PullRequest],
        };
    }
}
