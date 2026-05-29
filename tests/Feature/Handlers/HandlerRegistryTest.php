<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Handlers\LogIntentHandler;
use App\Bridge\Handlers\RegistryAppendHandler;
use App\Bridge\Handlers\SpawnDetachedHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use PHPUnit\Framework\TestCase;

class HandlerRegistryTest extends TestCase
{
    public function test_ships_the_four_defaults(): void
    {
        $registry = new HandlerRegistry;

        $this->assertInstanceOf(LogIntentHandler::class, $registry->resolve('log_intent'));
        $this->assertInstanceOf(RegistryAppendHandler::class, $registry->resolve('registry_append'));
        $this->assertInstanceOf(SpawnDetachedHandler::class, $registry->resolve('spawn_detached'));
        $this->assertInstanceOf(ChannelPushHandler::class, $registry->resolve('channel_push'));
    }

    public function test_known_is_sorted(): void
    {
        $this->assertSame(
            ['channel_push', 'log_intent', 'registry_append', 'spawn_detached'],
            (new HandlerRegistry)->known(),
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
