<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\RefusalContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * The bridge's half of releasing the seat owner tag (toolkit README § "The seat owner tag",
 * card#9436): once a bridge move INTO a terminal stage has LANDED, a separate `{tags}` write
 * removes every `owner:*` tag, so a finished card stops holding a seat (DL-386).
 *
 * ⭐ A SEPARATE WRITE, NEVER A FIELD ON THE MOVE. kanban authorizes a PATCH whose sole key is
 * `workflow_stage_id` as `task.move` and any other key set as `task.update` (kanban DL-204), so
 * a `{workflow_stage_id, tags}` PATCH needs both and a move-only token would lose the MOVE. As
 * its own write, the move keeps the permission it always needed and a token without
 * `task.update` loses only the clear, loudly.
 *
 * ⭐ THE TAG LIST COMES FROM A READ TAKEN HERE, immediately before the write, on every mover:
 * kanban replaces `tags` wholesale and offers no conditional write, so the GET→PATCH window
 * below is the one race the clear accepts, and it is the same window whichever mover called.
 * A list carried from the mover's own earlier read (a board scan, a long GitHub loop) would
 * widen it to that whole span.
 *
 * Which stage is terminal is the CALLER's to establish ({@see WritebackMapping::isTerminalStage}
 * for the PR-outcome movers, the configured terminal for the coord-card close); every mover that
 * can move a card into a terminal stage calls {@see clearAfterTerminalMove} after its move, and
 * `TerminalMoverOwnerClearCoverageTest` re-derives that population from `app/` on every run.
 */
final class OwnerTag
{
    public const PREFIX = 'owner:';

    /** The fresh row's tag list is not readable in full ({@see CardTags::readable}). */
    public const REASON_TAGS_UNREADABLE = 'owner_tag_not_cleared_tags_unreadable';

    /** A 5xx or a transport failure on the fresh read or on the tag write. */
    public const REASON_TRANSIENT = 'owner_tag_not_cleared_transient';

    /**
     * Remove every `owner:*` tag from a card whose move into a terminal stage has already
     * landed. Never throws for a kanban failure and never touches the stage: every way the clear
     * can fail leaves the card moved, the claim on it, and the operator told.
     *
     * ⚑ NOT RETRIED. A redelivery of the event finds the card already in its stage and writes
     * nothing, so a clear that failed here stays failed until someone removes the tag; that is
     * why every failure arm alerts rather than throwing for a retry that would never reach it.
     */
    public static function clearAfterTerminalMove(
        WritebackAlertNotifier $alerts,
        KanbanClient $client,
        WritebackMapping $mapping,
        string $arm,
        int $cardId,
        string $repo,
        string $outcome,
        ?int $issueNumber = null,
    ): void {
        $context = ['card_id' => $cardId, 'repo' => $repo] + ($issueNumber === null ? [] : ['issue' => $issueNumber]);

        try {
            $card = $client->getCard($cardId);
        } catch (RequestException|ConnectionException $e) {
            self::reportFailure($alerts, $e, 'read', $arm, $context, $cardId, $repo, $outcome, $issueNumber);

            return;
        }

        if (MappedBoardGuard::refuses($alerts, $card, $mapping, $arm.' (owner-tag clear)', $cardId, $repo, $outcome, $issueNumber)) {
            return;
        }

        $tags = CardTags::readable($card);
        if ($tags === null) {
            $alerts->warnAndNotify(
                'owner_tag.tags_unreadable',
                "{$arm}: the card moved, but its tag list is not readable in full, so its owner: tag was NOT cleared — writing a list built from what could be read would delete tags nobody saw",
                $context,
                $repo, $outcome, $cardId, self::REASON_TAGS_UNREADABLE, $issueNumber,
            );

            return;
        }

        $kept = array_values(array_filter($tags, static fn (string $tag): bool => ! str_starts_with($tag, self::PREFIX)));
        if (count($kept) === count($tags)) {
            return;
        }

        try {
            $client->patchCard($cardId, ['tags' => $kept]);
        } catch (RequestException|ConnectionException $e) {
            self::reportFailure($alerts, $e, 'write', $arm, $context, $cardId, $repo, $outcome, $issueNumber);

            return;
        }

        Log::info("{$arm}: cleared the card's owner: tag after its terminal move", ['catalog_id' => 'owner_tag.cleared'] + $context
            + ['removed' => array_values(array_diff($tags, $kept))]
            + MappedBoardGuard::boardContext($card, $mapping));
    }

    /**
     * @param  'read'|'write'  $step
     * @param  array<string, mixed>  $context
     */
    private static function reportFailure(
        WritebackAlertNotifier $alerts,
        RequestException|ConnectionException $e,
        string $step,
        string $arm,
        array $context,
        int $cardId,
        string $repo,
        string $outcome,
        ?int $issueNumber,
    ): void {
        if ($e instanceof RequestException && RefusalContext::isPermanent($e)) {
            // The id was established on the mapped board before the move landed, so a 403 on
            // the READ names this token's scope, never a foreign card id.
            $reason = $step === 'read'
                ? RefusalContext::readReason('owner_tag_not_cleared_read', $e, foreignIdExcluded: true)
                : RefusalContext::writeReason('owner_tag_not_cleared_write', $e);
            $detail = RefusalContext::from($e);
        } else {
            $reason = self::REASON_TRANSIENT;
            $detail = $e instanceof RequestException ? RefusalContext::from($e) : ['error' => RedactedErrorText::of($e)];
        }

        $alerts->warnAndNotify(
            'owner_tag.clear_failed',
            "{$arm}: the card moved, but the {$step} that clears its owner: tag failed, so the claim stays on the card — the move stands and the clear is not retried (see `body` / `error`)",
            $context + $detail,
            $repo, $outcome, $cardId, $reason, $issueNumber,
        );
    }
}
