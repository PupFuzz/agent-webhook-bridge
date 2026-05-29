<?php

namespace App\Bridge\Support;

use App\Bridge\Contracts\Handler;
use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Handlers\LogIntentHandler;
use App\Bridge\Handlers\RegistryAppendHandler;
use App\Bridge\Handlers\SpawnDetachedHandler;

/**
 * Resolves a ReactionTarget's handler name to a Handler instance. Ships the
 * four defaults (log_intent, registry_append, spawn_detached, channel_push);
 * operators register additional handlers (e.g. from a service provider).
 */
final class HandlerRegistry
{
    /**
     * @var array<string, Handler>
     */
    private array $handlers;

    public function __construct()
    {
        $this->handlers = [
            'log_intent' => new LogIntentHandler,
            'registry_append' => new RegistryAppendHandler,
            'spawn_detached' => new SpawnDetachedHandler,
            'channel_push' => new ChannelPushHandler,
        ];
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
