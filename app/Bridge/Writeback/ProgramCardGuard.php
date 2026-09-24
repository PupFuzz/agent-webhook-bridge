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
 * ⚑ THE TAG IS AN OPERATOR CONVENTION AND THIS CLASS IS ITS ONLY DECLARATION ANYWHERE. The
 * refusal was designed as a cross-repo guarantee, but ⛔ NO FAR END IMPLEMENTS IT TODAY:
 * measured 2026-09-20 over every coordination-framework version published on the reference
 * install (plugin cache 0.49.0–0.55.0, marketplace checkout `b6cb4ff` = v0.55.0), there is no
 * `program`-tag rule anywhere in the plugin, and the `kanban-prs-sync.py` it ships defers on
 * DL / card-id resolution and reads no tag at any version. (The instrument discriminates: the
 * same search finds `no-automove` published there in four places.) So the spelling published
 * in `docs/kanban-integration-contract.md` § 3 (Load-bearing invariants) is a REQUEST for a
 * counterpart, not a description of one, and that row says so in those terms — read it as the
 * declaration and this class as the only enforcement. The constant is read out of here by
 * `Tests\Feature\Writeback\ProgramCardGuardTest` rather than restated there by hand, so the
 * day a far end does implement it, the spelling it matches cannot have drifted from
 * {@see TAG}. (Named, not `{@see}`-linked: pint turns a docblock FQCN into a real `use`, and
 * `app/` does not import from `tests/` — the same note `PrCorrelationCommenter` carries.)
 * ⛔ WHAT THIS SIDE CANNOT VERIFY, stated rather than assumed: nothing here can establish that
 * a future far-end implementation uses the same spelling, and nothing on either side
 * establishes that a parent card actually CARRIES the tag — an untagged parent is invisible to
 * this guard, which is the residual card#9929 records and not something this check closes.
 *
 * ⛔ EVERY TERMINAL-STAGE WRITER IN THIS REPO CONSULTS IT — AND THE REFUSAL IS STILL NOT
 * REPO-WIDE (card#10068). Shipped with ONE caller, which is what card#10068 reports: a
 * predicate enforced at one writer of a terminal stage is not enforced, and the writer that
 * actually fires on this install's releases was not the one guarded. The consults now sit on
 * the PR-event path, the release promote scan, the `bridge:reconcile --fix` plan and the
 * coordination-card close. ⛔ WHICH WRITERS THOSE ARE IS NOT A LIST TO TRUST HERE: the
 * population is DERIVED every run by `Tests\Feature\Writeback\ProgramParentMoveCoverageTest`
 * over every `->moveCard(` site in `app/`, each of which carries a ruling — consulted, or what
 * it moves and why it does not — so a new writer arrives as a red test rather than as a
 * silence. (Named, not `{@see}`-linked, for the pint reason this docblock already carries
 * below.) A roster copied into a comment is true until the next writer lands (card#10063), and
 * this paragraph's own previous version is the worked example.
 * ⛔ WHAT IS STILL UNREACHED, stated rather than implied away: the TOOLKIT's
 * `bin/promote-released-cards` is a second implementation of the same Shipped→Released move,
 * in another repo, racing this one on every release — nothing here reaches it; and kanban's own
 * UI drag and HTTP API reach no bridge-side predicate at all. `docs/writeback.md` § Parent
 * cards owns that boundary for an operator.
 *
 * ⚑ EXACT MATCH, like `no-automove`'s: kanban stores tags verbatim (it normalizes neither case
 * nor whitespace), so `Program` and `program ` are not this tag and are not refused. The
 * predicate is the one written here; a second spelling would be a second rule.
 *
 * ⚑ A ROW CARRYING NO READABLE `tags` ANSWERS "NOT A PARENT" — degrading toward WRITING, the
 * same direction {@see PinGuard::isPinned} degrades in, and deliberately not detected a second
 * time here: a delivery that goes on to reach {@see PinGuard}'s own degraded-row detector is
 * already reported there, and a detector here would emit a second line about one read.
 * ⛔ WHICH IS NOT EVERY DELIVERY, AND THE OMISSION IS BOUNDED ON TWO DIMENSIONS, NOT ONE. The
 * first is the PATH, and it is the one a row-shape argument cannot see. That detector runs
 * inside the pin consult, so ONLY a delivery that reaches the pin consult reaches it — and in
 * `KanbanMoveCardHandler` several arms return before it (the DL-270 uncorroborated arm, which
 * writes a card NOTE, and the already-in-stage self-heal, which STAMPS) while the DL-194 unpark
 * and DL-195 revive overrides skip the consult by their own predicate. On every one of those a
 * degraded row is read through this guard and reported by NOTHING; the self-heal arm is the
 * routine redelivery case, so it is not an exotic one.
 * ⛔ The second is the ROW SHAPE, and it bites even on the arm that does reach the detector.
 * `PinGuard::reportUnreadableRow()` fires only when `block_reason` AND `tags` are BOTH absent
 * (its own docblock owns why). A row carrying `block_reason` but no `tags` key — or `tags`
 * present as a non-list, which {@see PinGuard::tags} maps to `[]` — makes this predicate answer
 * "not a parent" and reaches no detector at all. Either way the parent is moved and stamped
 * silently. For the pin that shape is survivable, `block_reason` still being readable; here
 * `tags` is the ONLY input, so the blind spot is total. Reporting the `tags`-unreadable shape
 * this guard alone depends on is a separate call, filed on card#9929 and not taken here.
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
     * $issueNumber carries the GitHub issue a refusal belongs to on the arms that have one
     * ({@see PinGuard::refuses}' final parameter, and the same default): the coordination-card
     * arm is keyed by issue, and a refusal an operator cannot trace back to one is half a
     * report. The PR-event and release-scan arms have no issue and pass nothing.
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
        ?int $issueNumber = null,
    ): bool {
        if (! self::isProgramParent($card)) {
            return false;
        }

        $alerts->warnAndNotify(
            'program_card_guard.parent_card',
            "{$arm}: {$write} REFUSED — card {$cardId} carries the `".self::TAG.'` tag, so it is a PARENT naming several legs '
            .'and not one deliverable; one pull request cannot speak for it. Nothing was written: not the stage, not a '
            .'correlation ref. The pull request should cite the LEG card it finishes instead, and the parent should be '
            .'moved when every leg is done.',
            ['card_id' => $cardId, 'repo' => $repo, 'program_tag' => self::TAG] + $logContext,
            $repo, $outcome, $cardId, self::REASON, $issueNumber,
        );

        return true;
    }
}
