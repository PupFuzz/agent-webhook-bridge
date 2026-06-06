<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Handlers\LogIntentHandler;
use App\Bridge\Handlers\RegistryAppendHandler;
use App\Bridge\Handlers\SpawnDetachedHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use PHPUnit\Framework\TestCase;

class HandlerRegistryTest extends TestCase
{
    public function test_ships_four_always_on_defaults(): void
    {
        $registry = new HandlerRegistry;

        $this->assertInstanceOf(LogIntentHandler::class, $registry->resolve('log_intent'));
        $this->assertInstanceOf(RegistryAppendHandler::class, $registry->resolve('registry_append'));
        $this->assertInstanceOf(ChannelPushHandler::class, $registry->resolve('channel_push'));
        $this->assertInstanceOf(KanbanMoveCardHandler::class, $registry->resolve('kanban_move_card'));
    }

    public function test_spawn_detached_is_opt_in(): void
    {
        // Off by default (DL-011): a classifier emitting spawn_detached resolves
        // to null → best-effort note, not execution.
        $this->assertNull((new HandlerRegistry)->resolve('spawn_detached'));
        $this->assertNull((new HandlerRegistry(false))->resolve('spawn_detached'));

        // Registered only when explicitly enabled.
        $this->assertInstanceOf(SpawnDetachedHandler::class, (new HandlerRegistry(true))->resolve('spawn_detached'));
    }

    public function test_known_is_sorted(): void
    {
        $this->assertSame(
            ['channel_push', 'kanban_dependabot_card', 'kanban_move_card', 'log_intent', 'registry_append'],
            (new HandlerRegistry)->known(),
        );
        $this->assertSame(
            ['channel_push', 'kanban_dependabot_card', 'kanban_move_card', 'log_intent', 'registry_append', 'spawn_detached'],
            (new HandlerRegistry(true))->known(),
        );
    }

    public function test_unknown_handler_resolves_to_null(): void
    {
        $this->assertNull((new HandlerRegistry)->resolve('nope'));
    }

    public function test_register_adds_a_custom_handler(): void
    {
        $registry = new HandlerRegistry;
        $custom = new class implements Handler
        {
            public function handle(ReactionTarget $target, AgentConfig $agent): void {}
        };
        $registry->register('my_handler', $custom);

        $this->assertSame($custom, $registry->resolve('my_handler'));
    }
}
