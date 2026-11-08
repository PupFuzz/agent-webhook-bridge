<?php

namespace App\Bridge\Writeback;

/**
 * A GitHub WRITE a runtime leg makes with its repo's token, as
 * {@see GitHubTokenFileConsumer::fileTokenWrites()} declares it. The two need different things:
 * a comment needs the token's Issues or Pull requests WRITE; a label needs that AND an account role
 * of at least Triage on the repo — so `bridge:check` judges each on its own evidence.
 */
enum GitHubWriteLeg
{
    /** {@see GitHubWriteClient::createIssueComment()}. */
    case Comment;

    /** {@see GitHubWriteClient::addLabels()}. */
    case Label;

    /** What the write does, as a finding names it. */
    public function describe(): string
    {
        return match ($this) {
            self::Comment => 'create issue and pull-request comments',
            self::Label => 'add labels to issues and pull requests',
        };
    }

    /** What the write needs — GitHub's REST reference for each endpoint. */
    public function permission(): string
    {
        return match ($this) {
            self::Comment => 'Issues or Pull requests WRITE',
            self::Label => 'Issues or Pull requests WRITE and an account role of at least Triage on the repo',
        };
    }
}
