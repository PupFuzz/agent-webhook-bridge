<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\HandlerRegistry;

/**
 * Resolves a tool name to a {@see Tool} instance (DL-217) — the deliberate
 * sibling of {@see HandlerRegistry}'s register/resolve shape.
 * Ships the three tools (board_my_cards, board_create_card, board_correct_card)
 * always-on: they are INERT without a per-agent `board_tools` block, so there is
 * no opt-in gate here —
 * an install with no board_tools config simply never reaches a tool. EVERY front
 * door enforces that before dispatch, each on evidence of its own; which doors
 * exist and what each refuses on is {@see BoardToolDispatcher}'s to state, and is
 * deliberately not repeated here — the sentence this replaced named one door's
 * guards and silently stopped being the whole answer when a second was added.
 * Operators register additional tools against the container singleton.
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
        foreach ([new BoardMyCardsTool, new BoardCreateCardTool, new BoardCorrectCardTool] as $tool) {
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
