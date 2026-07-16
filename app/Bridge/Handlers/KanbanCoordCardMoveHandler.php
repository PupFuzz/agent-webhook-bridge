<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Move a coordination issue's tracking card in real time (DL-200) — the sibling of
 * {@see KanbanCoordCardHandler}'s create leg (roundtable #18(b)). A closed issue's
 * card concludes into `coord_card_terminal_stage_id`; a reopened issue's card
 * revives to `coord_card_stage_id`.
 *
 * THE PARTITION (roundtable #18): this is the real-time PRIMARY, so each consumer's
 * periodic reconcile DEFERS to it and backstops. That makes the bridge a coord-card
 * COLUMN mover — deliberately widening the create leg's original create-only scope.
 *
 * Correlation on the SAME `id:<sid>` TAG the create leg writes, so the two legs need
 * no registry. Absent tag ⇒ nothing to move (never create — create-if-absent is the
 * create family's half of the reopen composition, so exactly one leg ever acts).
 *
 * THE ACTOR-GATE (revive only, #18 Q5): revive IFF the terminal was SERVICE-set —
 * `last_stage_move.actor_type === "service"`, an ALLOW-LIST rather than a deny-list of
 * the human value. (kanban's ChangeSource emits exactly `human` for a UI move, `service`
 * for api/system, and `null` on a pre-feature row — so a deny-list would silently revive
 * on null.) Absent / null / malformed / unknown / human ⇒ fail CLOSED. A human who drags a card to the
 * terminal has expressed a closure intent the bridge must never reverse. Revive also
 * requires the card to currently BE in that terminal: a card someone has since moved
 * on is live work, and dragging it back to the create stage is exactly the backward
 * regression DL-163 forbids.
 *
 * A close, by contrast, is unconditional over `user_lanes` — ruled on #18: a human's
 * priority placement YIELDS to closure ("close→Done IS the terminal case, both movers
 * agree"), so there is no PinGuard side to pick.
 *
 * DURABLE, with the writeback's standard transient(5xx → retry) / permanent(4xx → log
 * + no-op) split (DL-020). Idempotent under at-least-once redelivery: a card already
 * in the destination is skipped, so a re-PATCH never fires.
 */
final class KanbanCoordCardMoveHandler implements DurableReaction, Handler
{
    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $p = $target->payload;
        $repo = $p['repo'] ?? null;
        $issueNumber = $p['issue_number'] ?? null;
        $sid = $p['sid'] ?? null;
        $disposition = $p['disposition'] ?? null;
        // The disposition is an allow-list of what the classifier can emit — an
        // unrecognized value must never fall through to a move.
        if (! is_string($repo) || $repo === ''
            || ! is_numeric($issueNumber)
            || ! is_string($sid) || $sid === ''
            || ($disposition !== 'terminal' && $disposition !== 'revive')) {
            Log::warning('kanban_coord_card_move: malformed payload (repo/issue_number/sid/disposition); ignoring', ['payload' => $p]);

            return;
        }
        $issueNumber = (int) $issueNumber;

        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        if ($writeback === null) {
            Log::warning('kanban_coord_card_move: writeback not configured; ignoring', ['repo' => $repo, 'issue' => $issueNumber]);

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

        $tag = "id:{$sid}";
        $client = WritebackClientFactory::make();   // throws (→ 5xx) on a missing/insecure token
        try {
            $ids = $client->cardsByTag($mapping->boardId, $tag);
            if ($ids === []) {
                // Never carded (create leg off / pre-ship issue), or the reconcile hasn't
                // run yet. Nothing to move — and this leg never creates.
                Log::info('kanban_coord_card_move: no card carries tag; nothing to move', ['repo' => $repo, 'issue' => $issueNumber, 'tag' => $tag]);

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
                    $this->moveOne($client, $mapping, $id, $disposition, $sid, $repo, $issueNumber);
                } catch (RequestException $e) {
                    if ($this->isPermanent($e)) {
                        Log::warning('kanban_coord_card_move: kanban refused (4xx) for this card — skipping it (see `body` for the reason kanban gave)', ['card_id' => $id, 'repo' => $repo, 'issue' => $issueNumber] + RefusalContext::from($e));

                        continue;
                    }
                    throw $e;
                }
            }
        } catch (RequestException $e) {
            // The cardsByTag read itself: 4xx permanent (log + no-op), 5xx transient (throw → retry).
            if ($this->isPermanent($e)) {
                Log::warning('kanban_coord_card_move: kanban refused (4xx) — ignoring (see `body` for the reason kanban gave)', ['repo' => $repo, 'issue' => $issueNumber] + RefusalContext::from($e));

                return;
            }
            throw $e;
        }
    }

    /** Apply one card's disposition. Throws RequestException; the caller isolates per-card. */
    private function moveOne(KanbanClient $client, WritebackMapping $mapping, int $id, string $disposition, string $sid, string $repo, int $issueNumber): void
    {
        $card = $client->getCard($id);
        // Tag-collision guard: only ever act on the mapped board.
        if (! is_numeric($card['board_id'] ?? null) || (int) $card['board_id'] !== $mapping->boardId) {
            Log::info('kanban_coord_card_move: card is on another board; skipping', ['card_id' => $id, 'repo' => $repo, 'issue' => $issueNumber]);

            return;
        }
        $stage = is_numeric($card['workflow_stage_id'] ?? null) ? (int) $card['workflow_stage_id'] : null;

        if ($disposition === 'terminal') {
            if ($stage === $mapping->coordCardTerminalStageId) {
                return;   // already concluded — redelivery-safe no-op
            }
            $client->moveCard($id, (int) $mapping->coordCardTerminalStageId);
            Log::info('kanban_coord_card_move: moved to terminal', ['card_id' => $id, 'stage' => $mapping->coordCardTerminalStageId, 'sid' => $sid, 'issue' => $issueNumber]);

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
        $lastMove = is_array($card['last_stage_move'] ?? null) ? $card['last_stage_move'] : [];
        if (($lastMove['actor_type'] ?? null) !== 'service') {
            Log::info('kanban_coord_card_move: terminal was not service-set; refusing to revive', ['card_id' => $id, 'actor_type' => $lastMove['actor_type'] ?? null, 'sid' => $sid, 'issue' => $issueNumber]);

            return;
        }
        $client->moveCard($id, (int) $mapping->coordCardStageId);
        Log::info('kanban_coord_card_move: revived', ['card_id' => $id, 'stage' => $mapping->coordCardStageId, 'sid' => $sid, 'issue' => $issueNumber]);
    }

    private function isPermanent(RequestException $e): bool
    {
        $status = $e->response->status();

        return $status >= 400 && $status < 500;
    }
}
