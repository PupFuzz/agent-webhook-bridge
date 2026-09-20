<?php

namespace App\Bridge\Writeback;

/**
 * The PARENT-CARD refusal (card#9929, rt#522 ask 3): a card carrying the `program` tag names
 * SEVERAL LEGS, not one deliverable, so no single pull request's outcome may speak for it —
 * and the same compare-and-report pairing {@see PinGuard} and {@see MappedBoardGuard} own.
 *
 * ⛔ WHAT GOES WRONG WITHOUT IT, which is not the same defect the pin already covers. The
 * correlation path resolves `payload.card_id` out of a `card#NNNN` in author-controlled text
 * and then drives that card's STAGE and its correlation REFS from one pull request. Point
 * that at a parent and the parent reports a state no leg reached: an `opened` moves it to
 * In-Review on the first leg's PR, a `merged` takes it TERMINAL while the remaining legs are
 * unbuilt, and the `pr_number` / `pr_url` stamp binds the whole program to one leg's PR — so
 * every consumer deriving the program's state (and every later leg's PR, which now collides
 * with a ref the parent already answers) reads a false correlation. The board asserts work is
 * finished that nobody has done, which is the direction that does not self-correct: a card
 * already terminal is not moved forward again by the legs that follow.
 *
 * ⛔ IT REFUSES THE STAMP, NOT ONLY THE MOVE, AND THAT IS THE WHOLE PLACEMENT ARGUMENT.
 * Pinning a parent (`no-automove` / a `block_reason`) is the nearest thing an operator can do
 * today and it covers HALF the defect: {@see PinGuard} is consulted in
 * `KanbanMoveCardHandler::handle()` on an arm that deliberately STAMPS before returning (the
 * pin governs the card's STAGE, not its refs — see that consult's own comment), so a pinned
 * parent still has a leg's PR refs written onto it. This consult therefore sits UPSTREAM of
 * every stamp call site rather than beside the pin, where it would inherit exactly that.
 *
 * ⚑ THE TAG IS AN OPERATOR CONVENTION AND THIS CLASS IS ITS ONLY DECLARATION ON THIS SIDE OF
 * THE SEAM. The refusal is a cross-repo guarantee — the far end (the coordination framework's
 * `kanban-prs-sync.py` defer pre-pass) implements the same rule independently, and the two
 * share no runtime, so the only thing that keeps them agreeing is the SPELLING of
 * {@see TAG}. It is published for that reader in `docs/kanban-integration-contract.md`
 * § 3 (Load-bearing invariants), read out of this constant by
 * `Tests\Feature\Writeback\ProgramCardGuardTest` rather than restated there by hand. (Named,
 * not `{@see}`-linked: pint turns a docblock FQCN into a real `use`, and `app/` does not
 * import from `tests/` — the same note `PrCorrelationCommenter` carries.)
 * ⛔ WHAT THIS SIDE CANNOT VERIFY, stated rather than assumed: nothing here can establish that
 * the far end uses the same spelling, and nothing on either side establishes that a parent
 * card actually CARRIES the tag — an untagged parent is invisible to this guard and to the
 * far end alike, which is the residual card#9929 records and not something this check closes.
 *
 * ⚑ EXACT MATCH, like `no-automove`'s: kanban stores tags verbatim (it normalizes neither case
 * nor whitespace), so `Program` and `program ` are not this tag and are not refused. The
 * predicate is the one written here; a second spelling would be a second rule.
 *
 * ⚑ A ROW CARRYING NO READABLE `tags` ANSWERS "NOT A PARENT" — degrading toward WRITING, the
 * same direction {@see PinGuard::isPinned} degrades in, and deliberately NOT detected a second
 * time here: this consult returns false on such a row, so the delivery goes on to reach
 * {@see PinGuard}'s own degraded-row detector on the very same card. A detector here would
 * emit a second line about one read.
 */
final class ProgramCardGuard
{
    /**
     * The tag that marks a card as a PARENT naming several legs. ⛔ THE ONE PLACE THIS STRING
     * IS WRITTEN in `app/` — the contract doc quotes it from here through a test, and the far
     * end of the seam has only this spelling to match.
     */
    public const TAG = 'program';

    /** The reason code every parent-card refusal shares (third element of the alert dedup tuple). */
    public const REASON = 'program_parent_card';

    /**
     * @param  array<string, mixed>  $card  as returned by {@see KanbanClient::getCard()}
     */
    public static function isProgramParent(array $card): bool
    {
        return in_array(self::TAG, PinGuard::tags($card), true);
    }

    /**
     * The predicate AND its refusal report: true when $card is a program PARENT and the caller
     * must write nothing at all — no stage, no correlation ref; false when it may proceed.
     *
     * Permanent refusal (alert + log + no-op, never a 5xx retry): a parent card cannot become
     * a leg by being retried. The report is inside the primitive for the reason
     * {@see PinGuard::refuses}' is (DL-274's eleven-of-twelve): a consult-then-report shape
     * spelled at each site is one that gets minted with a different reason code, a different
     * log level, or no live signal at all.
     *
     * $write names WHICH write was refused, because this arm refuses two of them at once.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $logContext  arm-specific context; `card_id` and `repo` are added here
     */
    public static function refuses(
        WritebackAlertNotifier $alerts,
        array $card,
        string $arm,
        string $write,
        int $cardId,
        string $repo,
        string $outcome,
        array $logContext = [],
    ): bool {
        if (! self::isProgramParent($card)) {
            return false;
        }

        $alerts->warnAndNotify(
            "{$arm}: {$write} REFUSED — card {$cardId} carries the `".self::TAG.'` tag, so it is a PARENT naming several legs '
            .'and not one deliverable; one pull request cannot speak for it. Nothing was written: not the stage, not a '
            .'correlation ref. The pull request should cite the LEG card it finishes instead, and the parent should be '
            .'moved when every leg is done.',
            ['card_id' => $cardId, 'repo' => $repo, 'program_tag' => self::TAG] + $logContext,
            $repo, $outcome, $cardId, self::REASON,
        );

        return true;
    }
}
