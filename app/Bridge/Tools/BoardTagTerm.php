<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Writeback\KanbanFieldLimits;

/**
 * ONE caller-named tag, checked before it becomes a kanban `tags:"…"` search term (extracted from
 * {@see BoardMyCardsTool}'s `tag` read at its second caller, `board_search`, card#10832). Presence,
 * emptiness and type are the calling tool's; this owns what may be inside a present, non-empty tag.
 * TWO RULES, and which one a read takes depends on the kanban it can be answered by:
 *
 * {@see check} — THE TERM-SYNTAX RULE, every caller. `"` and `*` change what kanban's `tags:"…"` term
 * means: a quote ends the term early, and `*` turns an exact match into a glob, which would make a tag
 * filter a whole-board read. A tag over {@see KanbanFieldLimits::TAG_MAX} can be carried by no card.
 *
 * {@see checkForAnyKanban} — the term-syntax rule PLUS THE OLD-KANBAN RULE, for a read with no kanban
 * version floor (`board_my_cards`' `tag`). It refuses what an OLD kanban cannot match exactly:
 *  - `%`, because kanban escapes the LIKE wildcards of an exact tag match only from v0.36.0
 *    (`LikePattern::escape`); before it `%` matches every tagged card on the board. `_` is the other
 *    wildcard and is NOT refused: agent names carry it ({@see AgentNameShape}), so `created-by:` and
 *    `idem:` tags do; before v0.36.0 it matches any one character, which widens the read to tags
 *    differing there, never to the board.
 *  - a tag kanban STORES ESCAPED. Kanban casts `tags` to `array`, stored through `json_encode` with no
 *    flags, and a kanban before v0.46.0 matched `tags LIKE '%"<tag>"%'` over that stored text, so a
 *    character the encoding rewrites — a control byte 0x00–0x1F, `/`, `\`, or any byte ≥ 0x80 (every
 *    non-ASCII character; invalid UTF-8 fails the encode) — matched no card there, even one carrying
 *    it, as a well-formed empty answer. The check IS that encoding, so it cannot drift from a
 *    hand-kept list. Kanban's side: kanban card#9522.
 *
 * ⚠ From kanban v0.46.0 (its DL-271) an exact tag is matched ELEMENT-WISE over the decoded tags with
 * the pattern escaped by `LikePattern::escape` (source-read at kanban v0.46.0, v0.47.0 and `origin/dev`
 * 54a63399), so every tag the old-kanban rule refuses matches exactly — `%` literally. A read that
 * only answers from kanban v0.47.0 on (`board_search`, which refuses any response without the
 * v0.47.0 parse disclosure) takes {@see check} alone; the widening was operator-approved (DL-437).
 */
final class BoardTagTerm
{
    /** The term-syntax rule: refuse `$tag` (already trimmed and non-empty) when it cannot be one exact `tags:"…"` term. */
    public static function check(string $tag, string $tool, string $arg): void
    {
        self::termSyntax($tag, $tool, $arg);
        self::length($tag, $tool, $arg);
    }

    /** {@see check} plus the old-kanban rule, in the order a caller has always seen the refusals. */
    public static function checkForAnyKanban(string $tag, string $tool, string $arg): void
    {
        self::termSyntax($tag, $tool, $arg);
        if (str_contains($tag, '%')) {
            throw new ToolRefusalException("{$tool}: `{$arg}` may not contain `%`. A kanban older than v0.36.0 reads `%` in an exact tag match as a wildcard, which would widen this read to other tags — `%` alone matches every tagged card on your board.");
        }
        if (json_encode($tag) !== '"'.$tag.'"') {
            throw new ToolRefusalException("{$tool}: `{$arg}` may not contain a control character, `/`, `\\` or any non-ASCII character. Kanban stores tags as JSON, which writes each of those as an escape, and its exact tag match compares against that stored text — so no card would match, even one carrying the tag, and the answer would look like an empty one. No spelling of such a tag can be matched by this read.");
        }
        self::length($tag, $tool, $arg);
    }

    private static function termSyntax(string $tag, string $tool, string $arg): void
    {
        if (strpbrk($tag, '"*') !== false) {
            throw new ToolRefusalException("{$tool}: `{$arg}` may not contain `\"` or `*`. A tag filter matches ONE tag exactly; a quote would break the board search term and `*` would turn it into a wildcard over every lane.");
        }
    }

    private static function length(string $tag, string $tool, string $arg): void
    {
        if (mb_strlen($tag) > KanbanFieldLimits::TAG_MAX) {
            throw new ToolRefusalException("{$tool}: `{$arg}` is ".mb_strlen($tag).' characters — kanban accepts at most '.KanbanFieldLimits::TAG_MAX.' per tag, so no card can carry it.');
        }
    }
}
