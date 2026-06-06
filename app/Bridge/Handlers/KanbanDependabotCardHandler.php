<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Create-or-move a kanban card for a DEPENDABOT pull request — the writeback's
 * second reaction. Dependabot PRs carry no DL and have no pre-existing card, so
 * GitHubPrCardMoveClassifier emits this target (keyed by PR NUMBER) instead of a
 * move, and only when the repo's mapping opts in (`create_dependabot_cards`).
 *
 * Lifecycle, idempotent on `payload.pr_number`:
 *  - card exists → move it to the outcome's stage (no-op if already there).
 *  - no card, outcome opened / merged / merged_to_main → create it at that stage.
 *  - no card, outcome closed_unmerged → skip (don't mint a card for a dependabot
 *    PR we never tracked just to mark it closed).
 *
 * DURABLE, with the same transient(5xx → retry) / permanent(4xx → log + no-op)
 * split as the move handler (DL-020). New cards are tagged `dependencies` +
 * `triaged` so the routine churn doesn't flood the untriaged sweep.
 */
final class KanbanDependabotCardHandler implements DurableReaction, Handler
{
    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $p = $target->payload;
        $repo = $p['repo'] ?? null;
        $outcome = $p['outcome'] ?? null;
        $prNumber = $p['pr_number'] ?? null;
        if (! is_string($repo) || $repo === '' || ! is_string($outcome) || $outcome === '' || ! is_numeric($prNumber)) {
            Log::warning('kanban_dependabot_card: malformed payload (repo/outcome/pr_number); ignoring', ['payload' => $p]);

            return;
        }
        $prNumber = (int) $prNumber;
        $title = is_string($p['pr_title'] ?? null) && $p['pr_title'] !== '' ? $p['pr_title'] : "Dependabot PR #{$prNumber}";
        $url = is_string($p['pr_url'] ?? null) ? $p['pr_url'] : '';

        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        if ($writeback === null) {
            Log::warning('kanban_dependabot_card: writeback not configured; ignoring', ['repo' => $repo, 'pr' => $prNumber]);

            return;
        }
        $mapping = $writeback->mappingFor($repo);
        if ($mapping === null || ! $mapping->createDependabotCards) {
            // Opt-out / unmapped: permanent refusal — log + no-op (never 5xx-retry a config gap).
            Log::info('kanban_dependabot_card: repo not mapped or opt-out; ignoring', ['repo' => $repo, 'pr' => $prNumber]);

            return;
        }
        $stageId = $mapping->stageFor($outcome);
        if ($stageId === null) {
            Log::info('kanban_dependabot_card: no stage mapped for outcome; ignoring', ['repo' => $repo, 'outcome' => $outcome, 'pr' => $prNumber]);

            return;
        }

        $client = WritebackClientFactory::make();   // throws (→ 5xx) on a missing/insecure token
        try {
            $cardId = $client->findCardByPrNumber($mapping->boardId, $prNumber);
            if ($cardId !== null) {
                // Idempotent: move only when not already in the target stage.
                $card = $client->getCard($cardId);
                if (($card['workflow_stage_id'] ?? null) !== $stageId) {
                    $client->moveCard($cardId, $stageId);
                    Log::info('kanban_dependabot_card: moved', ['card_id' => $cardId, 'stage' => $stageId, 'outcome' => $outcome, 'pr' => $prNumber]);
                }

                return;
            }
            if ($outcome === 'closed_unmerged') {
                return;   // never tracked → nothing to create just to close
            }
            $newId = $client->createCard($mapping->boardId, $stageId, $title, [
                'pr_number' => $prNumber,
                'pr_url' => $url,
                'origin' => 'dependabot',
            ], ['dependencies', 'triaged'], $mapping->swimlaneId);
            Log::info('kanban_dependabot_card: created', ['card_id' => $newId, 'board' => $mapping->boardId, 'stage' => $stageId, 'swimlane' => $mapping->swimlaneId, 'outcome' => $outcome, 'pr' => $prNumber]);
        } catch (RequestException $e) {
            // A kanban 4xx is permanent (log + no-op); a 5xx / timeout is transient (throw → redelivery retries).
            if ($this->isPermanent($e)) {
                Log::warning('kanban_dependabot_card: kanban refused (4xx) — ignoring', ['repo' => $repo, 'pr' => $prNumber, 'status' => $e->response->status()]);

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
