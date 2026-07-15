<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Writeback\CardCollapse;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Create a kanban card for a coordination ISSUE opened/reopened with a recognized
 * `[PREFIX]` title (DL-198) — the writeback's real-time mover for the coord-card
 * flow, so a tracking card appears immediately instead of waiting for the periodic
 * `reconcile_simple_board` pass. The reconcile stays the backstop: it adopts the
 * bridge-created card by its `id:<sid>` tag, so the bridge stays REGISTRY-FREE.
 *
 * CREATE-ONLY — this handler only ever creates; it never moves or archives a card.
 * The bridge as a whole is no longer create-only, though: its sibling
 * {@see KanbanCoordCardMoveHandler} (DL-200, opt-in `move_coord_cards`) carries the
 * close→terminal / reopen→revive column moves, and under roundtable #18's partition
 * the reconcile DEFERS to it as the backstop. The reconcile still owns column and
 * lifecycle wherever that opt-in is off. Archival remains the reconcile's alone.
 * Correlation + idempotency key on the `id:<sid>` TAG (the
 * locked contract adoption key): if a card already carries it, skip — which covers
 * redelivery, opened+reopened, AND the bridge-vs-reconcile race (both movers key on
 * the same tag). Otherwise create at the mapping's `coord_card_stage_id`, then
 * re-read + collapse a raced duplicate via the shared {@see CardCollapse}.
 *
 * DURABLE, with the same transient(5xx → retry) / permanent(4xx → log + no-op)
 * split as the other writeback create handler (DL-020). Tags at create are
 * `["id:<sid>", "type:<itype>"]` ONLY — `repo:` is omitted (non-critical; the
 * reconcile folds it on its next run).
 */
final class KanbanCoordCardHandler implements DurableReaction, Handler
{
    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $p = $target->payload;
        $repo = $p['repo'] ?? null;
        $issueNumber = $p['issue_number'] ?? null;
        $sid = $p['sid'] ?? null;
        $itype = $p['itype'] ?? null;
        $title = $p['title'] ?? null;
        if (! is_string($repo) || $repo === ''
            || ! is_numeric($issueNumber)
            || ! is_string($sid) || $sid === ''
            || ! is_string($itype) || $itype === ''
            || ! is_string($title) || $title === '') {
            Log::warning('kanban_coord_card: malformed payload (repo/issue_number/sid/itype/title); ignoring', ['payload' => $p]);

            return;
        }
        $issueNumber = (int) $issueNumber;

        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        if ($writeback === null) {
            Log::warning('kanban_coord_card: writeback not configured; ignoring', ['repo' => $repo, 'issue' => $issueNumber]);

            return;
        }
        $mapping = $writeback->mappingFor($repo);
        if ($mapping === null || ! $mapping->createCoordCards || $mapping->coordCardStageId === null) {
            // Opt-out / unmapped: permanent refusal — log + no-op (never 5xx-retry a config gap).
            Log::info('kanban_coord_card: repo not mapped or opt-out; ignoring', ['repo' => $repo, 'issue' => $issueNumber]);

            return;
        }

        $tag = "id:{$sid}";
        $client = WritebackClientFactory::make();   // throws (→ 5xx) on a missing/insecure token
        try {
            // Idempotency: correlate-before-create by the `id:` tag. Non-empty ⇒ a
            // card already exists (redelivery, opened+reopened, OR the periodic
            // reconcile already carded it — both movers key on the SAME tag) → skip.
            $existing = $client->cardsByTag($mapping->boardId, $tag);
            if ($existing !== []) {
                Log::info('kanban_coord_card: card already exists for tag; skipping', ['repo' => $repo, 'issue' => $issueNumber, 'tag' => $tag]);

                return;
            }

            // Churn-avoidance fields mirror the reconcile's build_create so its next pass
            // doesn't update-churn them: description, priority (brief⇒1), and the issue
            // URL. external_id is intentionally NOT set — build_create omits it and
            // kanban's (board_id, external_id) uniqueness would 422 a colliding issue
            // number on a multi-repo coord board; external_link carries the correlation.
            $newId = $client->createCard(
                $mapping->boardId,
                $mapping->coordCardStageId,
                $title,
                [],
                ["id:{$sid}", "type:{$itype}"],
                $mapping->swimlaneId,
                "Coordination thread {$repo}#{$issueNumber}",
                $itype === 'brief' ? 1 : 0,
                "https://github.com/{$repo}/issues/{$issueNumber}",
            );
            Log::info('kanban_coord_card: created', ['card_id' => $newId, 'board' => $mapping->boardId, 'stage' => $mapping->coordCardStageId, 'swimlane' => $mapping->swimlaneId, 'sid' => $sid, 'issue' => $issueNumber]);

            // Close the check-then-create race (like the dependabot path): re-read by
            // the same `id:` tag and collapse any duplicate a concurrent delivery (or
            // the reconcile) minted. Deterministic survivor ⇒ racing workers converge.
            $live = $client->cardsByTag($mapping->boardId, $tag);
            if (count($live) > 1) {
                CardCollapse::toSurvivor($client, array_fill_keys($live, []), 'kanban_coord_card', ['repo' => $repo, 'issue' => $issueNumber, 'tag' => $tag]);
            }
        } catch (RequestException $e) {
            // A kanban 4xx is permanent (log + no-op); a 5xx / timeout is transient (throw → redelivery retries).
            if ($this->isPermanent($e)) {
                Log::warning('kanban_coord_card: kanban refused (4xx) — ignoring', ['repo' => $repo, 'issue' => $issueNumber, 'status' => $e->response->status()]);

                return;
            }
            throw $e;
        }
    }

    private function isPermanent(RequestException $e): bool
    {
        $status = $e->response->status();

        return $status >= 400 && $status < 500;
    }
}
