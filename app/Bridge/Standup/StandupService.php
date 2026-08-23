<?php

namespace App\Bridge\Standup;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\HandlerException;
use App\Bridge\Retention\RetentionService;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Models\AgentDispatch;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Builds and pushes the PM standup digest (DL-306).
 *
 * Silent by design — no console output — so the same code serves the event gate (where
 * there is nothing to print to) and `bridge:standup` (which formats its own). Same split
 * as {@see RetentionService}.
 *
 * THE ONE RULE THIS CLASS EXISTS TO HOLD: every value it puts in the digest comes from a
 * store the bridge writes, read at build time. Where it cannot read one it emits no key
 * (a seat's delivery time) or no row (a board with no Now-lane model) — never a
 * placeholder. See {@see StandupDigest} for the fields deliberately absent and why.
 */
final class StandupService
{
    public function __construct(private readonly HandlerRegistry $handlers) {}

    public function build(): StandupDigest
    {
        return new StandupDigest(
            generatedAt: Carbon::now()->toIso8601String(),
            seats: $this->seats(),
            boards: $this->boards(),
        );
    }

    /**
     * Push a digest at the named seat over its own configured channel.
     *
     * Deliberately routed through the registered `channel_push` handler rather than
     * `ChannelPushTransport` directly: that handler already owns the agent-config
     * endpoint fallback, the fail-closed bearer read, the socket validation and the
     * DL-014 prefix gate. A second sender would be a second, quietly-weaker set of those
     * rules. The payload carries no `socket`/`url`, which is precisely what selects the
     * agent-config branch — the only branch a token may ride.
     *
     * @throws HandlerException|ConfigException
     */
    public function push(StandupDigest $digest, string $agentName): void
    {
        $agent = AgentConfig::load($agentName, (string) config('bridge.config_dir'));

        $intent = new Intent(
            kind: 'pm_standup',
            subjectId: 'standup:'.$digest->generatedAt,
            provider: 'bridge',
            // The bridge itself authored this, and no upstream actor did. `null` is the
            // registry's own established spelling for an actor it cannot name (DL-002),
            // so a consumer's existing "unknown author" handling covers it unchanged.
            actor: new Actor(id: null),
            summary: $digest->summary(),
            payload: $digest->toArray(),
        );

        $handler = $this->handlers->resolve('channel_push');
        if ($handler === null) {
            throw new HandlerException('standup: the channel_push handler is not registered');
        }

        $handler->handle(
            ReactionTarget::make(
                handler: 'channel_push',
                targetId: $intent->subjectId,
                debounceSeconds: 0,
                payload: $intent->toArray(),
            ),
            $agent,
        );
    }

    /**
     * @return list<SeatSnapshot>
     */
    private function seats(): array
    {
        $configDir = (string) config('bridge.config_dir');
        $lastDeliveries = $this->lastDeliveryByAgent();

        $seats = [];
        foreach ((new SubscriptionRegistry($configDir))->agentConfigs() as $cfg) {
            $seats[] = new SeatSnapshot(
                agent: $cfg->agentName,
                lastDeliveryAt: $lastDeliveries[$cfg->agentName] ?? null,
                unseenInboxIntents: count(BridgePaths::unseenInboxLines($cfg->agentName)),
            );
        }

        usort($seats, fn (SeatSnapshot $a, SeatSnapshot $b): int => strcmp($a->agent, $b->agent));

        return $seats;
    }

    /**
     * Agent name => ISO-8601 time of its most recent DELIVERED dispatch.
     *
     * ⛔ `outcome = 'delivered'` is the filter, not `processed_at is not null`. A
     * GATE-DROP is also "processed" — the event reached the agent's classifier and
     * yielded nothing — and until DL-036 the two were byte-identical in this ledger.
     * Counting a drop as a delivery would make a seat nobody is routing anything to look
     * freshly served, which is the mislabelling the whole digest is scoped to avoid. The
     * cost is that pre-DL-036 rows (NULL `outcome`) never answer, and an absent key is
     * the right answer for a row whose outcome is genuinely unknowable.
     *
     * @return array<string, string>
     */
    private function lastDeliveryByAgent(): array
    {
        $rows = AgentDispatch::query()
            ->where('outcome', AgentDispatch::OUTCOME_DELIVERED)
            ->whereNotNull('processed_at')
            ->selectRaw('agent_name, max(processed_at) as last_delivery')
            ->groupBy('agent_name')
            ->pluck('last_delivery', 'agent_name');

        $out = [];
        foreach ($rows as $agent => $raw) {
            // An aggregate bypasses the model's datetime cast and the drivers do not agree
            // on the string it hands back (SQLite keeps the fractional seconds MariaDB's
            // DATETIME(3) renders differently), so it is parsed rather than passed through
            // — the digest states ONE format regardless of which database produced it.
            if (is_string($raw) && $raw !== '') {
                $out[(string) $agent] = Carbon::parse($raw)->toIso8601String();
            }
        }

        return $out;
    }

    /**
     * @return list<BoardSnapshot>
     */
    private function boards(): array
    {
        // A MALFORMED writeback.json propagates and fails the whole pass — deliberately,
        // and note that its neighbour below is caught. The difference is what each failure
        // is evidence of: an unresolvable token is a remote/environment fault the digest
        // can report per board and still be true, while a config file the bridge refuses to
        // parse means it does not know which boards exist. Degrading THAT to `boards: []`
        // would print "this install has no Now lanes" over a broken config — the invented
        // fact this whole class exists to refuse. The gate records it and backs off.
        $writeback = WritebackConfig::loadDefault();
        if ($writeback === null) {
            return [];
        }

        // Board id => the stage id its mapping declares as the Now lane. A board with no
        // `coord_card_lane_stage_ids` never enters this map and so never produces a row:
        // the bridge has no Now-lane model for it, and a `now_depth: 0` there would be a
        // number about a column the bridge cannot even identify. Several repo mappings can
        // name one board, hence the dedup.
        $nowStages = [];
        foreach ($writeback->mappings as $mapping) {
            $now = $mapping->coordCardLaneStageIds['now'] ?? null;
            if (is_int($now)) {
                $nowStages[$mapping->boardId] = $now;
            }
        }
        if ($nowStages === []) {
            return [];
        }
        ksort($nowStages);

        try {
            $client = WritebackClientFactory::make();
        } catch (Throwable $e) {
            // Not fatal to the pass: the seat half of the digest is unaffected, and every
            // board says WHY it has no number instead of the digest silently shipping
            // without a boards section.
            return array_map(
                fn (int $boardId): BoardSnapshot => BoardSnapshot::unavailable($boardId, 'no usable kanban writeback client: '.$e->getMessage()),
                array_keys($nowStages),
            );
        }

        $boards = [];
        foreach ($nowStages as $boardId => $nowStageId) {
            $boards[] = $this->boardSnapshot($client, (int) $boardId, $nowStageId);
        }

        return $boards;
    }

    /**
     * ⛔ THE DEPTH IS COUNTED FROM EACH ROW'S OWN `workflow_stage_id`, over a
     * BOARD-scoped read — not from a `q=... workflow_stage_id=<s>` search, which would be
     * one request instead of a page walk. That q-term is a QueryParser token an
     * un-upgraded kanban does not recognise, and an unrecognised token is routed to FREE
     * TEXT: the answer comes back `200` and EMPTY, indistinguishable from a Now lane that
     * is genuinely empty. `now_depth: 0` is the single most consequential value in this
     * digest to get wrong — it is the one that says "this seat has nothing queued" — so
     * it is decided on a fact each row asserts about itself rather than on a filter the
     * server may have silently dropped (`board_id=` is measured honoured; see
     * `KanbanClient`).
     *
     * A TRUNCATED read yields no number at all. The page walk stops at the safety ceiling,
     * so the count past it is a lower bound, and a lower bound printed as a depth is a
     * wrong number rather than a missing one.
     */
    private function boardSnapshot(KanbanClient $client, int $boardId, int $nowStageId): BoardSnapshot
    {
        try {
            $read = $client->readBoardCards($boardId);
        } catch (Throwable $e) {
            return BoardSnapshot::unavailable($boardId, 'board read failed: '.$e->getMessage());
        }

        if ($read['truncated']) {
            return BoardSnapshot::unavailable(
                $boardId,
                'the board read hit the '.KanbanClient::MAX_PAGES.'-page ceiling, so a Now-lane count would be a lower bound rather than a depth',
            );
        }

        $depth = 0;
        foreach ($read['cards'] as $card) {
            $stage = $card['workflow_stage_id'] ?? null;
            if (is_numeric($stage) && (int) $stage === $nowStageId) {
                $depth++;
            }
        }

        return BoardSnapshot::depth($boardId, $depth);
    }
}
