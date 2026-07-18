<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\CardCollapse;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
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
        $sid = $p['sid'] ?? null;   // null for a non-prefixed issue (#4553 by-ref path)
        $itype = $p['itype'] ?? null;
        $title = $p['title'] ?? null;
        // sid is NO LONGER always required (#4553): a non-prefixed issue carries an empty
        // sid legitimately and is correlated by github_issue by-ref. The remaining fields
        // are always required.
        if (! is_string($repo) || $repo === ''
            || ! is_numeric($issueNumber)
            || ! is_string($itype) || $itype === ''
            || ! is_string($title) || $title === '') {
            Log::warning('kanban_coord_card: malformed payload (repo/issue_number/itype/title); ignoring', ['payload' => $p]);

            return;
        }
        $issueNumber = (int) $issueNumber;
        $isPrefixed = is_string($sid) && $sid !== '';

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

        // Per-issue correlation keys (#4553). Prefixed → the `id:<sid>` tag (DL-198, shared
        // with the tag-keyed reconcile). Non-prefixed → the github_issue by-ref key, live
        // ONLY under population=all. A card created under `all` stamps EVERY eligible key
        // (tag when prefixed AND issue_number in payload always under `all`), so a prefixed
        // card is dual-keyed and the prefix-change-between-events edge is covered by the
        // unified pre-check below.
        $byRef = $mapping->issuePopulation === WritebackMapping::POPULATION_ALL;
        if (! $isPrefixed && ! $byRef) {
            // No derivable correlation key: a null-sid target under population=prefixed. The
            // classifier never emits this; refuse defensively rather than mint an
            // uncorrelatable card that would re-create on every redelivery.
            Log::warning('kanban_coord_card: malformed payload (empty sid with population=prefixed — no correlation key); ignoring', ['payload' => $p]);

            return;
        }

        $tag = $isPrefixed ? "id:{$sid}" : null;
        $client = WritebackClientFactory::make();   // throws (→ 5xx) on a missing/insecure token
        try {
            // Unified pre-check: skip if EITHER derivable key already resolves a card. The
            // tag covers redelivery / opened+reopened / the bridge-vs-reconcile race (both
            // movers key on the same tag). The by-ref key (under `all`) additionally covers
            // the non-prefixed population AND the prefix-change edge (a card first created
            // non-prefixed is dual-discoverable once a later prefixed event stamps the tag).
            if ($tag !== null && $client->cardsByTag($mapping->boardId, $tag) !== []) {
                Log::info('kanban_coord_card: card already exists for tag; skipping', ['repo' => $repo, 'issue' => $issueNumber, 'tag' => $tag]);

                return;
            }
            if ($byRef && $client->correlateIssue($mapping->boardId, $issueNumber, $repo) !== []) {
                Log::info('kanban_coord_card: card already exists for issue by-ref; skipping', ['repo' => $repo, 'issue' => $issueNumber]);

                return;
            }

            // Churn-avoidance fields mirror the reconcile's build_create so its next pass
            // doesn't update-churn them: description, priority (brief⇒1), and the issue
            // URL. external_id is intentionally NOT set — build_create omits it and
            // kanban's (board_id, external_id) uniqueness would 422 a colliding issue
            // number on a multi-repo coord board; external_link carries the correlation.
            // Stamp every eligible key: the id: tag when prefixed; issue_number in payload
            // under `all` (so the by-ref index finds it — the ref derives from that payload
            // key, verified live). Under the prefixed default this is byte-identical DL-198
            // (empty payload, [id:,type:] tags).
            $tags = ["type:{$itype}"];
            if ($isPrefixed) {
                array_unshift($tags, "id:{$sid}");
            }
            $payload = $byRef ? ['issue_number' => $issueNumber] : [];

            $newId = $client->createCard(
                $mapping->boardId,
                $mapping->coordCardStageId,
                $title,
                $payload,
                $tags,
                $mapping->swimlaneId,
                "Coordination thread {$repo}#{$issueNumber}",
                $itype === 'brief' ? 1 : 0,
                "https://github.com/{$repo}/issues/{$issueNumber}",
            );
            Log::info('kanban_coord_card: created', ['card_id' => $newId, 'board' => $mapping->boardId, 'stage' => $mapping->coordCardStageId, 'swimlane' => $mapping->swimlaneId, 'sid' => $sid, 'issue' => $issueNumber, 'population' => $mapping->issuePopulation]);

            // Close the check-then-create race (like the dependabot path): re-read by each
            // eligible key and collapse a duplicate a concurrent delivery (or the reconcile)
            // minted. Deterministic survivor ⇒ racing workers converge.
            if ($tag !== null) {
                $live = $client->cardsByTag($mapping->boardId, $tag);
                if (count($live) > 1) {
                    CardCollapse::toSurvivor($client, array_fill_keys($live, []), 'kanban_coord_card', ['repo' => $repo, 'issue' => $issueNumber, 'tag' => $tag]);
                }
            }
            if ($byRef) {
                $liveRef = $client->correlateIssue($mapping->boardId, $issueNumber, $repo);
                if (count($liveRef) > 1) {
                    CardCollapse::toSurvivor($client, array_fill_keys($liveRef, []), 'kanban_coord_card', ['repo' => $repo, 'issue' => $issueNumber, 'ref' => "github_issue:{$issueNumber}"]);
                }
            }
        } catch (RequestException $e) {
            // A kanban 4xx is permanent (log + no-op); a 5xx / timeout is transient (throw → redelivery retries).
            if (RefusalContext::isPermanent($e)) {
                Log::warning('kanban_coord_card: kanban refused (4xx) — ignoring (see `body` for the reason kanban gave)', ['repo' => $repo, 'issue' => $issueNumber] + RefusalContext::from($e));

                return;
            }
            throw $e;
        }
    }
}
