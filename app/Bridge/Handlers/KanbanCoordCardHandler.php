<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\CardCollapse;
use App\Bridge\Writeback\CoordCardLanePlacement;
use App\Bridge\Writeback\WritebackAlertNotifier;
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
 * the same tag). That read is LIVE-only, so a fourth duplicate source needs its own
 * branch: a thread whose only card was ARCHIVED reads as un-carded, and a reopen would
 * mint a second card over the retire (DL-296). One `archivedOnly` tag search answers
 * it, and {@see retiredTwins} decides on the consumer's partition — a human retire
 * suppresses the create and signals; a reroute-archived twin does not.
 * Otherwise create at the stage {@see CoordCardLanePlacement} resolves (the
 * mapping's `coord_card_stage_id` unless a lane model is configured and governs this
 * issue — an anchored `[TASK]` title, card#6371), then re-read + collapse a raced
 * duplicate via the shared {@see CardCollapse}.
 *
 * DURABLE, with the same transient(5xx → retry) / permanent(4xx → alert + log + no-op)
 * split as the other writeback create handler (DL-020/DL-285). Tags at create are
 * `["id:<sid>", "type:<itype>"]` ONLY — `repo:` is omitted (non-critical; the
 * reconcile folds it on its next run).
 *
 * Its permanent refusals are keyed by the coordination ISSUE, not by a card — they fire
 * while *creating* the card — so the alert carries `issue_number` with a null `card_id`
 * (DL-285). See docs/writeback.md's *Which failures signal*.
 */
final class KanbanCoordCardHandler implements DurableReaction, Handler
{
    /**
     * The synthetic `outcome` this handler's alerts carry. It has no PR outcome of its
     * own, and the alert dedup tuple is `(repo, outcome, reason)` — so a constant naming
     * the reaction keeps its signals from colliding with the other handlers' on a shared
     * repo (DL-274(3)).
     */
    private const ALERT_OUTCOME = 'coord_card_create';

    /**
     * The consumer's `REROUTE_ARCHIVED_TAG` (`kanban_common.REROUTE_ARCHIVED_TAG`),
     * carried here because the two movers must partition the archived population the
     * SAME way or the bridge would honour a retire the reconcile is about to undo
     * (DL-296). It is stamped by the consumer's reclass pass when it archives a coord
     * twin whose source re-routed to another board — FRAMEWORK bookkeeping, not a human
     * decision — which is why an archived card carrying it does NOT suppress a create:
     * a source that routes BACK must get its card again. The literal is a shared
     * CONTRACT with the consumer (like the `id:<sid>` adoption key), so it is pinned
     * here rather than derived; a consumer that renames it renames it on both sides.
     */
    private const REROUTE_ARCHIVED_TAG = 'coord:reroute-archived';

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
            // A malformed payload is a deterministic CLASSIFIER bug — permanent, so it
            // must not throw. The repo/issue that would key the alert are part of what
            // is malformed, so each degrades to the empty/null form rather than
            // suppressing the signal.
            $this->alerts->warnAndNotify(
                'kanban_coord_card: malformed payload (repo/issue_number/itype/title); ignoring',
                ['payload' => $p],
                is_string($repo) ? $repo : '', self::ALERT_OUTCOME, null, 'coord_card_payload_invalid',
                is_numeric($issueNumber) ? (int) $issueNumber : null,
            );

            return;
        }
        $issueNumber = (int) $issueNumber;
        $isPrefixed = is_string($sid) && $sid !== '';

        $writeback = WritebackConfig::loadDefault();
        if ($writeback === null) {
            // Degrades to log-only: with no writeback.json the notifier has no
            // alert_channel to load (docs/writeback.md, *Branch-#3 degradation*). The
            // call is kept so this arm cannot drift out of the paired primitive.
            $this->alerts->warnAndNotify(
                'kanban_coord_card: writeback not configured; ignoring',
                ['repo' => $repo, 'issue' => $issueNumber],
                $repo, self::ALERT_OUTCOME, null, 'writeback_not_configured', $issueNumber,
            );

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
            $this->alerts->warnAndNotify(
                'kanban_coord_card: malformed payload (empty sid with population=prefixed — no correlation key); ignoring',
                ['payload' => $p],
                $repo, self::ALERT_OUTCOME, null, 'coord_card_no_correlation_key', $issueNumber,
            );

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
            // Both reads above are LIVE-only (kanban excludes archived rows), so a thread
            // whose ONLY card was RETIRED reads as un-carded and a reopen would mint a
            // second card over the retire (DL-296). Ask the archive side explicitly, and
            // only here — this is the last branch before the create, so the extra search
            // is paid once per card the handler was about to mint, never on a skip.
            if ($tag !== null) {
                $retired = self::retiredTwins($client->cardRowsByTag($mapping->boardId, $tag, true));
                if ($retired !== []) {
                    $this->alerts->warnAndNotify(
                        'kanban_coord_card: the only card for this thread is ARCHIVED (a deliberate retire, and archival is not the bridge\'s to undo) — NOT creating a replacement; unarchive that card if the thread is live again',
                        ['repo' => $repo, 'issue' => $issueNumber, 'tag' => $tag, 'archived_card_ids' => $retired],
                        $repo, self::ALERT_OUTCOME, null, 'coord_card_archived_twin', $issueNumber,
                    );

                    return;
                }
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
            $stageId = $this->createStage($mapping, $title, $p, $repo, $issueNumber);

            $newId = $client->createCard(
                $mapping->boardId,
                $stageId,
                $title,
                $payload,
                $tags,
                $mapping->swimlaneId,
                "Coordination thread {$repo}#{$issueNumber}",
                $itype === 'brief' ? 1 : 0,
                "https://github.com/{$repo}/issues/{$issueNumber}",
            );
            Log::info('kanban_coord_card: created', ['card_id' => $newId, 'board' => $mapping->boardId, 'stage' => $stageId, 'swimlane' => $mapping->swimlaneId, 'sid' => $sid, 'issue' => $issueNumber, 'population' => $mapping->issuePopulation]);

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
            // A kanban 4xx is permanent (alert + log + no-op); a 5xx / timeout is transient (throw → redelivery retries).
            if (RefusalContext::isPermanent($e)) {
                // FLAT reason, unlike the card-keyed handlers': this one catch spans the
                // correlation READS and the create WRITE, so a status-split
                // `403_not_writable_by_this_token` would be wrong-but-specific on a
                // refused read. The status and kanban's own words are in `body`.
                $this->alerts->warnAndNotify(
                    'kanban_coord_card: kanban refused (4xx) — ignoring (see `body` for the reason kanban gave)',
                    ['repo' => $repo, 'issue' => $issueNumber] + RefusalContext::from($e),
                    $repo, self::ALERT_OUTCOME, null, 'coord_card_create_4xx', $issueNumber,
                );

                return;
            }
            throw $e;
        }
    }

    /**
     * The archived cards that represent a DELIBERATE RETIRE, out of the rows an
     * `archived=1` tag search returned (DL-296) — the bridge's copy of the consumer's
     * `archived_stable_ids(...) - reroute_archived_stable_ids(...)` partition, so one
     * hazard keeps one rule across both movers.
     *
     * A retire is the DEFAULT reading of an archived card: only the consumer's own
     * reroute tag exempts one, and the exemption is per CARD, not per thread — a thread
     * whose archived set holds one reroute-tagged card and one human-retired card is
     * still retired, because the human decision is the one that would be overturned.
     * That asymmetry is deliberate and is why this returns the retired ids rather than a
     * bool: the caller names them in the signal, so an operator can see which card to
     * unarchive.
     *
     * Every row here is archived by construction (the caller passes `archivedOnly`), so
     * this re-tests only the tag. A row kanban answered without an `id` is still a
     * retire — dropping it would silently un-suppress the create — and reports as `0`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private static function retiredTwins(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $tags = $row['tags'] ?? null;
            if (is_array($tags) && in_array(self::REROUTE_ARCHIVED_TAG, $tags, true)) {
                continue;
            }
            $id = $row['id'] ?? null;
            $ids[] = is_numeric($id) ? (int) $id : 0;
        }

        return $ids;
    }

    /**
     * The stage a new coord card is created in (card#6371): the lane the issue's
     * `stage:*` label DECLARES, when this mapping configures the board's lane model
     * and the lane model governs this issue — else the mapping's fixed
     * `coord_card_stage_id`, which is byte-identical DL-198.
     *
     * Why derive it at all: the fixed stage is not a placement on a lane-model board,
     * it is a REWRITE. The consumer's `kanban-writeback` pass runs before its
     * issues-sync and maps the card's lane back onto the issue's `stage:*` label, so
     * pinning every card here silently overwrites the priority the issue already
     * states (measured on the reference install: 9 issues flipped to `stage:now` —
     * card#6348 (reporter's install, sola), not a card on this repo's board).
     *
     * The resolution itself is {@see CoordCardLanePlacement}, shared with the move
     * handler's revive and relane legs so the three writes cannot disagree about where
     * a `[TASK]` belongs (card#6393). What stays here is the WARN, which the primitive
     * deliberately does not carry.
     *
     * The unresolvable arm is a DECISION, not a fail-quiet: a `stage:*` label naming a
     * lane the operator's map does not carry is SKIPPED (the scan continues to the
     * issue's next declared lane, then to the default) and WARNs naming it, so the
     * config gap is visible in the log of the very create it affected. Refusing the
     * create would leave the thread untracked over a priority hint; creating at the
     * fixed stage would put the card back in exactly the lane this path exists to stop
     * imposing.
     *
     * @param  array<mixed>  $p  the reaction-target payload (its `labels` key is the
     *                           issue's labels; a target that carries none resolves like
     *                           an unlabelled issue — see
     *                           {@see CoordCardLanePlacement::labelsFrom()} for why that
     *                           is a boundary read and not back-compat)
     */
    private function createStage(WritebackMapping $mapping, string $title, array $p, string $repo, int $issueNumber): int
    {
        // coordCardStageId is non-null here — handle() refuses the target otherwise.
        $placement = CoordCardLanePlacement::resolve($mapping, (int) $mapping->coordCardStageId, $title, CoordCardLanePlacement::labelsFrom($p));
        if ($placement['unmapped'] !== []) {
            // The skipped lanes and the mapped set are CONTEXT, not interpolation: the
            // DL-285 refusal-signal guard keys its accounted-for list on the message
            // literal, and an interpolated message degrades that key to a line number.
            Log::warning('kanban_coord_card: the issue declares a lane that is not mapped in coord_card_lane_stage_ids — creating in the next mapped lane it declares, else the default lane; add the lane to the mapping if this board has that column', ['repo' => $repo, 'issue' => $issueNumber, 'unmapped_lanes' => $placement['unmapped'], 'created_in_lane' => $placement['lane'], 'mapped_lanes' => array_keys($mapping->coordCardLaneStageIds ?? [])]);
        }

        return $placement['stage'];
    }
}
