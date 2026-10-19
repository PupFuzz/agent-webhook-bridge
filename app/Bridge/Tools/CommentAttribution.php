<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\RecipientAddressing;

/**
 * THE BRIDGE'S ATTRIBUTION LINE ON A CARD COMMENT — written by {@see BoardCommentCardTool} and read
 * back by {@see BoardGetCardCommentsTool}, so the format has one owner and the reader is the exact
 * inverse of the writer (card#12010).
 *
 * Every seat writes through the one writeback user, so a comment's kanban author does not say which
 * seat wrote it; the bridge puts the seat on the body's FIRST line instead, followed by a blank line,
 * then the caller's text.
 *
 * ⚠ THE READ IS A CLAIM IN THE TEXT, NOT AN AUTHENTICATION. Anyone who can comment on the board —
 * a human with their own kanban account included — can open a comment with the same line. What the
 * bridge itself wrote is a comment whose kanban author is the writeback user AND whose first line
 * parses here; the reader reports both and does not combine them.
 *
 * ⛔ NOT {@see RecipientAddressing::author()}. That one serves the coordination
 * protocol: it takes the first `FROM:` line ANYWHERE in a body, lowercased and tokenised. This one
 * reads only the line the bridge writes, at the start of the body, byte for byte — a `FROM:` further
 * down is the caller's own text.
 */
final class CommentAttribution
{
    /** The exact bytes the bridge writes before the caller's text. */
    public static function prefix(string $agentName): string
    {
        return "FROM: {$agentName}\n\n";
    }

    /**
     * The seat named by a body that opens with {@see prefix()}, or null. Agent names carry no
     * whitespace, so a first line with any after `FROM: ` is not one the bridge wrote.
     */
    public static function writer(string $body): ?string
    {
        return preg_match('/\AFROM: (\S+)\n\n/', $body, $m) === 1 ? $m[1] : null;
    }
}
