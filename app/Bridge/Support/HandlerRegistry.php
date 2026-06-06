<?php

namespace App\Bridge\Support;

use App\Bridge\Contracts\Handler;
use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Handlers\LogIntentHandler;
use App\Bridge\Handlers\RegistryAppendHandler;
use App\Bridge\Handlers\SpawnDetachedHandler;

/**
 * Resolves a ReactionTarget's handler name to a Handler instance. Ships four
 * always-on defaults (log_intent, registry_append, channel_push,
 * kanban_move_card); the highest-blast-radius spawn_detached is opt-in (DL-011)
 * — registered only when $spawnDetachedEnabled (wired from
 * config('bridge.spawn.enabled') by BridgeServiceProvider). Operators register
 * additional handlers (e.g. from a service provider). When spawn_detached is not
 * registered, a classifier emitting it resolves to null → the dispatcher records
 * a best-effort note, not an execution.
 *
 * kanban_move_card (the writeback, DL-020) is always-on because it is INERT
 * without `writeback.json` + a writeback token (it no-ops, unlike spawn_detached
 * which would execute), and the classifier only emits it for configured repos.
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
            'kanban_dependabot_card' => new KanbanDependabotCardHandler,
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
