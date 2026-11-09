<?php

namespace App\Bridge\Writeback;

/**
 * A per-repo GitHub READ a runtime leg makes with its repo's token, as
 * {@see GitHubTokenFileConsumer::fileTokenReads()} declares it, so `bridge:check` can ask that token
 * for exactly the reads its legs depend on (card#12968). A repo's `write_token_path` answers every
 * read for that repo, not only its writes, so a write token that lacks a read permission breaks the
 * leg that reads.
 */
enum GitHubReadLeg
{
    /** {@see GitHubReadClient::hasIssueCommentStartingWith()} — the correlation comment's dedupe read. */
    case IssueComments;

    /** {@see GitHubReadClient::getPull()}. */
    case Pulls;

    /** {@see GitHubReadClient::compareStatus()}. */
    case Compare;

    /** What the read does, as a finding names it. */
    public function describe(): string
    {
        return match ($this) {
            self::IssueComments => 'read issue and pull-request comments',
            self::Pulls => 'read pull requests',
            self::Compare => 'compare commits',
        };
    }

    /** The fine-grained permission the read needs (GitHub's REST reference for each endpoint). */
    public function permission(): string
    {
        return match ($this) {
            self::IssueComments => 'Issues or Pull requests READ',
            self::Pulls => 'Pull requests READ',
            self::Compare => 'Contents READ',
        };
    }
}
