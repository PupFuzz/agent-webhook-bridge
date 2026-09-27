<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\HandlerRegistry;

/**
 * Resolves a tool name to a {@see Tool} instance (DL-217) — the deliberate
 * sibling of {@see HandlerRegistry}'s register/resolve shape.
 * The CONSTRUCTOR BELOW IS THE SHIPPED SET — it is not restated in prose here, because a
 * counted or listed copy goes stale on the day the next tool lands and reads as complete
 * until somebody notices; `ChannelServerToolSurfaceRestatementTest` holds the reference
 * channel server AND `docs/board-tools.md`'s tool table against {@see known}. Every shipped
 * tool is always-on: they are INERT without a per-agent `board_tools` block, so there is
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
     * The shipped set, by CLASS. Every one is `final`, so an operator tool can take a shipped
     * tool's NAME through {@see register} but never its class — which is what lets
     * {@see isShipped} answer for the instance rather than the name (DL-426).
     *
     * @var list<class-string<Tool>>
     */
    private const SHIPPED = [BoardMyCardsTool::class, BoardCreateCardTool::class, BoardCorrectCardTool::class, BoardTakeCardTool::class, BoardCommentCardTool::class];

    /**
     * @var array<string, Tool>
     */
    private array $tools;

    public function __construct()
    {
        $this->tools = [];
        foreach (self::SHIPPED as $class) {
            $tool = new $class;
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** Whether `$tool` IS one of the shipped tools — not merely registered under one's name. */
    public static function isShipped(Tool $tool): bool
    {
        return in_array($tool::class, self::SHIPPED, true);
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
