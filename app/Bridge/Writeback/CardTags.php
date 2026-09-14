<?php

namespace App\Bridge\Writeback;

/**
 * A card row's `tags`, read IN FULL or not at all.
 *
 * kanban replaces `tags` wholesale, so a list the bridge sends is the card's whole tag set
 * afterwards. Composed from a list that is absent, not a list, or holds any non-string entry,
 * such a write silently deletes every tag it could not read. Present-null is kanban's nullable
 * json column answering "no tags", which is a real, empty answer.
 */
final class CardTags
{
    /**
     * @param  array<string, mixed>  $row
     * @return list<string>|null null when the row carries no tag list readable in full
     */
    public static function readable(array $row): ?array
    {
        if (! array_key_exists('tags', $row)) {
            return null;
        }
        $tags = $row['tags'];
        if ($tags === null) {
            return [];
        }
        if (! is_array($tags) || ! array_is_list($tags)) {
            return null;
        }
        $strings = [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                return null;
            }
            $strings[] = $tag;
        }

        return $strings;
    }
}
