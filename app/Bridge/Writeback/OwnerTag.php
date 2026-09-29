<?php

namespace App\Bridge\Writeback;

/**
 * The retired seat owner tag, `owner:<project>/<seat>` — kept as a READ-SIDE vocabulary only
 * (card#10869). Card ownership is the kanban ASSIGNEE, one kanban user per agent (toolkit
 * README § "The card owner — the kanban assignee", card#10868): nothing writes this tag any
 * more and nothing removes it — the bridge's own post-terminal-move clear (DL-386) is retired,
 * because a finished card keeps the record of who did the work, and a tag removed on finish is
 * a tag-only card's only such record, gone before `kbcard owner-migrate` could turn it into an
 * assignee.
 *
 * It is still READ, as the migration fallback: on a card with no assignee, another seat's tag
 * names its holder. Those readers are deleted at migration step 3, once `kbcard owner-migrate`'s
 * `counts.tag_only` reads 0 on every board; this class goes with them.
 */
final class OwnerTag
{
    /** The toolkit's spelling of "is this an owner tag" (`KB_JQ_OWNER`): the prefix, case-sensitive. */
    public const PREFIX = 'owner:';

    public static function is(string $tag): bool
    {
        return str_starts_with($tag, self::PREFIX);
    }
}
