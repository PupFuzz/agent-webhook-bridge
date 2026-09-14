<?php

namespace App\Bridge\Writeback;

/**
 * The `owner:<project>/<seat>` claim a seat stamps on a card it starts, and the bridge's half of
 * releasing it: a bridge move INTO a terminal stage sends the card's tags minus every `owner:*`
 * tag in the same PATCH as the stage, so a finished card stops holding a seat.
 *
 * Which stage is terminal is the CALLER's to establish (each mover knows its own terminal —
 * {@see WritebackMapping::isTerminalStage} for the PR-outcome movers, the configured terminal for
 * the coord-card move); this answers only what the terminal move sends beside the stage.
 */
final class OwnerTag
{
    public const PREFIX = 'owner:';

    /**
     * The tag list a terminal move sends beside its stage, or null to send no `tags` at all.
     *
     * Null in two cases, and only one of them is quiet: the card carries no `owner:*` tag (no
     * write is owed), or its tag list is not readable in full ({@see CardTags::readable}). The
     * second REPORTS here — the move still lands, the claim is left on the card, and the operator
     * is told so — because replacing the list with one built from what could be read would delete
     * tags nobody saw.
     *
     * @param  array<string, mixed>  $card  the row the caller read before deciding to move
     * @return list<string>|null
     */
    public static function tagsForTerminalMove(WritebackAlertNotifier $alerts, array $card, string $caller, int $cardId, string $repo, string $outcome, ?int $issueNumber = null): ?array
    {
        $tags = CardTags::readable($card);
        if ($tags === null) {
            $alerts->warnAndNotify(
                "{$caller}: the card's tag list is not readable in full, so its owner: tag was NOT cleared — the terminal move is sent without `tags`, and any owner: claim stays on the card",
                ['card_id' => $cardId],
                $repo, $outcome, $cardId, 'owner_tag_not_cleared_tags_unreadable', $issueNumber,
            );

            return null;
        }

        $kept = array_values(array_filter($tags, static fn (string $tag): bool => ! str_starts_with($tag, self::PREFIX)));

        return count($kept) === count($tags) ? null : $kept;
    }
}
