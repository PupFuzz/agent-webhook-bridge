<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\HandlerRegistry;

/**
 * Resolves a tool name to a {@see Tool} instance (DL-217) — the deliberate
 * sibling of {@see HandlerRegistry}'s register/resolve shape.
 * Ships the two v1 tools (board_my_cards, board_create_card) always-on: they are
 * INERT without a per-agent `board_tools` block + a resolved bearer (the route is
 * loopback-gated and the controller refuses an unresolved token), so there is no
 * opt-in gate here — an install with no board_tools config simply never reaches a
 * tool. Operators register additional tools against the container singleton.
 */
final class BoardToolsRegistry
{
    /**
     * @var array<string, Tool>
     */
    private array $tools;

    public function __construct()
    {
        $this->tools = [];
        foreach ([new BoardMyCardsTool, new BoardCreateCardTool] as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function resolve(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function known(): array
    {
        $names = array_keys($this->tools);
        sort($names);

        return $names;
    }
}
