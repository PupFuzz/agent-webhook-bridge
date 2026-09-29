<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Writeback\KanbanFieldLimits;

/**
 * ONE caller-named tag, checked before it becomes a kanban `tags:"…"` search term — the rule every
 * read on this door that searches by a tag the caller names applies (extracted from
 * {@see BoardMyCardsTool}'s `tag` read at its second caller, `board_search`, card#10832). Presence,
 * emptiness and type are the calling tool's; this owns what may be inside a present, non-empty tag.
 *
 * ⛔ `"` AND `*` ARE REFUSED because they change what kanban's `tags:"…"` term means: a quote ends
 * the term early, and `*` turns an exact match into a glob, which would make a tag filter a
 * whole-board read.
 *
 * ⛔ `%` IS REFUSED because kanban escapes the LIKE wildcards of an exact tag match only from
 * v0.36.0 (`LikePattern::escape`). Before it, `%` matches every tagged card on the board. `_` is the
 * other wildcard and is NOT refused: agent names carry it ({@see AgentNameShape}), so `created-by:`
 * and `idem:` tags do. From kanban v0.36.0 both match literally; before it a `_` matches any one
 * character, which widens the read to tags differing there, never to the board.
 *
 * ⛔ A TAG KANBAN STORES ESCAPED IS REFUSED, NEVER ANSWERED AS AN EMPTY RESULT. Kanban casts `tags`
 * to `array`, which Laravel stores through `json_encode` with no flags, and a kanban before v0.46.0
 * (its DL-271, source-read) matched `tags LIKE '%"<tag>"%'` over that stored text. A character the
 * encoding rewrites — a control byte 0x00–0x1F, `"`, `/`, `\`, or any byte ≥ 0x80 (every non-ASCII
 * character; invalid UTF-8 fails the encode) — so matches no card there, even one carrying it, and
 * the answer is a well-formed empty one. From v0.46.0 kanban matches element-wise and would find
 * such a tag; the refusal is kept because the bridge does not read which kanban answers, and a
 * refusal is the failure direction that cannot pass for an empty board. The check IS that
 * encoding, so it cannot drift from a hand-kept list. Kanban's side: kanban card#9522.
 */
final class BoardTagTerm
{
    /** Refuse `$tag` (already trimmed and non-empty) when kanban's exact tag match cannot honour it. */
    public static function check(string $tag, string $tool, string $arg): void
    {
        if (strpbrk($tag, '"*') !== false) {
            throw new ToolRefusalException("{$tool}: `{$arg}` may not contain `\"` or `*`. A tag filter matches ONE tag exactly; a quote would break the board search term and `*` would turn it into a wildcard over every lane.");
        }
        if (str_contains($tag, '%')) {
            throw new ToolRefusalException("{$tool}: `{$arg}` may not contain `%`. A kanban older than v0.36.0 reads `%` in an exact tag match as a wildcard, which would widen this read to other tags — `%` alone matches every tagged card on your board.");
        }
        if (json_encode($tag) !== '"'.$tag.'"') {
            throw new ToolRefusalException("{$tool}: `{$arg}` may not contain a control character, `/`, `\\` or any non-ASCII character. Kanban stores tags as JSON, which writes each of those as an escape, and its exact tag match compares against that stored text — so no card would match, even one carrying the tag, and the answer would look like an empty one. No spelling of such a tag can be matched by this read.");
        }
        if (mb_strlen($tag) > KanbanFieldLimits::TAG_MAX) {
            throw new ToolRefusalException("{$tool}: `{$arg}` is ".mb_strlen($tag).' characters — kanban accepts at most '.KanbanFieldLimits::TAG_MAX.' per tag, so no card can carry it.');
        }
    }
}
