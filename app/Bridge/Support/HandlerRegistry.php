<?php

namespace App\Bridge\Support;

use App\Bridge\Contracts\Handler;
use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Handlers\KanbanBlockReasonHandler;
use App\Bridge\Handlers\KanbanCoordCardHandler;
use App\Bridge\Handlers\KanbanCoordCardMoveHandler;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Handlers\KanbanPromoteReleasedHandler;
use App\Bridge\Handlers\LogIntentHandler;
use App\Bridge\Handlers\RegistryAppendHandler;
use App\Bridge\Handlers\SpawnDetachedHandler;

/**
 * Resolves a ReactionTarget's handler name to a Handler instance. Ships nine
 * always-on defaults (log_intent, registry_append, channel_push, kanban_move_card,
 * kanban_promote_released, kanban_dependabot_card, kanban_block_reason,
 * kanban_coord_card, kanban_coord_card_move); the
 * highest-blast-radius spawn_detached is opt-in (DL-011)
 * — registered only when $spawnDetachedEnabled (wired from
 * config('bridge.spawn.enabled') by BridgeServiceProvider). Operators register
 * additional handlers (e.g. from a service provider). When spawn_detached is not
 * registered, a classifier emitting it resolves to null → the dispatcher records
 * a best-effort note, not an execution.
 *
 * The kanban writeback handlers (kanban_move_card DL-020, kanban_promote_released
 * DL-207, kanban_dependabot_card DL-024, kanban_block_reason DL-193, kanban_coord_card
 * DL-198, kanban_coord_card_move DL-200) are always-on
 * because they are INERT without `writeback.json` + a writeback token (they no-op,
 * unlike spawn_detached which would execute), and the classifier only emits them
 * for configured repos/opt-ins.
 */
final class HandlerRegistry
{
    /**
     * @var array<string, Handler>
     */
    private array $handlers;

    public function __construct(bool $spawnDetachedEnabled = false)
    {
        $this->handlers = [
            'log_intent' => new LogIntentHandler,
            'registry_append' => new RegistryAppendHandler,
            'channel_push' => new ChannelPushHandler,
            'kanban_move_card' => new KanbanMoveCardHandler,
            'kanban_promote_released' => new KanbanPromoteReleasedHandler,
            'kanban_dependabot_card' => new KanbanDependabotCardHandler,
            'kanban_block_reason' => new KanbanBlockReasonHandler,
            'kanban_coord_card' => new KanbanCoordCardHandler,
            'kanban_coord_card_move' => new KanbanCoordCardMoveHandler,
        ];
        if ($spawnDetachedEnabled) {
            $this->handlers['spawn_detached'] = new SpawnDetachedHandler;
        }
    }

    public function register(string $name, Handler $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    public function resolve(string $name): ?Handler
    {
        return $this->handlers[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function known(): array
    {
        $names = array_keys($this->handlers);
        sort($names);

        return $names;
    }
}
