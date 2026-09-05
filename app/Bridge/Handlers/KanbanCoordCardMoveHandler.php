<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\CoordCardLanePlacement;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\MappedBoardGuard;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\WritebackAlertNotifier;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Move a coordination issue's tracking card in real time (DL-200) — the sibling of
 * {@see KanbanCoordCardHandler}'s create leg (roundtable #18(b)). A closed issue's
 * card concludes into `coord_card_terminal_stage_id`; a reopened issue's card
 * revives; an issue that gains a `stage:*` label is re-laned (card#6393, opt-in).
 *
 * THE LANE (card#6393). Where a lane model is configured, revive and relane both
 * resolve their destination through {@see CoordCardLanePlacement} — the SAME
 * primitive the create leg uses — instead of writing a fixed stage. On a lane-model
 * board the consumer's `kanban-writeback` pass maps a card's lane back onto the
 * issue's `stage:*` label BEFORE its issues-sync runs, so a bridge move that ignores
 * the lane does not merely place the card, it rewrites a written sequencing ruling on
 * the issue. With no `coord_card_lane_stage_ids` the primitive returns the fixed
 * stage and both legs are byte-identical DL-200.
 *
 * THE PARTITION (roundtable #18): this is the real-time PRIMARY, so each consumer's
 * periodic reconcile DEFERS to it and backstops. That makes the bridge a coord-card
 * COLUMN mover — deliberately widening the create leg's original create-only scope.
 *
 * Correlation on the SAME `id:<sid>` TAG the create leg writes, so the two legs need
 * no registry. Absent tag ⇒ nothing to move (never create — create-if-absent is the
 * create family's half of the reopen composition, so exactly one leg ever acts).
 *
 * THE ACTOR-GATE (#18 Q5 — revive, and reused by relane): act IFF the card's current
 * stage was SERVICE-set —
 * `last_stage_move.actor_type === "service"`, an ALLOW-LIST rather than a deny-list of
 * the human value. (kanban's ChangeSource emits exactly `human` for a UI move, `service`
 * for api/system, and `null` on a pre-feature row — so a deny-list would silently revive
 * on null.) Absent / null / malformed / unknown / human ⇒ fail CLOSED. A human who drags a card to the
 * terminal has expressed a closure intent the bridge must never reverse. Revive also
 * requires the card to currently BE in that terminal: a card someone has since moved
 * on is live work, and dragging it back to the revive target is exactly the backward
 * regression DL-163 forbids. Relane reuses the same allow-list against the LANE the
 * card sits in, and additionally refuses any card not currently in a mapped lane —
 * see {@see relaneOne()} for its four gates.
 *
 * A close is unconditional over `user_lanes` — ruled on #18: a human's priority PLACEMENT
 * yields to closure ("close→Done IS the terminal case, both movers agree"). ⛔ That is a
 * ruling about a lane, and until card#8523 it was carried here as though it settled the
 * PIN too: it does not, and the close leg had no actor-gate either, so an `issues.closed`
 * concluded a card carrying a `block_reason` / `no-automove` with nothing between it and
 * the write but the `move_coord_cards` opt-in. Since DL-340 the terminal leg consults
 * {@see PinGuard} — a human hold is not a lane preference, and the operator ruling on
 * card#8523 was that it outranks a close.
 *
 * ⭐ ALL THREE LEGS CONSULT THE PIN SINCE card#8557, and the two that were left out are
 * worth naming because their exclusion was DELIBERATE and still wrong. `revive` and
 * `relane` already refused any card whose current stage was not service-set, and that was
 * read as covering the hold: it does not. Pinning is a field PATCH, so `last_stage_move`
 * stays service-set and a card pinned AFTER the bridge parked it walked through both legs
 * — an operator told the card was frozen, watching a reopen or a label edit move it. The
 * widening changes what the system refuses and was taken to the operator under card#8523's
 * gate rather than settled here.
 *
 * DURABLE, with the writeback's standard transient(5xx → retry) / permanent(4xx → alert
 * + log + no-op) split (DL-020/DL-285). Idempotent under at-least-once redelivery: a card
 * already in the destination is skipped, so a re-PATCH never fires.
 *
 * Its refusals are keyed by the coordination ISSUE, so the alert carries `issue_number`
 * (DL-285); the per-card arms reached from inside the loop additionally carry that card's id.
 */
final class KanbanCoordCardMoveHandler implements DurableReaction, Handler
{
    /**
     * The synthetic `outcome` this handler's alerts carry — see the create leg's twin.
     * The dedup tuple is `(repo, outcome, reason)`, so this separates its signals from
     * the create leg's on a shared repo, and its two 4xx arms carry DIFFERENT reasons so
     * they cannot share one marker and silence each other (DL-274(3)).
     */
    private const ALERT_OUTCOME = 'coord_card_move';

    /**
     * What the classifier may ask this handler to do — an ALLOW-LIST, so an
     * unrecognized disposition can never fall through to a move. `terminal` and
     * `revive` are DL-200's lifecycle pair; `relane` (card#6393) is the label-driven
     * lane correction, emitted only by the opt-in `coord-card-relane` family.
     *
     * @var list<string>
     */
    private const DISPOSITIONS = ['terminal', 'revive', 'relane'];

    private WritebackAlertNotifier $alerts;

    public function __construct(?WritebackAlertNotifier $alerts = null)
    {
        $this->alerts = $alerts ?? new WritebackAlertNotifier;
    }

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $p = $target->payload;
        $repo = $p['repo'] ?? null;
        $issueNumber = $p['issue_number'] ?? null;
        $sid = $p['sid'] ?? null;
        $disposition = $p['disposition'] ?? null;
        // The disposition is an allow-list of what the classifier can emit — an
        // unrecognized value must never fall through to a move.
        // sid is NO LONGER always required (#4553): a non-prefixed issue carries an empty
        // sid legitimately and is correlated by github_issue by-ref.
        if (! is_string($repo) || $repo === ''
            || ! is_numeric($issueNumber)
            || ! in_array($disposition, self::DISPOSITIONS, true)) {
            // Deterministic classifier bug — permanent. The repo/issue that would key the
            // alert are part of what is malformed, so each degrades rather than
            // suppressing the signal.
            $this->alerts->warnAndNotify(
                'kanban_coord_card_move: malformed payload (repo/issue_number/disposition); ignoring',
                ['payload' => $p],
                is_string($repo) ? $repo : '', self::ALERT_OUTCOME, null, 'coord_card_move_payload_invalid',
                is_numeric($issueNumber) ? (int) $issueNumber : null,
            );

            return;
        }
        $issueNumber = (int) $issueNumber;
        $isPrefixed = is_string($sid) && $sid !== '';

        $writeback = WritebackConfig::loadDefault();
        if ($writeback === null) {
            // Degrades to log-only (docs/writeback.md, *Branch-#3 degradation*); the call
            // is kept so this arm cannot drift out of the paired primitive.
            $this->alerts->warnAndNotify(
                'kanban_coord_card_move: writeback not configured; ignoring',
                ['repo' => $repo, 'issue' => $issueNumber],
                $repo, self::ALERT_OUTCOME, null, 'writeback_not_configured', $issueNumber,
            );

            return;
        }
        $mapping = $writeback->mappingFor($repo);
        if ($mapping === null || ! $mapping->moveCoordCards
            || $mapping->coordCardTerminalStageId === null || $mapping->coordCardStageId === null) {
            // Opt-out / unmapped: permanent refusal — log + no-op (never 5xx-retry a config gap).
            // The stage-null arms are unreachable while move_coord_cards is on (WritebackConfig
            // fails closed at load); they are the type-narrowing for the moves below.
            Log::info('kanban_coord_card_move: repo not mapped or opt-out; ignoring', ['repo' => $repo, 'issue' => $issueNumber]);

            return;
        }

        // Per-issue correlation (#4553), mirroring the create leg so the move-set equals the
        // create-set. Prefixed → the `id:<sid>` tag. Non-prefixed → github_issue by-ref
        // (live only under population=all). Under `all` a prefixed card is dual-keyed, so we
        // union both lookups to also catch the prefix-change edge (a card first created
        // non-prefixed whose closing event now carries a prefix, or vice-versa).
        $byRef = $mapping->issuePopulation === WritebackMapping::POPULATION_ALL;
        if (! $isPrefixed && ! $byRef) {
            // No derivable correlation key: a null-sid target under population=prefixed. The
            // classifier never emits this; nothing to move.
            $this->alerts->warnAndNotify(
                'kanban_coord_card_move: malformed payload (empty sid with population=prefixed — no correlation key); ignoring',
                ['payload' => $p],
                $repo, self::ALERT_OUTCOME, null, 'coord_card_move_no_correlation_key', $issueNumber,
            );

            return;
        }

        $client = WritebackClientFactory::make();   // throws (→ 5xx) on a missing/insecure token
        try {
            $ids = [];
            if ($isPrefixed) {
                $ids = $client->cardsByTag($mapping->boardId, "id:{$sid}");
            }
            if ($byRef) {
                $ids = array_values(array_unique(array_merge($ids, $client->correlateIssue($mapping->boardId, $issueNumber, $repo))));
            }
            if ($ids === []) {
                // Never carded (create leg off / pre-ship issue), or the reconcile hasn't
                // run yet. Nothing to move — and this leg never creates.
                Log::info('kanban_coord_card_move: no card correlated; nothing to move', ['repo' => $repo, 'issue' => $issueNumber, 'sid' => $sid, 'by_ref' => $byRef]);

                return;
            }

            // PER-CARD error isolation: a tag can legitimately match several cards, and
            // a permanent 4xx on one of them (a card deleted between the search and the
            // read) must not abandon the rest — they would never be retried, since a
            // permanent failure is deliberately not redelivered. A transient 5xx still
            // propagates: redelivery re-runs the whole set, and the cards already moved
            // are skipped as idempotent.
            foreach ($ids as $id) {
                try {
                    $this->moveOne($client, $mapping, $id, $disposition, $sid, $repo, $issueNumber, $p);
                } catch (RequestException $e) {
                    if (RefusalContext::isPermanent($e)) {
                        // FLAT reason: this catch spans moveOne's getCard READ and its
                        // moveCard WRITE, so a status-split write reason would be
                        // wrong-but-specific on a refused read. Distinct from the
                        // correlation-read arm below so the two cannot share a marker.
                        $this->alerts->warnAndNotify(
                            'kanban_coord_card_move: kanban refused (4xx) for this card — skipping it (see `body` for the reason kanban gave)',
                            ['card_id' => $id, 'repo' => $repo, 'issue' => $issueNumber] + RefusalContext::from($e),
                            $repo, self::ALERT_OUTCOME, $id, 'coord_card_move_card_4xx', $issueNumber,
                        );

                        continue;
                    }
                    throw $e;
                }
            }
        } catch (RequestException $e) {
            // The cardsByTag read itself: 4xx permanent (alert + log + no-op), 5xx transient (throw → retry).
            if (RefusalContext::isPermanent($e)) {
                $this->alerts->warnAndNotify(
                    'kanban_coord_card_move: kanban refused (4xx) — ignoring (see `body` for the reason kanban gave)',
                    ['repo' => $repo, 'issue' => $issueNumber] + RefusalContext::from($e),
                    $repo, self::ALERT_OUTCOME, null, 'coord_card_move_lookup_4xx', $issueNumber,
                );

                return;
            }
            throw $e;
        }
    }

    /**
     * Apply one card's disposition. Throws RequestException; the caller isolates per-card.
     *
     * @param  array<mixed>  $p  the reaction-target payload — `title` and `labels` are the
     *                           lane inputs the revive and relane legs derive from (card#6393)
     */
    private function moveOne(KanbanClient $client, WritebackMapping $mapping, int $id, string $disposition, ?string $sid, string $repo, int $issueNumber, array $p): void
    {
        $card = $client->getCard($id);
        // SECURITY (belongs-to-mapped-board, DL-009): a tag can legitimately collide
        // across boards, so only ever act on the mapped one. Permanent refusal — alert +
        // log + no-op. SIGNALS (card#7133), same reason string and channel as the
        // `kanban_move_card` / `kanban_block_reason` twins, kept distinct in the dedup
        // tuple by the synthetic outcome (DL-274(3)). It signals because nothing else on
        // this path does: a cross-board write that SUCCEEDS emits no event, so the
        // refusal is the only surface that can tell an operator this class of citation is
        // occurring — and as an untagged `Log::info` it was indistinguishable from the
        // path never having been invoked at all. The compare and the report are now one
        // shared primitive (DL-292, card#7138) — that severity split was possible only
        // because this rule was written out three times.
        if (MappedBoardGuard::refuses($this->alerts, $card, $mapping, 'kanban_coord_card_move', $id, $repo, self::ALERT_OUTCOME, $issueNumber)) {
            return;
        }
        $stage = is_numeric($card['workflow_stage_id'] ?? null) ? (int) $card['workflow_stage_id'] : null;

        if ($disposition === 'terminal') {
            if ($stage === $mapping->coordCardTerminalStageId) {
                return;   // already concluded — redelivery-safe no-op
            }
            // The DL-178 human hold (card#8523, DL-340). Taken AFTER the already-concluded
            // no-op, exactly where the move and dependabot handlers take theirs: a pinned
            // card that is already in the terminal has no write to refuse, and alerting
            // there would report a permanent failure that did not happen. There is no
            // override to test first — the DL-194 unpark and DL-195 revive are outcomes of
            // the PR move handler and have no counterpart on an `issues.closed`.
            if (PinGuard::refuses(
                $this->alerts, $card, 'kanban_coord_card_move', 'terminal move', $id, $repo, self::ALERT_OUTCOME,
                ['issue' => $issueNumber, 'sid' => $sid] + MappedBoardGuard::boardContext($card, $mapping),
                $issueNumber,
            )) {
                return;
            }
            $client->moveCard($id, (int) $mapping->coordCardTerminalStageId);
            Log::info('kanban_coord_card_move: moved to terminal', ['card_id' => $id, 'stage' => $mapping->coordCardTerminalStageId, 'sid' => $sid, 'issue' => $issueNumber] + MappedBoardGuard::boardContext($card, $mapping));

            return;
        }

        // Both remaining legs write a LANE, so both resolve through the same primitive the
        // create leg uses (card#6393). A payload carrying no title — a foreign classifier's
        // target, since this handler is registered unconditionally — reads as an
        // un-governed issue and lands on the fixed stage, which is the pre-card#6393 answer.
        $title = is_string($p['title'] ?? null) ? $p['title'] : '';
        $placement = CoordCardLanePlacement::resolve($mapping, (int) $mapping->coordCardStageId, $title, CoordCardLanePlacement::labelsFrom($p));

        if ($disposition === 'relane') {
            $this->relaneOne($client, $mapping, $card, $id, $stage, $placement, $sid, $repo, $issueNumber);

            return;
        }

        // revive
        if ($stage !== $mapping->coordCardTerminalStageId) {
            // Not parked in OUR terminal: either already live, or moved on by someone.
            // Reviving would drag it backward (DL-163). Leave it.
            return;
        }
        // The actor-gate: an ALLOW-LIST of exactly "service" (kanban's ChangeSource emits
        // `human` for a UI move, `service` for api/system, and `null` on a pre-feature
        // row). Anything else — human, null, malformed, or an actor_type this bridge has
        // never heard of — fails CLOSED. A human's closure intent is never reversed.
        if (! self::serviceSet($card)) {
            $lastMove = is_array($card['last_stage_move'] ?? null) ? $card['last_stage_move'] : [];
            Log::info('kanban_coord_card_move: terminal was not service-set; refusing to revive', ['card_id' => $id, 'actor_type' => $lastMove['actor_type'] ?? null, 'sid' => $sid, 'issue' => $issueNumber]);

            return;
        }
        // The DL-178 human hold (card#8557), taken LAST — after the two gates above, both
        // of which end in no write at all. A card this leg was never going to move has
        // nothing to refuse, and alerting there would report a permanent failure that did
        // not happen; it is the same placement the terminal leg takes for the same reason.
        // ⚠ It is NOT redundant beside `serviceSet()`: that gate asks who made the card's
        // LAST STAGE MOVE, and pinning is a field PATCH, so a card an operator pins after
        // the bridge parked it stays service-set and reaches here every time.
        if (PinGuard::refuses(
            $this->alerts, $card, 'kanban_coord_card_move', 'revive', $id, $repo, self::ALERT_OUTCOME,
            ['issue' => $issueNumber, 'sid' => $sid] + MappedBoardGuard::boardContext($card, $mapping),
            $issueNumber,
        )) {
            return;
        }
        $this->warnUnmappedLanes($placement, $mapping, $id, $repo, $issueNumber);
        $client->moveCard($id, $placement['stage']);
        Log::info('kanban_coord_card_move: revived', ['card_id' => $id, 'stage' => $placement['stage'], 'lane' => $placement['lane'], 'sid' => $sid, 'issue' => $issueNumber] + MappedBoardGuard::boardContext($card, $mapping));
    }

    /**
     * The `relane` leg (card#6393 instance 2): an issue that gained a `stage:*` label
     * AFTER it was carded has its card moved to the lane that label declares, so the
     * consumer's lane→label writeback stops converging the new label back to the lane
     * the card was created in.
     *
     * FOUR gates, each closing a way this leg could become a third mover fighting the
     * other two:
     *   1. the answer must be LANE-DERIVED (`lane` non-null) — no lane map configured, or
     *      a non-`[TASK]` issue the lane model does not govern, and there is no lane to
     *      write. This is what makes an install without `coord_card_lane_stage_ids`
     *      byte-identical;
     *   2. the card must currently BE in one of the mapped lanes. Relane is a lane→lane
     *      move: a card someone has advanced to In-Progress, or one the close leg parked
     *      in the terminal, must not be yanked back into a lane by a label edit (the
     *      terminal is disjoint from the lanes by config, so this subsumes it);
     *   3. the terminal-provenance ALLOW-LIST the revive leg already carries — the card's
     *      current stage must be SERVICE-set. A human who dragged this card to a lane
     *      expressed a placement, and overriding it from a label would re-mint card#6393's
     *      own defect pointing the other way: instead of the writeback overwriting a
     *      label, the label would overwrite a board move;
     *   4. the DL-178 human hold (card#8557). It is NOT gate 3 restated: gate 3 asks who
     *      made the card's LAST STAGE MOVE, and a pin is a field PATCH, so a card pinned
     *      after the bridge laned it is still service-set and reaches the write. Taken
     *      last, where the write is, so a card the gates above already left alone raises
     *      no alert.
     *
     * @param  array<string, mixed>  $card  as returned by {@see KanbanClient::getCard()}
     * @param  array{stage: int, lane: ?string, unmapped: list<string>}  $placement
     */
    private function relaneOne(KanbanClient $client, WritebackMapping $mapping, array $card, int $id, ?int $stage, array $placement, ?string $sid, string $repo, int $issueNumber): void
    {
        if ($placement['lane'] === null) {
            // Gate 1 has TWO causes and they need two different operator actions — the
            // docblock above distinguishes them, so the line an operator actually reads
            // must too. A missing lane model is a CONFIG gap (add
            // `coord_card_lane_stage_ids`, or drop the family); a title the lane model does
            // not govern is the design working (only an anchored `[TASK]` is lane-derived,
            // mirroring the consumer's own gate) and needs nothing done.
            if ($mapping->coordCardLaneStageIds === null) {
                Log::info('kanban_coord_card_move: no lane model is configured for this repo; nothing to re-lane', ['card_id' => $id, 'repo' => $repo, 'issue' => $issueNumber]);
            } else {
                Log::info('kanban_coord_card_move: the lane model does not govern this issue (its title is not an anchored [TASK]); nothing to re-lane', ['card_id' => $id, 'repo' => $repo, 'issue' => $issueNumber]);
            }

            return;
        }
        if ($stage === $placement['stage']) {
            return;   // already in the declared lane — redelivery-safe no-op
        }
        $lanes = $mapping->coordCardLaneStageIds ?? [];
        if ($stage === null || ! in_array($stage, $lanes, true)) {
            Log::info('kanban_coord_card_move: card is not in a mapped lane; refusing to re-lane', ['card_id' => $id, 'stage' => $stage, 'sid' => $sid, 'issue' => $issueNumber]);

            return;
        }
        if (! self::serviceSet($card)) {
            $lastMove = is_array($card['last_stage_move'] ?? null) ? $card['last_stage_move'] : [];
            Log::info('kanban_coord_card_move: lane was not service-set; refusing to re-lane', ['card_id' => $id, 'actor_type' => $lastMove['actor_type'] ?? null, 'sid' => $sid, 'issue' => $issueNumber]);

            return;
        }
        // The DL-178 human hold (card#8557) — gate 4, and last for the reason the revive
        // leg's twin states: every gate above it ends in no write, so this is the first
        // point at which there is a refusal to report.
        if (PinGuard::refuses(
            $this->alerts, $card, 'kanban_coord_card_move', 're-lane', $id, $repo, self::ALERT_OUTCOME,
            ['issue' => $issueNumber, 'sid' => $sid] + MappedBoardGuard::boardContext($card, $mapping),
            $issueNumber,
        )) {
            return;
        }
        $this->warnUnmappedLanes($placement, $mapping, $id, $repo, $issueNumber);
        $client->moveCard($id, $placement['stage']);
        Log::info('kanban_coord_card_move: re-laned', ['card_id' => $id, 'stage' => $placement['stage'], 'lane' => $placement['lane'], 'from_stage' => $stage, 'sid' => $sid, 'issue' => $issueNumber] + MappedBoardGuard::boardContext($card, $mapping));
    }

    /**
     * The provenance ALLOW-LIST both lane-writing legs share: exactly `"service"`.
     * kanban's ChangeSource emits `human` for a UI move, `service` for api/system, and
     * `null` on a pre-feature row — so a deny-list of the human value would silently act
     * on null. Anything that is not literally `service` fails CLOSED.
     *
     * @param  array<mixed>  $card
     */
    private static function serviceSet(array $card): bool
    {
        $lastMove = is_array($card['last_stage_move'] ?? null) ? $card['last_stage_move'] : [];

        return ($lastMove['actor_type'] ?? null) === 'service';
    }

    /**
     * The ONE lane-config-gap diagnostic for this handler, shared by both lane-writing
     * legs. A `stage:*` label naming a lane the operator's map does not carry is SKIPPED
     * (the scan continues to the issue's next declared lane, then to the default), and the
     * operator is told which lane went unused — the same decision, and the same reasoning,
     * as the create leg's twin.
     *
     * It stays HERE rather than inside {@see CoordCardLanePlacement} deliberately:
     * `WritebackRefusalSignalCoverageTest` holds set-equality over the bare log calls in
     * the `Kanban*Handler.php` population, so hoisting it into the primitive would move it
     * out from under that guard.
     *
     * @param  array{stage: int, lane: ?string, unmapped: list<string>}  $placement
     */
    private function warnUnmappedLanes(array $placement, WritebackMapping $mapping, int $id, string $repo, int $issueNumber): void
    {
        if ($placement['unmapped'] === []) {
            return;
        }
        // The skipped lanes and the mapped set are CONTEXT, not interpolation: the DL-285
        // refusal-signal guard keys its accounted-for list on the message literal, and an
        // interpolated message degrades that key to a line number.
        Log::warning('kanban_coord_card_move: the issue declares a lane that is not mapped in coord_card_lane_stage_ids — moving to the next mapped lane it declares, else the default lane; add the lane to the mapping if this board has that column', ['card_id' => $id, 'repo' => $repo, 'issue' => $issueNumber, 'unmapped_lanes' => $placement['unmapped'], 'moved_to_lane' => $placement['lane'], 'mapped_lanes' => array_keys($mapping->coordCardLaneStageIds ?? [])]);
    }
}
